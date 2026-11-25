<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Self-contained Iranian mobile number normalizer for the Sms module.
 *
 * Deliberately duplicates a small part of App\Core\PhoneNumber so the module
 * has zero dependencies on Famo domain code and can be extracted as-is.
 * Canonical form: 09XXXXXXXXX (11 ASCII digits starting with 09).
 */
final class SmsPhone
{
    private const PERSIAN_DIGITS = ["\u{06F0}", "\u{06F1}", "\u{06F2}", "\u{06F3}", "\u{06F4}", "\u{06F5}", "\u{06F6}", "\u{06F7}", "\u{06F8}", "\u{06F9}"];
    private const ARABIC_DIGITS = ["\u{0660}", "\u{0661}", "\u{0662}", "\u{0663}", "\u{0664}", "\u{0665}", "\u{0666}", "\u{0667}", "\u{0668}", "\u{0669}"];
    private const ASCII_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public const CANONICAL_PATTERN = '/^09\d{9}$/';

    /**
     * Normalize any accepted Iranian mobile representation to canonical
     * 09XXXXXXXXX, or return null when the input is empty or invalid.
     *
     * Supported inputs (after Persian/Arabic digit conversion and separator
     * cleanup): 09123456789, 9123456789, +989123456789, +9809123456789,
     * 00989123456789, 9809123456789, 989123456789.
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

        $value = str_replace(self::PERSIAN_DIGITS, self::ASCII_DIGITS, $value);
        $value = str_replace(self::ARABIC_DIGITS, self::ASCII_DIGITS, $value);

        // Strip common formatting characters, whitespace, and Unicode
        // directional / zero-width marks.
        $value = str_replace([' ', "\t", "\n", "\r", '-', '_', '.', '(', ')', '/'], '', $value);
        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);

        // Strip leading international '+' or '00' prefix before the country code check.
        if (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '00')) {
            $value = substr($value, 2);
        }

        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        // Handle Iran country code (98).
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

        return preg_match(self::CANONICAL_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Normalize or throw SmsInputException. Called before any DB write or
     * provider call so invalid receptors never reach the provider.
     *
     * @throws SmsInputException
     */
    public static function normalizeOrFail(mixed $raw): string
    {
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            throw new SmsInputException('Invalid Iranian mobile number.');
        }

        return $normalized;
    }
}
