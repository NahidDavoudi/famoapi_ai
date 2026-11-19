<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Helper class to validate, normalize, format, and mask Iranian mobile phone numbers
 * before saving them to or querying them from the database.
 *
 * Canonical database format: 09XXXXXXXXX (11 ASCII digits starting with 09).
 */
final class PhoneNumber
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ARABIC_DIGITS  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    private const ASCII_DIGITS   = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * Pattern for canonical 11-digit Iranian mobile numbers (09XXXXXXXXX).
     */
    public const CANONICAL_PATTERN = '/^09\d{9}$/';

    /**
     * Convert Persian (۰-۹) and Arabic-Indic (٠-٩) numerals to English ASCII digits (0-9).
     */
    public static function toEnglishDigits(string $input): string
    {
        $converted = str_replace(self::PERSIAN_DIGITS, self::ASCII_DIGITS, $input);
        return str_replace(self::ARABIC_DIGITS, self::ASCII_DIGITS, $converted);
    }

    /**
     * Normalize any accepted Iranian mobile representation to canonical 09XXXXXXXXX,
     * or return null when the input is empty or invalid.
     *
     * Supported inputs:
     * - 09123456789
     * - 9123456789
     * - +989123456789 / +9809123456789
     * - 00989123456789 / 009809123456789
     * - 989123456789 / 9809123456789
     * - Strings containing Persian/Arabic digits, spaces, dashes, dots, or parentheses
     */
    public static function normalize(mixed $raw): ?string
    {
        if ($raw === null || is_bool($raw) || is_array($raw) || is_object($raw)) {
            return null;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $value = self::toEnglishDigits($value);

        // Strip common formatting characters, whitespace, and Unicode directional/zero-width marks
        $value = str_replace([' ', "\t", "\n", "\r", '-', '_', '.', '(', ')', '/'], '', $value);
        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);

        // Strip leading international '+' or '00' prefix before country code check
        if (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '00')) {
            $value = substr($value, 2);
        }

        // Reject if any non-digit characters remain after prefix & separator cleanup
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        // Handle Iran country code (98)
        if (str_starts_with($value, '98')) {
            if (strlen($value) === 12 && str_starts_with($value, '989')) {
                // 989123456789 -> 09123456789
                $value = '0' . substr($value, 2);
            } elseif (strlen($value) === 13 && str_starts_with($value, '9809')) {
                // 9809123456789 -> 09123456789
                $value = substr($value, 2);
            }
        } elseif (strlen($value) === 10 && str_starts_with($value, '9')) {
            // 9123456789 -> 09123456789
            $value = '0' . $value;
        }

        return self::isCanonical($value) ? $value : null;
    }

    /**
     * Normalize an Iranian mobile phone number or throw a 422 ApiException when invalid.
     *
     * @throws ApiException
     */
    public static function normalizeOrFail(
        mixed $raw,
        string $message = 'شماره تلفن همراه معتبر نیست',
        string $errorCode = 'VALIDATION_ERROR'
    ): string {
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            throw new ApiException($message, 422, $errorCode);
        }

        return $normalized;
    }

    /**
     * Check whether the given value can be normalized to a valid Iranian mobile number.
     */
    public static function isValid(mixed $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /**
     * Check whether the string is already in canonical 09XXXXXXXXX format.
     */
    public static function isCanonical(string $value): bool
    {
        return preg_match(self::CANONICAL_PATTERN, $value) === 1;
    }

    /**
     * Convert a valid Iranian mobile number to E.164 international format (+989XXXXXXXXX).
     */
    public static function toE164(mixed $raw): ?string
    {
        $canonical = self::normalize($raw);
        if ($canonical === null) {
            return null;
        }

        return '+98' . substr($canonical, 1);
    }

    /**
     * Mask a phone number for safe display in 2FA / public responses (e.g., 0912***89).
     */
    public static function mask(string $phone): string
    {
        $canonical = self::normalize($phone) ?? trim($phone);
        if (strlen($canonical) < 6) {
            return '***';
        }

        return substr($canonical, 0, 4) . '***' . substr($canonical, -2);
    }

    /**
     * Generate common stored representations of a canonical number for indexed lookups.
     *
     * @return string[]
     */
    public static function candidateForms(string $rawOrCanonical): array
    {
        $canonical = self::normalize($rawOrCanonical);
        if ($canonical === null) {
            return [$rawOrCanonical];
        }

        $withoutZero = substr($canonical, 1);

        return array_values(array_unique([
            $canonical,
            $withoutZero,
            '+98' . $withoutZero,
            '0098' . $withoutZero,
            '98' . $withoutZero,
        ]));
    }
}
