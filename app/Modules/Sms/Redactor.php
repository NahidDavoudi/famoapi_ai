<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Privacy / secret-hygiene helpers for the Sms module.
 */
final class Redactor
{
    /**
     * Remove every occurrence of the given secrets from a string.
     *
     * Used so the Kavenegar API key (part of every request URL) can never
     * leak into logs, exception messages, or sms_log.error.
     *
     * @param array<int, string|null> $secrets
     */
    public static function redactSecrets(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $secret = trim((string) $secret);
            if ($secret !== '' && str_contains($text, $secret)) {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
        }

        return $text;
    }

    /**
     * Mask the stored text of OTP messages so verification codes are never
     * persisted in the database. The length is kept for debugging only.
     */
    public static function maskOtpText(string $text): string
    {
        return '[otp-redacted len=' . mb_strlen($text) . ']';
    }

    /**
     * A purpose is treated as OTP when it starts with "otp" (case-insensitive),
     * e.g. "otp", "otp.auth_2fa", "OTP_RESET".
     */
    public static function isOtpPurpose(string $purpose): bool
    {
        return str_starts_with(strtolower($purpose), 'otp');
    }
}
