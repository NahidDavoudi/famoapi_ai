<?php

namespace App\Modules\Bot;

use App\Core\PhoneNumber;

/**
 * Canonical Iranian mobile normalization shared by the Bot modules.
 *
 * Delegates to App\Core\PhoneNumber so all modules use a single source of truth
 * for converting Persian/Arabic digits and +98 / 0098 / 98 / 09 / 9 prefixes
 * into the canonical 09XXXXXXXXX format.
 */
final class PhoneNormalizer
{
    /** Normalize any accepted representation to 09XXXXXXXXX, or null. */
    public static function normalize(?string $raw): ?string
    {
        return PhoneNumber::normalize($raw);
    }

    public static function isCanonical(string $value): bool
    {
        return PhoneNumber::isCanonical($value);
    }

    /**
     * Common stored representations of a canonical number, used for indexed
     * lookups. Results are compared against the normalized value afterwards.
     *
     * @return string[]
     */
    public static function candidateForms(string $canonical): array
    {
        return PhoneNumber::candidateForms($canonical);
    }
}

