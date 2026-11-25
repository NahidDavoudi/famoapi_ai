<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\User;
use PDO;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FA\Support\Constants as Google2FAConstants;

/**
 * RFC 6238 TOTP (SHA1, 6 digits, 30s period, ±1 step window) and one-time
 * recovery-code service for admin panel 2FA.
 *
 * Security guarantees:
 * - TOTP crypto delegated entirely to `pragmarx/google2fa`.
 * - Secrets are encrypted at rest via `SecretBox` (AES-256-GCM) with per-user AAD.
 * - Replay protection rejects any matched time-step <= `totp_last_used_step`
 *   and atomically advances `totp_last_used_step` in SQL.
 * - Recovery codes are stored ONLY as salted `password_hash()` digests and
 *   verified across all hashes without early short-circuiting, then immediately
 *   removed inside a database transaction on first use.
 * - Never logs secrets, TOTP codes, or recovery codes.
 */
final class TotpService
{
    public const METHOD_TOTP = 'totp';
    public const METHOD_SMS = 'sms';
    public const VALID_METHODS = [self::METHOD_TOTP, self::METHOD_SMS];

    public const DEFAULT_WINDOW = 1;
    public const PERIOD_SECONDS = 30;
    public const CODE_DIGITS = 6;
    public const SECRET_LENGTH = 32; // 32 Base32 chars = 160 bits of entropy
    public const RECOVERY_CODE_COUNT = 8;

    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const GENERIC_2FA_ERROR = 'کد تأیید نامعتبر یا منقضی شده است';

    private Google2FA $google2fa;

    public function __construct(?Google2FA $google2fa = null)
    {
        if ($google2fa !== null) {
            $this->google2fa = $google2fa;
        } else {
            $g = new Google2FA();
            $g->setAlgorithm(Google2FAConstants::SHA1);
            $g->setOneTimePasswordLength(self::CODE_DIGITS);
            $g->setKeyRegeneration(self::PERIOD_SECONDS);
            $g->setWindow(self::DEFAULT_WINDOW);
            $this->google2fa = $g;
        }
    }

    /**
     * Resolve configured admin 2FA method (`totp` or `sms`). Defaults to `totp`.
     */
    public static function admin2faMethod(): string
    {
        $raw = $_ENV['ADMIN_2FA_METHOD'] ?? $_SERVER['ADMIN_2FA_METHOD'] ?? getenv('ADMIN_2FA_METHOD');
        if (!is_string($raw) || trim($raw) === '') {
            return self::METHOD_TOTP;
        }

        $normalized = strtolower(trim($raw));
        return in_array($normalized, self::VALID_METHODS, true) ? $normalized : self::METHOD_TOTP;
    }

    public static function issuer(): string
    {
        $raw = $_ENV['TOTP_ISSUER'] ?? $_SERVER['TOTP_ISSUER'] ?? getenv('TOTP_ISSUER');
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        return 'FAMO Academy';
    }

    public static function isEnrolled(array $user): bool
    {
        return (int) ($user['totp_enabled'] ?? 0) === 1
            && isset($user['totp_secret'])
            && is_string($user['totp_secret'])
            && trim($user['totp_secret']) !== '';
    }

    /**
     * Generate a new TOTP secret and 8 one-time recovery codes, encrypt the secret
     * and hash the recovery codes before persisting, and revoke prior active sessions.
     *
     * Plaintext secret and plaintext recovery codes are returned ONCE for CLI display
     * and are never persisted or logged.
     *
     * @return array{
     *     user_id: int,
     *     username: string,
     *     issuer: string,
     *     secret: string,
     *     otpauth_uri: string,
     *     recovery_codes: array<int, string>
     * }
     */
    public function enrollAdmin(int $userId, ?string $issuer = null): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }
        if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
            throw new ApiException('تنظیم TOTP فقط برای حساب‌های مدیر مجاز است', 422, 'VALIDATION_ERROR');
        }

        $issuerName = ($issuer !== null && trim($issuer) !== '') ? trim($issuer) : self::issuer();
        $secret = $this->google2fa->generateSecretKey(self::SECRET_LENGTH);
        $encryptedSecret = SecretBox::encrypt($secret, self::aadForUser($userId));

        $plainRecoveryCodes = $this->generateRecoveryCodes(self::RECOVERY_CODE_COUNT);
        $hashedRecoveryCodes = array_map(
            static fn (string $code): string => password_hash(self::canonicalizeRecoveryCode($code), PASSWORD_DEFAULT),
            $plainRecoveryCodes
        );

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE users
             SET totp_secret = :secret,
                 totp_enabled = 1,
                 totp_last_used_step = NULL,
                 recovery_codes = :recovery_codes
             WHERE id = :id AND role = :role'
        );
        $stmt->execute([
            'secret'         => $encryptedSecret,
            'recovery_codes' => json_encode($hashedRecoveryCodes, JSON_UNESCAPED_SLASHES),
            'id'             => $userId,
            'role'           => 'admin',
        ]);

        // Clear any pending login challenges and revoke prior sessions
        $this->invalidateSessionsAndChallenges($userId);

        $holder = (string) ($user['username'] ?: ('admin-' . $userId));
        $otpauthUri = $this->buildOtpAuthUri($issuerName, $holder, $secret);

        return [
            'user_id'        => $userId,
            'username'       => (string) $user['username'],
            'issuer'         => $issuerName,
            'secret'         => $secret,
            'otpauth_uri'    => $otpauthUri,
            'recovery_codes' => $plainRecoveryCodes,
        ];
    }

    /**
     * Disable and wipe TOTP secret, last-used step, and recovery codes for an admin.
     */
    public function resetAdmin(int $userId): void
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }
        if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
            throw new ApiException('عملیات فقط برای حساب‌های مدیر مجاز است', 422, 'VALIDATION_ERROR');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE users
             SET totp_secret = NULL,
                 totp_enabled = 0,
                 totp_last_used_step = NULL,
                 recovery_codes = NULL
             WHERE id = :id AND role = :role'
        );
        $stmt->execute([
            'id'   => $userId,
            'role' => 'admin',
        ]);

        $this->invalidateSessionsAndChallenges($userId);
    }

    /**
     * Safe status summary (never exposes secret or recovery-code hashes).
     *
     * @return array{user_id: int, username: string, role: string, totp_enabled: bool, has_secret: bool, remaining_recovery_codes: int, has_used_step: bool}
     */
    public function statusForUser(int $userId): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }

        $hashes = $this->decodeRecoveryHashes($user['recovery_codes'] ?? null);

        return [
            'user_id'                  => (int) $user['id'],
            'username'                 => (string) ($user['username'] ?? ''),
            'role'                     => (string) ($user['role'] ?? ''),
            'totp_enabled'             => (int) ($user['totp_enabled'] ?? 0) === 1,
            'has_secret'               => isset($user['totp_secret']) && is_string($user['totp_secret']) && trim($user['totp_secret']) !== '',
            'remaining_recovery_codes' => count($hashes),
            'has_used_step'            => isset($user['totp_last_used_step']) && $user['totp_last_used_step'] !== null,
        ];
    }

    /**
     * Verify a 6-digit TOTP code (with ±1 step drift and atomic replay protection)
     * or a one-time recovery code (immediately invalidated on match).
     *
     * Always throws a generic 401 `2FA_ERROR` on any failure so callers cannot
     * distinguish which check or factor failed.
     *
     * @return array{method: string, matched_step?: int, remaining_recovery_codes?: int}
     */
    public function verifyAdminCode(array $user, string $rawCode, ?int $timestamp = null): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || !self::isEnrolled($user)) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $normalized = self::normalizeDigits(trim($rawCode));
        $compact = preg_replace('/[\s\-]+/', '', $normalized) ?? '';

        if ($compact === '') {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        // Branch 1: 6-digit numeric TOTP code
        if (preg_match('/^\d{6}$/', $compact) === 1) {
            $step = $this->verifyTotpCode($user, $compact, $timestamp);
            return [
                'method'       => 'totp',
                'matched_step' => $step,
            ];
        }

        // Branch 2: One-time recovery code (e.g. XXXXX-XXXXX)
        $remaining = $this->consumeRecoveryCode($userId, $compact);
        return [
            'method'                   => 'recovery',
            'remaining_recovery_codes' => $remaining,
        ];
    }

    /**
     * Render a compact UTF-8 half-block QR code for terminal display when
     * `chillerlan/php-qrcode` is installed. Returns null if unavailable.
     */
    public static function renderTerminalQr(string $otpauthUri): ?string
    {
        if (!class_exists(\chillerlan\QRCode\QRCode::class) || !class_exists(\chillerlan\QRCode\QROptions::class)) {
            return null;
        }

        try {
            $options = new \chillerlan\QRCode\QROptions([
                'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
                'addQuietzone' => true,
                'quietzoneSize'=> 2,
            ]);
            $qr = new \chillerlan\QRCode\QRCode($options);
            $matrix = $qr->getQRMatrix($otpauthUri);
            $size = $matrix->getSize();

            $lines = [];
            for ($y = 0; $y < $size; $y += 2) {
                $line = '';
                for ($x = 0; $x < $size; $x++) {
                    $topDark = $matrix->check($x, $y);
                    $bottomDark = ($y + 1 < $size) ? $matrix->check($x, $y + 1) : false;

                    // White background / dark modules using inverted contrast suitable for dark terminals
                    if ($topDark && $bottomDark) {
                        $line .= ' ';
                    } elseif ($topDark && !$bottomDark) {
                        $line .= '▄';
                    } elseif (!$topDark && $bottomDark) {
                        $line .= '▀';
                    } else {
                        $line .= '█';
                    }
                }
                $lines[] = $line;
            }

            return implode(PHP_EOL, $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve an admin user by numeric ID, exact username, or normalized phone number.
     */
    public static function findAdminByIdentifier(string $identifier): ?array
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }

        $db = Database::getConnection();

        if (ctype_digit($trimmed) && (int) $trimmed > 0) {
            $stmt = $db->prepare('SELECT * FROM users WHERE id = :id AND role = :role LIMIT 1');
            $stmt->execute(['id' => (int) $trimmed, 'role' => 'admin']);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        $normalizedPhone = PhoneNumber::normalize($trimmed) ?? $trimmed;
        $stmt = $db->prepare(
            'SELECT * FROM users
             WHERE role = :role AND (username = :raw_u OR username = :norm_u OR phone = :norm_p)
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'role'   => 'admin',
            'raw_u'  => $trimmed,
            'norm_u' => $normalizedPhone,
            'norm_p' => $normalizedPhone,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function verifyTotpCode(array $user, string $code, ?int $timestamp): int
    {
        $userId = (int) $user['id'];

        try {
            $plainSecret = SecretBox::decrypt((string) $user['totp_secret'], self::aadForUser($userId));
        } catch (\Throwable) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $lastUsedStep = isset($user['totp_last_used_step']) && $user['totp_last_used_step'] !== null
            ? (int) $user['totp_last_used_step']
            : null;

        try {
            $matchedStep = $this->google2fa->verifyKeyNewer(
                $plainSecret,
                $code,
                $lastUsedStep,
                self::DEFAULT_WINDOW,
                $timestamp
            );
        } catch (\Throwable) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        if ($matchedStep === false || !is_int($matchedStep)) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        if ($lastUsedStep !== null && $matchedStep <= $lastUsedStep) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        // Atomic conditional update prevents concurrent replay of the exact same time-step
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE users
             SET totp_last_used_step = :step
             WHERE id = :id
               AND totp_enabled = 1
               AND (totp_last_used_step IS NULL OR totp_last_used_step < :step_check)'
        );
        $stmt->execute([
            'step'       => $matchedStep,
            'step_check' => $matchedStep,
            'id'         => $userId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        return $matchedStep;
    }

    private function consumeRecoveryCode(int $userId, string $compactInput): int
    {
        $canonical = self::canonicalizeRecoveryCode($compactInput);
        if (preg_match('/^[A-Z0-9]{8,20}$/', $canonical) !== 1) {
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }

        $db = Database::getConnection();
        $driver = strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME));
        $startedTx = !$db->inTransaction();
        if ($startedTx) {
            $db->beginTransaction();
        }

        try {
            $selectSql = $driver === 'sqlite'
                ? 'SELECT recovery_codes FROM users WHERE id = :id AND totp_enabled = 1 LIMIT 1'
                : 'SELECT recovery_codes FROM users WHERE id = :id AND totp_enabled = 1 LIMIT 1 FOR UPDATE';

            $stmt = $db->prepare($selectSql);
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
            }

            $hashes = $this->decodeRecoveryHashes($row['recovery_codes'] ?? null);
            if ($hashes === []) {
                throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
            }

            // Evaluate every stored hash without breaking early to avoid index-position timing leaks.
            $matchedIndex = null;
            foreach ($hashes as $idx => $hash) {
                if (is_string($hash) && $hash !== '' && password_verify($canonical, $hash)) {
                    if ($matchedIndex === null) {
                        $matchedIndex = $idx;
                    }
                }
            }

            if ($matchedIndex === null) {
                throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
            }

            array_splice($hashes, $matchedIndex, 1);

            $update = $db->prepare('UPDATE users SET recovery_codes = :codes WHERE id = :id');
            $update->execute([
                'codes' => json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES),
                'id'    => $userId,
            ]);

            if ($startedTx) {
                $db->commit();
            }

            return count($hashes);
        } catch (\Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(self::GENERIC_2FA_ERROR, 401, '2FA_ERROR');
        }
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(int $count): array
    {
        $alphabet = self::RECOVERY_ALPHABET;
        $maxIndex = strlen($alphabet) - 1;
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $part1 = '';
            $part2 = '';
            for ($j = 0; $j < 5; $j++) {
                $part1 .= $alphabet[random_int(0, $maxIndex)];
                $part2 .= $alphabet[random_int(0, $maxIndex)];
            }
            $codes[] = $part1 . '-' . $part2;
        }

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    private function decodeRecoveryHashes(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn ($v): bool => is_string($v) && $v !== ''));
    }

    private function buildOtpAuthUri(string $issuer, string $holder, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl($issuer, $holder, $secret);
        // Ensure explicit RFC 6238 parameters for Microsoft Authenticator / Google Authenticator / Aegis
        $query = parse_url($uri, PHP_URL_QUERY);
        $params = [];
        if (is_string($query)) {
            parse_str($query, $params);
        }

        $label = rawurlencode($issuer) . ':' . rawurlencode($holder);
        $ordered = [
            'secret'    => (string) ($params['secret'] ?? $secret),
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => (string) self::CODE_DIGITS,
            'period'    => (string) self::PERIOD_SECONDS,
        ];

        return 'otpauth://totp/' . $label . '?' . http_build_query($ordered, '', '&', PHP_QUERY_RFC3986);
    }

    private function invalidateSessionsAndChallenges(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare('UPDATE login_codes SET used = 1 WHERE user_id = ? AND used = 0')
               ->execute([$userId]);
        } catch (\Throwable) {
        }

        try {
            if (RedisClient::isAvailable()) {
                RedisClient::del('2fa:totp_challenge:' . $userId);
                JwtRevocation::revokeAllForUser($userId);
            }
        } catch (\Throwable) {
        }
    }

    private static function aadForUser(int $userId): string
    {
        return 'user:' . $userId . ':totp_secret';
    }

    private static function canonicalizeRecoveryCode(string $code): string
    {
        $normalized = self::normalizeDigits(trim($code));
        $stripped = preg_replace('/[\s\-]+/', '', $normalized) ?? '';
        return strtoupper($stripped);
    }

    private static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
