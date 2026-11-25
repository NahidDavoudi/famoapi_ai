<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

use App\Core\PhoneNumber;
use App\Core\SmsService;

/**
 * SMS delivery channel for admin 2FA.
 *
 * - Skips immediately without waiting for timeout when ADMIN_SMS_ENABLED=false.
 * - Skips immediately when SmsCircuitBreaker is open (3 consecutive failures -> 10m open).
 * - Enforces a hard 4-second timeout on the SMS provider call.
 * - Never leaks OTP codes, API keys, or SMS passwords in error messages.
 */
final class SmsChannel implements TwoFactorChannelInterface
{
    public const HARD_TIMEOUT_SECONDS = 4;

    private SmsService $sms;

    public function __construct(?SmsService $sms = null)
    {
        $this->sms = $sms ?? new SmsService();
    }

    public function name(): string
    {
        return 'sms';
    }

    public static function isEnabled(): bool
    {
        $raw = $_ENV['ADMIN_SMS_ENABLED'] ?? $_SERVER['ADMIN_SMS_ENABLED'] ?? getenv('ADMIN_SMS_ENABLED');
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return true;
        }
        $normalized = strtolower(trim((string) $raw));
        return !in_array($normalized, ['false', '0', 'no', 'off'], true);
    }

    public function isAvailableFor(array $user): bool
    {
        return $this->resolveMobile($user) !== null;
    }

    public function send(array $user, string $otpCode): ChannelDeliveryResult
    {
        if (!self::isEnabled()) {
            return ChannelDeliveryResult::failed(
                'sms',
                'SmsDisabled',
                'ADMIN_SMS_ENABLED is false; SMS skipped',
                true
            );
        }

        if (SmsCircuitBreaker::isOpen()) {
            $rem = SmsCircuitBreaker::remainingSeconds();
            return ChannelDeliveryResult::failed(
                'sms',
                'CircuitBreakerOpen',
                "SMS circuit breaker open ({$rem}s remaining); SMS skipped",
                true
            );
        }

        $mobile = $this->resolveMobile($user);
        if ($mobile === null) {
            return ChannelDeliveryResult::failed(
                'sms',
                'MissingMobileNumber',
                'Admin account has no valid mobile number',
                true
            );
        }

        try {
            $this->sms->setRequestTimeoutOverride(self::HARD_TIMEOUT_SECONDS);
            $sent = $this->sms->sendVerificationCode($mobile, $otpCode);
        } catch (\Throwable $e) {
            SmsCircuitBreaker::recordFailure();
            return ChannelDeliveryResult::failed(
                'sms',
                get_class($e),
                self::sanitizeMessage($e->getMessage(), $otpCode)
            );
        } finally {
            $this->sms->setRequestTimeoutOverride(null);
        }

        if (!$sent) {
            SmsCircuitBreaker::recordFailure();
            $reason = $this->sms->getLastError() ?: 'SMS provider returned non-success response';
            return ChannelDeliveryResult::failed(
                'sms',
                'SmsDeliveryFailed',
                self::sanitizeMessage($reason, $otpCode)
            );
        }

        SmsCircuitBreaker::recordSuccess();
        return ChannelDeliveryResult::ok('sms', PhoneNumber::mask($mobile));
    }

    private function resolveMobile(array $user): ?string
    {
        $raw = !empty($user['phone']) ? (string) $user['phone'] : (string) ($user['username'] ?? '');
        $normalized = PhoneNumber::normalize($raw);
        if ($normalized === null || $normalized === '09000000000') {
            return null;
        }
        return $normalized;
    }

    private static function sanitizeMessage(string $message, string $otpCode): string
    {
        $secrets = [
            $otpCode,
            $_ENV['SMS_API_KEY'] ?? getenv('SMS_API_KEY') ?: '',
            $_ENV['KAVENEGAR_API_KEY'] ?? getenv('KAVENEGAR_API_KEY') ?: '',
            $_ENV['MELIPAYAMAK_PASSWORD'] ?? getenv('MELIPAYAMAK_PASSWORD') ?: '',
        ];

        $clean = $message;
        foreach ($secrets as $secret) {
            $s = trim((string) $secret);
            if ($s !== '' && str_contains($clean, $s)) {
                $clean = str_replace($s, '[REDACTED]', $clean);
            }
        }

        return mb_substr(trim($clean), 0, 240);
    }
}
