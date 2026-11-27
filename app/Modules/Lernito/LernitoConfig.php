<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use InvalidArgumentException;

/**
 * Configuration loader and multi-account safety guard for the Lernito integration.
 */
final class LernitoConfig
{
    public const DEFAULT_SERVICE_URL = 'http://127.0.0.1:3001';
    public const DEFAULT_ACCOUNTS = ['motevasete1', 'motevasete2'];
    public const DEFAULT_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 100;
    public const DEFAULT_SYNC_TIMEOUT_SECONDS = 45;
    public const DEFAULT_ENSURE_TIMEOUT_SECONDS = 15;
    public const DEFAULT_BATCH_SLEEP_MS = 350;
    public const DEFAULT_MISSING_COOLDOWN_HOURS = 24;
    public const DEFAULT_MISSING_MAX_ATTEMPTS = 5;
    public const DEFAULT_SYNC_LOCK_TTL_SECONDS = 1800;
    public const DEFAULT_REFRESH_DAYS = 60;
    public const ACCOUNT_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    public function __construct(
        public readonly string $serviceUrl,
        public readonly string $internalKey,
        /** @var array<int, string> */
        public readonly array $accounts,
        public readonly bool $sendAccountParam,
        public readonly int $batchSize,
        public readonly int $syncTimeoutSeconds,
        public readonly int $ensureTimeoutSeconds,
        public readonly int $batchSleepMs,
        public readonly int $missingCooldownHours,
        public readonly int $missingMaxAttempts,
        public readonly int $syncLockTtlSeconds,
        public readonly int $refreshDays,
        public readonly string $phpBinary,
    ) {
    }

    public static function fromEnv(): self
    {
        $rawUrl = trim((string) (self::env('LERNITO_SERVICE_URL') ?? self::DEFAULT_SERVICE_URL));
        $serviceUrl = $rawUrl !== '' ? rtrim($rawUrl, '/') : self::DEFAULT_SERVICE_URL;

        $internalKey = trim((string) (self::env('LERNITO_INTERNAL_KEY') ?? ''));

        $rawAccounts = trim((string) (self::env('LERNITO_ACCOUNTS') ?? implode(',', self::DEFAULT_ACCOUNTS)));
        $accounts = [];
        foreach (explode(',', $rawAccounts) as $part) {
            $acc = strtolower(trim($part));
            if ($acc !== '' && preg_match(self::ACCOUNT_PATTERN, $acc) === 1 && !in_array($acc, $accounts, true)) {
                $accounts[] = $acc;
            }
        }
        if ($accounts === []) {
            $accounts = self::DEFAULT_ACCOUNTS;
        }

        $sendAccountParam = self::parseBool(self::env('LERNITO_SEND_ACCOUNT_PARAM'), false);

        $batchSize = self::parseInt(self::env('LERNITO_SYNC_BATCH_SIZE'), self::DEFAULT_BATCH_SIZE, 1, self::MAX_BATCH_SIZE);
        $syncTimeout = self::parseInt(self::env('LERNITO_SYNC_TIMEOUT_SECONDS'), self::DEFAULT_SYNC_TIMEOUT_SECONDS, 5, 300);
        $ensureTimeout = self::parseInt(self::env('LERNITO_ENSURE_TIMEOUT_SECONDS'), self::DEFAULT_ENSURE_TIMEOUT_SECONDS, 2, 60);
        $batchSleepMs = self::parseInt(self::env('LERNITO_SYNC_BATCH_SLEEP_MS'), self::DEFAULT_BATCH_SLEEP_MS, 0, 5000);
        $cooldownHours = self::parseInt(self::env('LERNITO_MISSING_COOLDOWN_HOURS'), self::DEFAULT_MISSING_COOLDOWN_HOURS, 1, 720);
        $maxAttempts = self::parseInt(self::env('LERNITO_MISSING_MAX_ATTEMPTS'), self::DEFAULT_MISSING_MAX_ATTEMPTS, 1, 50);
        $lockTtl = self::parseInt(self::env('LERNITO_SYNC_LOCK_TTL'), self::DEFAULT_SYNC_LOCK_TTL_SECONDS, 60, 14400);
        $refreshDays = self::parseInt(self::env('LERNITO_REFRESH_DAYS'), self::DEFAULT_REFRESH_DAYS, 1, 365);

        $rawPhp = trim((string) (self::env('LERNITO_PHP_BINARY') ?? ''));
        $phpBinary = $rawPhp !== '' ? $rawPhp : (defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php');

        return new self(
            serviceUrl: $serviceUrl,
            internalKey: $internalKey,
            accounts: $accounts,
            sendAccountParam: $sendAccountParam,
            batchSize: $batchSize,
            syncTimeoutSeconds: $syncTimeout,
            ensureTimeoutSeconds: $ensureTimeout,
            batchSleepMs: $batchSleepMs,
            missingCooldownHours: $cooldownHours,
            missingMaxAttempts: $maxAttempts,
            syncLockTtlSeconds: $lockTtl,
            refreshDays: $refreshDays,
            phpBinary: $phpBinary,
        );
    }

    /**
     * Resolve and validate target accounts according to the LERNITO_SEND_ACCOUNT_PARAM safety rule.
     *
     * When LERNITO_SEND_ACCOUNT_PARAM=false:
     *   - An explicit single account is REQUIRED.
     *   - Omitting account, passing "all", or specifying multiple accounts is rejected so shelves
     *     from a single Node gateway instance are never stamped with the wrong account label.
     *
     * @param string|array<int, string>|null $requestedAccount
     * @return array<int, string>
     */
    public function resolveTargetAccounts(string|array|null $requestedAccount): array
    {
        $normalized = [];

        if (is_string($requestedAccount)) {
            $trimmed = strtolower(trim($requestedAccount));
            if ($trimmed === '' || $trimmed === 'all') {
                if (!$this->sendAccountParam) {
                    throw new InvalidArgumentException(
                        'وقتی LERNITO_SEND_ACCOUNT_PARAM=false است، مشخص کردن دقیقاً یک اکانت اجباری است تا شلف‌ها با برچسب اکانت اشتباه ثبت نشوند.'
                    );
                }
                return $this->accounts;
            }
            foreach (explode(',', $trimmed) as $item) {
                $acc = trim($item);
                if ($acc !== '') {
                    $normalized[] = $acc;
                }
            }
        } elseif (is_array($requestedAccount)) {
            foreach ($requestedAccount as $item) {
                if (!is_string($item)) {
                    throw new InvalidArgumentException('نام اکانت لرنیتو باید رشته باشد.');
                }
                $acc = strtolower(trim($item));
                if ($acc !== '') {
                    $normalized[] = $acc;
                }
            }
        } elseif ($requestedAccount === null) {
            if (!$this->sendAccountParam) {
                throw new InvalidArgumentException(
                    'وقتی LERNITO_SEND_ACCOUNT_PARAM=false است، مشخص کردن دقیقاً یک اکانت اجباری است تا شلف‌ها با برچسب اکانت اشتباه ثبت نشوند.'
                );
            }
            return $this->accounts;
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            if (!$this->sendAccountParam) {
                throw new InvalidArgumentException(
                    'وقتی LERNITO_SEND_ACCOUNT_PARAM=false است، مشخص کردن دقیقاً یک اکانت اجباری است.'
                );
            }
            return $this->accounts;
        }

        if (!$this->sendAccountParam && count($normalized) > 1) {
            throw new InvalidArgumentException(
                'وقتی LERNITO_SEND_ACCOUNT_PARAM=false است، اجرای همزمان بیش از یک اکانت مجاز نیست؛ لطفاً فقط یک اکانت مشخص کنید.'
            );
        }

        foreach ($normalized as $acc) {
            $this->assertValidAccount($acc);
        }

        return $normalized;
    }

    public function assertValidAccount(string $account): string
    {
        $acc = strtolower(trim($account));
        if ($acc === '' || preg_match(self::ACCOUNT_PATTERN, $acc) !== 1) {
            throw new InvalidArgumentException("شناسه اکانت لرنیتو «{$account}» معتبر نیست.");
        }
        if (!in_array($acc, $this->accounts, true)) {
            $allowed = implode(', ', $this->accounts);
            throw new InvalidArgumentException("اکانت لرنیتو «{$acc}» مجاز نیست. اکانت‌های مجاز: {$allowed}");
        }
        return $acc;
    }

    private static function env(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        $val = getenv($key);
        return $val !== false ? (string) $val : null;
    }

    private static function parseBool(?string $raw, bool $default): bool
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $norm = strtolower(trim($raw));
        if (in_array($norm, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($norm, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private static function parseInt(?string $raw, int $default, int $min, int $max): int
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $parsed = filter_var(trim($raw), FILTER_VALIDATE_INT);
        if ($parsed === false) {
            return $default;
        }
        return max($min, min($max, (int) $parsed));
    }
}
