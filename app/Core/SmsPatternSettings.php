<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Manages separate Melipayamak pattern body IDs per SMS purpose (2FA login,
 * weekly appointment reminders, and exam result share links) with database
 * persistence and dedicated environment-variable fallback.
 *
 * IMPORTANT: Appointment and Exam patterns NEVER fall back to the 2FA login
 * pattern (`MELIPAYAMAK_PATTERN_BODY_ID`) because each pattern has a distinct
 * placeholder schema.
 */
final class SmsPatternSettings
{
    public const KEY_AUTH_2FA = 'auth_2fa';
    public const KEY_APPOINTMENT_REMINDER = 'appointment_reminder';
    public const KEY_EXAM_RESULT = 'exam_result';

    public const DEFAULT_APPOINTMENT_TEMPLATE =
        'موسسه آموزشی فامو | دکتر موسوی والدین گرامی {0} ، نوبت فرزند شما روز {1} ساعت {2} میباشد. در صورت تایید به شماره موسسه پیامک دهید.';

    public const DEFINITIONS = [
        self::KEY_AUTH_2FA => [
            'label'                => 'کد تایید ورود دو مرحله‌ای (لاگین ادمین و کادر)',
            'env_key'              => 'MELIPAYAMAK_PATTERN_BODY_ID',
            'alias_field'          => 'auth_body_id',
            'default_pattern_text' => 'کد تایید ورود به پنل فامو: {0}',
            'placeholders'         => ['کد تایید ۶ رقمی'],
        ],
        self::KEY_APPOINTMENT_REMINDER => [
            'label'                => 'یادآوری نوبت هفتگی شاگردان به والدین',
            'env_key'              => 'MELIPAYAMAK_APPOINTMENT_BODY_ID',
            'alias_field'          => 'appointment_body_id',
            'default_pattern_text' => self::DEFAULT_APPOINTMENT_TEMPLATE,
            'placeholders'         => ['نام و نام خانوادگی دانش‌آموز', 'روز و تاریخ شمسی', 'ساعت نوبت'],
        ],
        self::KEY_EXAM_RESULT => [
            'label'                => 'ارسال لینک کارنامه آزمون به والدین',
            'env_key'              => 'MELIPAYAMAK_EXAM_BODY_ID',
            'alias_field'          => 'exam_body_id',
            'default_pattern_text' => "کارنامه آزمون {0} در تاریخ {1}:\n{2}\nآکادمی فامو",
            'placeholders'         => ['نام و نام خانوادگی دانش‌آموز', 'تاریخ آزمون', 'لینک کارنامه'],
        ],
    ];

    private static bool $tableEnsured = false;

    public static function ensureTable(?PDO $db = null): void
    {
        if (self::$tableEnsured) {
            return;
        }

        $pdo = $db ?? Database::getConnection();
        if ($pdo->inTransaction()) {
            return;
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'pgsql') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sms_pattern_settings (
                    pattern_key VARCHAR(64) PRIMARY KEY,
                    body_id BIGINT NULL,
                    pattern_text TEXT NULL,
                    updated_by INT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
        } else {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `sms_pattern_settings` (
                    `pattern_key`  VARCHAR(64) NOT NULL,
                    `body_id`      BIGINT      DEFAULT NULL,
                    `pattern_text` TEXT        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `updated_by`   INT         DEFAULT NULL,
                    `updated_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`pattern_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        self::$tableEnsured = true;
    }

    public static function envValue(string $key): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($val) && !is_numeric($val)) {
            return '';
        }
        return trim((string) $val);
    }

    public static function parsePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !ctype_digit($trimmed)) {
                return null;
            }
            $parsed = (int) $trimmed;
            return $parsed > 0 ? $parsed : null;
        }
        return null;
    }

    /**
     * Resolve effective configuration for one pattern key.
     *
     * Priority:
     * 1. Explicit per-request override ($overrideBodyId)
     * 2. Database setting in `sms_pattern_settings`
     * 3. Dedicated environment variable (`env_key`)
     *
     * @return array<string, mixed>
     */
    public static function resolve(string $key, ?int $overrideBodyId = null): array
    {
        if (!isset(self::DEFINITIONS[$key])) {
            throw new ApiException("کلید پترن پیامک «{$key}» معتبر نیست", 422, 'VALIDATION_ERROR');
        }

        $def = self::DEFINITIONS[$key];
        $dbRow = self::fetchDbRow($key);

        $dbBodyId = self::parsePositiveInt($dbRow['body_id'] ?? null);
        $envRaw = self::envValue($def['env_key']);
        $envBodyId = self::parsePositiveInt($envRaw);

        $patternText = isset($dbRow['pattern_text']) && trim((string) $dbRow['pattern_text']) !== ''
            ? (string) $dbRow['pattern_text']
            : $def['default_pattern_text'];

        if ($overrideBodyId !== null && $overrideBodyId > 0) {
            $effectiveBodyId = $overrideBodyId;
            $source = 'request';
        } elseif ($dbBodyId !== null) {
            $effectiveBodyId = $dbBodyId;
            $source = 'database';
        } elseif ($envBodyId !== null) {
            $effectiveBodyId = $envBodyId;
            $source = 'env';
        } else {
            $effectiveBodyId = null;
            $source = 'unset';
        }

        return [
            'pattern_key'   => $key,
            'label'         => $def['label'],
            'env_key'       => $def['env_key'],
            'alias_field'   => $def['alias_field'],
            'body_id'       => $effectiveBodyId,
            'db_body_id'    => $dbBodyId,
            'env_body_id'   => $envBodyId,
            'source'        => $source,
            'is_configured' => $effectiveBodyId !== null && $effectiveBodyId > 0,
            'pattern_text'  => $patternText,
            'placeholders'  => $def['placeholders'],
            'updated_by'    => isset($dbRow['updated_by']) ? (int) $dbRow['updated_by'] : null,
            'updated_at'    => $dbRow['updated_at'] ?? null,
        ];
    }

    public static function resolveBodyId(string $key, ?int $overrideBodyId = null): ?int
    {
        $resolved = self::resolve($key, $overrideBodyId);
        return $resolved['body_id'] !== null ? (int) $resolved['body_id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        $dbRows = self::fetchAllDbRows();
        $patterns = [];
        $items = [];

        foreach (array_keys(self::DEFINITIONS) as $key) {
            $resolved = self::resolveFromDbRow($key, $dbRows[$key] ?? null);
            $patterns[$key] = $resolved;
            $items[] = $resolved;
        }

        return [
            'patterns'            => $patterns,
            'items'               => $items,
            'auth_body_id'        => $patterns[self::KEY_AUTH_2FA]['body_id'],
            'appointment_body_id' => $patterns[self::KEY_APPOINTMENT_REMINDER]['body_id'],
            'exam_body_id'        => $patterns[self::KEY_EXAM_RESULT]['body_id'],
        ];
    }

    /**
     * @param array<string, array{body_id?: ?int, pattern_text?: ?string}> $updates
     * @return array<string, mixed>
     */
    public static function updateMany(array $updates, ?int $actorUserId = null): array
    {
        if ($updates === []) {
            return self::getAll();
        }

        $pdo = Database::getConnection();
        self::ensureTable($pdo);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        foreach ($updates as $key => $payload) {
            if (!isset(self::DEFINITIONS[$key])) {
                throw new ApiException("کلید پترن پیامک «{$key}» معتبر نیست", 422, 'VALIDATION_ERROR');
            }

            $existing = self::fetchDbRow($key);
            $hasBodyId = array_key_exists('body_id', $payload);
            $hasPatternText = array_key_exists('pattern_text', $payload);

            $bodyId = $hasBodyId
                ? ($payload['body_id'] !== null ? (int) $payload['body_id'] : null)
                : self::parsePositiveInt($existing['body_id'] ?? null);

            $patternText = $hasPatternText
                ? ($payload['pattern_text'] !== null && trim((string) $payload['pattern_text']) !== ''
                    ? trim((string) $payload['pattern_text'])
                    : self::DEFINITIONS[$key]['default_pattern_text'])
                : ($existing['pattern_text'] ?? self::DEFINITIONS[$key]['default_pattern_text']);

            if ($key === self::KEY_EXAM_RESULT && is_string($patternText) && str_contains($patternText, '{2}')) {
                // Ensure a space or non-URL separator follows `{2}` so SMS clients never glue trailing chars to the token.
                if (preg_match('/\{2\}(?:$|[^\s])/u', $patternText) === 1) {
                    $patternText = preg_replace('/\{2\}(?=$|[^\s])/u', '{2} ', $patternText) ?? $patternText;
                    if (str_ends_with($patternText, '{2} ')) {
                        $patternText .= "\nآکادمی فامو";
                    }
                }
            }

            if ($driver === 'pgsql') {
                $stmt = $pdo->prepare(
                    'INSERT INTO sms_pattern_settings (pattern_key, body_id, pattern_text, updated_by, updated_at)
                     VALUES (:pattern_key, :body_id, :pattern_text, :updated_by, CURRENT_TIMESTAMP)
                     ON CONFLICT (pattern_key) DO UPDATE SET
                         body_id      = EXCLUDED.body_id,
                         pattern_text = EXCLUDED.pattern_text,
                         updated_by   = EXCLUDED.updated_by,
                         updated_at   = CURRENT_TIMESTAMP'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO sms_pattern_settings (pattern_key, body_id, pattern_text, updated_by, updated_at)
                     VALUES (:pattern_key, :body_id, :pattern_text, :updated_by, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                         body_id      = VALUES(body_id),
                         pattern_text = VALUES(pattern_text),
                         updated_by   = VALUES(updated_by),
                         updated_at   = CURRENT_TIMESTAMP'
                );
            }

            $stmt->execute([
                'pattern_key'  => $key,
                'body_id'      => $bodyId,
                'pattern_text' => $patternText,
                'updated_by'   => $actorUserId,
            ]);
        }

        return self::getAll();
    }

    public static function renderAppointmentMessage(
        string $studentName,
        string $dayAndDate,
        string $time,
        ?string $template = null
    ): string {
        $tpl = ($template !== null && trim($template) !== '')
            ? $template
            : self::DEFAULT_APPOINTMENT_TEMPLATE;

        return strtr($tpl, [
            '{0}' => trim($studentName),
            '{1}' => trim($dayAndDate),
            '{2}' => trim($time),
        ]);
    }

    public static function renderExamResultMessage(
        string $studentName,
        string $examDate,
        string $shareUrl,
        ?string $template = null
    ): string {
        $tpl = ($template !== null && trim($template) !== '')
            ? $template
            : self::DEFINITIONS[self::KEY_EXAM_RESULT]['default_pattern_text'];

        return strtr($tpl, [
            '{0}' => trim($studentName),
            '{1}' => trim($examDate),
            '{2}' => trim($shareUrl),
        ]);
    }

    /**
     * @param array<string, mixed>|null $dbRow
     * @return array<string, mixed>
     */
    private static function resolveFromDbRow(string $key, ?array $dbRow): array
    {
        $def = self::DEFINITIONS[$key];
        $dbBodyId = self::parsePositiveInt($dbRow['body_id'] ?? null);
        $envBodyId = self::parsePositiveInt(self::envValue($def['env_key']));

        $patternText = isset($dbRow['pattern_text']) && trim((string) $dbRow['pattern_text']) !== ''
            ? (string) $dbRow['pattern_text']
            : $def['default_pattern_text'];

        if ($dbBodyId !== null) {
            $effectiveBodyId = $dbBodyId;
            $source = 'database';
        } elseif ($envBodyId !== null) {
            $effectiveBodyId = $envBodyId;
            $source = 'env';
        } else {
            $effectiveBodyId = null;
            $source = 'unset';
        }

        return [
            'pattern_key'   => $key,
            'label'         => $def['label'],
            'env_key'       => $def['env_key'],
            'alias_field'   => $def['alias_field'],
            'body_id'       => $effectiveBodyId,
            'db_body_id'    => $dbBodyId,
            'env_body_id'   => $envBodyId,
            'source'        => $source,
            'is_configured' => $effectiveBodyId !== null && $effectiveBodyId > 0,
            'pattern_text'  => $patternText,
            'placeholders'  => $def['placeholders'],
            'updated_by'    => isset($dbRow['updated_by']) ? (int) $dbRow['updated_by'] : null,
            'updated_at'    => $dbRow['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchDbRow(string $key): ?array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTable($pdo);
            $stmt = $pdo->prepare('SELECT pattern_key, body_id, pattern_text, updated_by, updated_at FROM sms_pattern_settings WHERE pattern_key = :key LIMIT 1');
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function fetchAllDbRows(): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTable($pdo);
            $stmt = $pdo->query('SELECT pattern_key, body_id, pattern_text, updated_by, updated_at FROM sms_pattern_settings');
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['pattern_key'])) {
                    $out[(string) $row['pattern_key']] = $row;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
