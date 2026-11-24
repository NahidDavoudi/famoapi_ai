<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Pure SMS segment counter (no I/O, no provider calls).
 *
 * Rules implemented (GSM 03.38 / UDH convention; Kavenegar's own
 * documentation at https://kavenegar.com/rest.html states that multi-part
 * messages reserve 7 characters (Latin) / 3 characters (Unicode) per
 * segment for the UDH header):
 *
 * - GSM-7 compatible text: 160 characters for a single segment, 153 per
 *   segment when the message is multi-part. Characters from the GSM
 *   extension table (^{}\[~]|E) occupy TWO septets each and count as two.
 * - ANY other character (including ZWNJ U+200C, Persian letters, emoji and
 *   most non-Latin punctuation) switches the whole message to UCS-2:
 *   70 characters for a single segment, 67 per segment when multi-part.
 * - Counting is by Unicode CODE POINT (not bytes, not UTF-16 units):
 *   astral-plane emoji count as one character.
 *
 * NOTE: Kavenegar's Persian doc page also mentions "140 characters for a
 * Latin SMS", which contradicts the GSM-7 standard (160 septets). The spec
 * pins this module to the GSM-7 constants (160/153 and 70/67); the
 * provider-reported cost (sms_log.provider_cost) is always the billing
 * source of truth, so this estimate never drives billing decisions.
 * See the module README, "Not verified".
 */
final class SegmentCounter
{
    public const GSM7_SINGLE_SEGMENT_CHARS = 160;
    public const GSM7_MULTIPART_SEGMENT_CHARS = 153;
    public const UCS2_SINGLE_SEGMENT_CHARS = 70;
    public const UCS2_MULTIPART_SEGMENT_CHARS = 67;

    /** GSM 03.38 basic character set (each char = 1 septet). */
    private const GSM7_BASIC = "@\u{00A3}\u{00A5}\u{00E8}\u{00E9}\u{00F9}\u{00EC}\u{00F2}\u{00C7}\n\u{00D8}\u{00F8}\r\u{00C5}\u{00E5}\u{0394}_\u{03A6}\u{0393}\u{039B}\u{03A9}\u{03A0}\u{03A8}\u{03A3}\u{0398}\u{039E}\u{00C6}\u{00E6}\u{00DF}\u{00C9} !\"#\u{00A4}%&'()*+,-./0123456789:;<=>?\u{00A1}ABCDEFGHIJKLMNOPQRSTUVWXYZ\u{00C4}\u{00D6}\u{00D1}\u{00DC}\u{00A7}\u{00BF}abcdefghijklmnopqrstuvwxyz\u{00E4}\u{00F6}\u{00F1}\u{00FC}\u{00E0}";

    /** GSM 03.38 extension table (each char = 2 septets). */
    private const GSM7_EXTENSION = '^{}\\[~]|\u{20AC}';

    /** @var array<string, bool> */
    private static array $basicMap = [];

    /** @var array<string, bool> */
    private static array $extensionMap = [];

    public static function isGsm7(string $text): bool
    {
        foreach (self::charIterator($text) as $char) {
            if (!self::isBasicGsm7Char($char) && !self::isExtendedGsm7Char($char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Count the number of billed SMS segments for the given text.
     */
    public static function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $septets = 0;
        $codePoints = 0;
        $isGsm7 = true;

        foreach (self::charIterator($text) as $char) {
            $codePoints++;
            if (!$isGsm7) {
                continue;
            }
            if (self::isBasicGsm7Char($char)) {
                $septets += 1;
            } elseif (self::isExtendedGsm7Char($char)) {
                $septets += 2;
            } else {
                $isGsm7 = false;
            }
        }

        if ($isGsm7) {
            if ($septets <= self::GSM7_SINGLE_SEGMENT_CHARS) {
                return 1;
            }

            return (int) ceil($septets / self::GSM7_MULTIPART_SEGMENT_CHARS);
        }

        if ($codePoints <= self::UCS2_SINGLE_SEGMENT_CHARS) {
            return 1;
        }

        return (int) ceil($codePoints / self::UCS2_MULTIPART_SEGMENT_CHARS);
    }

    private static function isBasicGsm7Char(string $char): bool
    {
        if (self::$basicMap === []) {
            foreach (self::charIterator(self::GSM7_BASIC) as $basicChar) {
                self::$basicMap[$basicChar] = true;
            }
        }

        return isset(self::$basicMap[$char]);
    }

    private static function isExtendedGsm7Char(string $char): bool
    {
        if (self::$extensionMap === []) {
            foreach (self::charIterator(self::GSM7_EXTENSION) as $extChar) {
                self::$extensionMap[$extChar] = true;
            }
        }

        return isset(self::$extensionMap[$char]);
    }

    /**
     * Iterate a UTF-8 string by Unicode code point.
     *
     * @return \Generator<int, string>
     */
    private static function charIterator(string $text): \Generator
    {
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {
                $take = 1;
            } elseif ($byte >= 0xF0) {
                $take = 4;
            } elseif ($byte >= 0xE0) {
                $take = 3;
            } elseif ($byte >= 0xC0) {
                $take = 2;
            } else {
                // Invalid UTF-8 byte: treat as one opaque unit.
                $take = 1;
            }
            $chunk = substr($text, $i, $take);
            $i += $take;
            yield $chunk === false ? '' : $chunk;
        }
    }
}
