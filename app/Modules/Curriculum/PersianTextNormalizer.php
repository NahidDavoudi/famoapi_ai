<?php

declare(strict_types=1);

namespace App\Modules\Curriculum;

/**
 * Pure Persian/Arabic string normalizer for deterministic matching between
 * Lernito question metadata, curriculum_nodes labels (which may contain NBSP/ZWNJ),
 * and legacy exam_results entries.
 */
final class PersianTextNormalizer
{
    private const CHAR_MAP = [
        // Arabic -> Persian letters
        'ي' => 'ی',
        'ى' => 'ی',
        'ك' => 'ک',
        'ۀ' => 'ه',
        'ة' => 'ه',
        'أ' => 'ا',
        'إ' => 'ا',
        'ٱ' => 'ا',
        'ؤ' => 'و',
        // Persian & Arabic-Indic digits -> ASCII
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Standard readable normalization:
     *   - Arabic letters/digits -> Persian letters & ASCII digits
     *   - NBSP (\u00A0) and repeated whitespace -> single ASCII space
     *   - Remove Arabic diacritics/tatweel
     */
    public static function normalize(string $text): string
    {
        $s = strtr($text, self::CHAR_MAP);
        // Replace NBSP, narrow NBSP, figure space, thin space with regular space
        $s = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\t\r\n]+/u', ' ', $s) ?? $s;
        // Strip tatweel and Arabic vowel marks
        $s = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $s) ?? $s;
        // Collapse multiple spaces
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Loose key for resilient matching across ZWNJ, spaces, and punctuation differences
     * (e.g. "زیست‌شناسی" == "زیست شناسی" and "فصل  4: هندسه" == "فصل 4: هندسه").
     */
    public static function looseKey(string $text): string
    {
        $s = self::normalize($text);
        // Strip ZWNJ, spaces, punctuation, dashes, colons
        $s = preg_replace('/[\x{200C}\x{200D}\s:：،,\.\-\–\—\(\)\[\]\/\\\\÷×]+/u', '', $s) ?? $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /**
     * Map numeric or Persian grade to canonical Lernito grade label.
     */
    public static function gradeToLabel(int|string|null $grade): ?string
    {
        if ($grade === null) {
            return null;
        }
        $norm = self::normalize((string) $grade);
        return match ($norm) {
            '7', 'هفتم' => 'هفتم',
            '8', 'هشتم' => 'هشتم',
            '9', 'نهم' => 'نهم',
            '10', 'دهم' => 'دهم',
            '11', 'یازدهم' => 'یازدهم',
            '12', 'دوازدهم', 'کنکور' => 'دوازدهم',
            default => null,
        };
    }

    /**
     * Map student.field string to canonical Lernito group label (متوسطه ۲).
     */
    public static function fieldToGroupLabel(?string $field): ?string
    {
        if ($field === null || trim($field) === '') {
            return null;
        }
        $key = self::looseKey($field);
        if (str_contains($key, 'تجربی')) {
            return 'علوم تجربی';
        }
        if (str_contains($key, 'ریاضی') || str_contains($key, 'فیزیک')) {
            return 'ریاضی و فیزیک';
        }
        if (str_contains($key, 'انسانی')) {
            return 'علوم انسانی';
        }
        return null;
    }
}
