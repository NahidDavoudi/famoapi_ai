<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Purpose-bound SMS OTP service backed by Redis.
 *
 * Key layout: `famo:otp:{purpose}:{identifier}` (single prefix applied by RedisClient).
 * Security invariants:
 * - OTP values are NEVER stored or logged in plaintext (salted HMAC-SHA256 only).
 * - Codes are strictly bound to their issuance purpose (`login_2fa` or `password_reset`).
 * - Single-use consumption on match and max 5 verification attempts within a 300s TTL.
 * - Fails CLOSED (503 OTP_SERVICE_UNAVAILABLE) whenever Redis is unavailable or disabled.
 */
final class OtpService
{
    public const PURPOSE_LOGIN_2FA      = 'login_2fa';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const VALID_PURPOSES = [
        self::PURPOSE_LOGIN_2FA,
        self::PURPOSE_PASSWORD_RESET,
    ];

    public const DEFAULT_TTL          = 300;
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public static function key(string $purpose, string $identifier): string
    {
        self::assertValidPurpose($purpose);
        $normalizedId = self::normalizeIdentifier($identifier);
        if ($normalizedId === '') {
            throw new \InvalidArgumentException('OTP identifier cannot be empty.');
        }

        return 'famo:otp:' . $purpose . ':' . $normalizedId;
    }

    public static function normalizeOtpDigits(string $code): string
    {
        return PhoneNumber::toEnglishDigits(trim($code));
    }

    /**
     * Generate a 6-digit OTP bound to `$purpose` and `$identifier`, storing only a salted HMAC hash in Redis.
     *
     * @param array<string, mixed> $metadata
     * @return array{code: string, ttl: int, key: string}
     *
     * @throws ApiException when Redis is unavailable (fails closed)
     */
    public function issue(
        string $purpose,
        string $identifier,
        int $ttl = self::DEFAULT_TTL,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        array $metadata = []
    ): array {
        self::assertValidPurpose($purpose);
        $normalizedId = self::normalizeIdentifier($identifier);
        if ($normalizedId === '') {
            throw new ApiException('شناسه ارسال کد نامعتبر است', 422, 'VALIDATION_ERROR');
        }

        if (!RedisClient::isAvailable()) {
            self::logRedisUnavailable('issue', $purpose);
            throw new ApiException(
                'سرویس کد تأیید موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        $ttl = max(1, $ttl);
        $maxAttempts = max(1, $maxAttempts);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $salt = bin2hex(random_bytes(16));
        $hash = self::computeHash($purpose, $normalizedId, $salt, $code);

        $payload = json_encode([
            'v'            => 1,
            'purpose'      => $purpose,
            'salt'         => $salt,
            'hash'         => $hash,
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'meta'         => $metadata,
        ], JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            throw new ApiException('خطا در آماده‌سازی کد تأیید', 500, 'INTERNAL_ERROR');
        }

        $key = self::key($purpose, $normalizedId);
        $stored = RedisClient::setex($key, $ttl, $payload);
        if (!$stored) {
            self::logRedisUnavailable('issue:setex', $purpose);
            throw new ApiException(
                'سرویس کد تأیید موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        return [
            'code' => $code,
            'ttl'  => $ttl,
            'key'  => $key,
        ];
    }

    /**
     * Verify a purpose-bound OTP. Fails CLOSED when Redis is unavailable.
     * Enforces constant-time comparison, max attempts (deletes on exhaustion), and single-use consumption.
     *
     * @return array<string, mixed> stored metadata on success
     *
     * @throws ApiException
     */
    public function verify(string $purpose, string $identifier, string $code): array
    {
        self::assertValidPurpose($purpose);
        $normalizedId = self::normalizeIdentifier($identifier);
        if ($normalizedId === '') {
            throw new ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        if (!RedisClient::isAvailable()) {
            self::logRedisUnavailable('verify', $purpose);
            throw new ApiException(
                'سرویس تأیید کد موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        $key = self::key($purpose, $normalizedId);
        $raw = RedisClient::get($key);
        if (!RedisClient::isAvailable()) {
            self::logRedisUnavailable('verify:get', $purpose);
            throw new ApiException(
                'سرویس تأیید کد موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        if ($raw === null || $raw === '') {
            throw new ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        $record = json_decode($raw, true);
        if (!is_array($record)) {
            RedisClient::del($key);
            throw new ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        $storedPurpose = (string) ($record['purpose'] ?? '');
        $salt = (string) ($record['salt'] ?? '');
        $storedHash = (string) ($record['hash'] ?? '');
        $attempts = (int) ($record['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($record['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS));

        if (!hash_equals($purpose, $storedPurpose) || $salt === '' || $storedHash === '') {
            RedisClient::del($key);
            throw new ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        if ($attempts >= $maxAttempts) {
            RedisClient::del($key);
            throw new ApiException(
                'تعداد تلاش‌های مجاز برای وارد کردن کد به پایان رسیده است. لطفاً درخواست کد جدید ثبت کنید.',
                429,
                'OTP_MAX_ATTEMPTS'
            );
        }

        $normalizedCode = self::normalizeOtpDigits($code);
        $candidateHash = self::computeHash($purpose, $normalizedId, $salt, $normalizedCode);
        $isValidFormat = preg_match('/^\d{6}$/', $normalizedCode) === 1;
        $hashMatches = hash_equals($storedHash, $candidateHash);

        if (!$isValidFormat || !$hashMatches) {
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= $maxAttempts) {
                RedisClient::del($key);
                throw new ApiException(
                    'تعداد تلاش‌های مجاز برای وارد کردن کد به پایان رسیده است. لطفاً درخواست کد جدید ثبت کنید.',
                    429,
                    'OTP_MAX_ATTEMPTS'
                );
            }

            $remainingTtl = RedisClient::ttl($key);
            if (!RedisClient::isAvailable()) {
                self::logRedisUnavailable('verify:ttl', $purpose);
                throw new ApiException(
                    'سرویس تأیید کد موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                    503,
                    'OTP_SERVICE_UNAVAILABLE'
                );
            }

            if ($remainingTtl <= 0) {
                RedisClient::del($key);
                throw new ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
            }

            $record['attempts'] = $nextAttempts;
            $updatedJson = json_encode($record, JSON_UNESCAPED_UNICODE);
            if (is_string($updatedJson)) {
                $updated = RedisClient::setex($key, $remainingTtl, $updatedJson);
                if (!$updated || !RedisClient::isAvailable()) {
                    self::logRedisUnavailable('verify:update_attempts', $purpose);
                    throw new ApiException(
                        'سرویس تأیید کد موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                        503,
                        'OTP_SERVICE_UNAVAILABLE'
                    );
                }
            }

            throw new ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        $deleted = RedisClient::del($key);
        if (!RedisClient::isAvailable()) {
            self::logRedisUnavailable('verify:consume', $purpose);
            throw new ApiException(
                'سرویس تأیید کد موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        if ($deleted !== 1) {
            throw new ApiException('کد تأیید نامعتبر یا قبلاً استفاده شده است', 401, 'OTP_INVALID');
        }

        return is_array($record['meta'] ?? null) ? $record['meta'] : [];
    }

    public function invalidate(string $purpose, string $identifier): void
    {
        self::assertValidPurpose($purpose);
        $normalizedId = self::normalizeIdentifier($identifier);
        if ($normalizedId === '' || !RedisClient::isAvailable()) {
            return;
        }
        RedisClient::del(self::key($purpose, $normalizedId));
    }

    /**
     * Perform comparable CPU work when no SMS/OTP is actually issued (timing equalization helper).
     */
    public function simulateIssueWork(string $purpose, string $identifier): void
    {
        $dummyCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $dummySalt = bin2hex(random_bytes(16));
        self::computeHash($purpose, self::normalizeIdentifier($identifier), $dummySalt, $dummyCode);
    }

    private static function assertValidPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::VALID_PURPOSES, true)) {
            throw new \InvalidArgumentException('Unsupported OTP purpose: ' . $purpose);
        }
    }

    private static function normalizeIdentifier(string $identifier): string
    {
        return strtolower(trim($identifier));
    }

    private static function computeHash(string $purpose, string $normalizedId, string $salt, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$normalizedId}|{$salt}|{$code}", self::hmacSecret());
    }

    private static function hmacSecret(): string
    {
        $raw = $_ENV['OTP_SECRET']
            ?? getenv('OTP_SECRET')
            ?: ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        $trimmed = is_string($raw) ? trim($raw) : '';
        if ($trimmed === '') {
            // Deterministic process fallback for isolated unit tests without JWT_SECRET configured
            return hash('sha256', 'famo-otp-default-hmac-key');
        }

        return hash_hmac('sha256', 'famo:otp:hmac:v1', $trimmed);
    }

    private static function logRedisUnavailable(string $context, string $purpose): void
    {
        $reason = RedisClient::getLastError()
            ?? (RedisClient::isEnabled() ? 'Redis connection unavailable' : 'Redis disabled');
        $line = sprintf(
            '[%s] [CRITICAL] OtpService::%s failing closed while Redis unavailable (purpose=%s): %s',
            gmdate('Y-m-d H:i:s'),
            $context,
            $purpose,
            $reason
        );
        RedisClient::writeCriticalLog($line);
    }
}
