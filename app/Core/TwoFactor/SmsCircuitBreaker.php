<?php
declare(strict_types=1);

namespace App\Core\TwoFactor;

use App\Core\RedisClient;

/**
 * Simple circuit breaker for admin 2FA SMS delivery.
 *
 * After 3 consecutive SMS failures, opens the circuit for 10 minutes (600s)
 * so subsequent logins immediately skip SMS without waiting for a timeout.
 * Backed by Redis when available, with an automatic local file fallback.
 */
final class SmsCircuitBreaker
{
    public const FAILURE_THRESHOLD = 3;
    public const OPEN_SECONDS = 600;

    private const REDIS_FAILURES_KEY = '2fa:cb:sms:failures';
    private const REDIS_OPEN_UNTIL_KEY = '2fa:cb:sms:open_until';

    private static ?string $statePathOverride = null;
    /** @var (callable(): int)|null */
    private static $clockOverride = null;

    public static function setStatePath(?string $path): void
    {
        self::$statePathOverride = $path;
    }

    /** @param (callable(): int)|null $clock */
    public static function setClock(?callable $clock): void
    {
        self::$clockOverride = $clock;
    }

    public static function reset(): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del(self::REDIS_FAILURES_KEY, self::REDIS_OPEN_UNTIL_KEY);
        }
        $path = self::statePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function isOpen(): bool
    {
        return self::remainingSeconds() > 0;
    }

    public static function remainingSeconds(): int
    {
        $now = self::now();

        if (RedisClient::isAvailable()) {
            $openUntilRaw = RedisClient::get(self::REDIS_OPEN_UNTIL_KEY);
            if ($openUntilRaw !== null && is_numeric($openUntilRaw)) {
                $rem = (int) $openUntilRaw - $now;
                if ($rem > 0) {
                    return $rem;
                }
                RedisClient::del(self::REDIS_OPEN_UNTIL_KEY);
            }
            return 0;
        }

        $state = self::readFileState();
        $openUntil = (int) ($state['open_until'] ?? 0);
        if ($openUntil > $now) {
            return $openUntil - $now;
        }

        return 0;
    }

    /**
     * Record a failed SMS delivery attempt. Opens the circuit when consecutive failures
     * reach FAILURE_THRESHOLD (3).
     *
     * @return array{failures: int, opened: bool, open_seconds: int}
     */
    public static function recordFailure(): array
    {
        $now = self::now();

        if (RedisClient::isAvailable()) {
            $failures = (int) (RedisClient::get(self::REDIS_FAILURES_KEY) ?? 0) + 1;
            $opened = false;

            if ($failures >= self::FAILURE_THRESHOLD) {
                $openUntil = $now + self::OPEN_SECONDS;
                RedisClient::setex(self::REDIS_OPEN_UNTIL_KEY, self::OPEN_SECONDS, (string) $openUntil);
                RedisClient::del(self::REDIS_FAILURES_KEY);
                $opened = true;
            } else {
                RedisClient::setex(self::REDIS_FAILURES_KEY, self::OPEN_SECONDS, (string) $failures);
            }

            return [
                'failures'     => $failures,
                'opened'       => $opened,
                'open_seconds' => $opened ? self::OPEN_SECONDS : 0,
            ];
        }

        $state = self::readFileState();
        $failures = ((int) ($state['failures'] ?? 0)) + 1;
        $opened = false;

        if ($failures >= self::FAILURE_THRESHOLD) {
            $state['failures'] = 0;
            $state['open_until'] = $now + self::OPEN_SECONDS;
            $opened = true;
        } else {
            $state['failures'] = $failures;
        }

        self::writeFileState($state);

        return [
            'failures'     => $failures,
            'opened'       => $opened,
            'open_seconds' => $opened ? self::OPEN_SECONDS : 0,
        ];
    }

    public static function recordSuccess(): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del(self::REDIS_FAILURES_KEY, self::REDIS_OPEN_UNTIL_KEY);
            return;
        }

        self::writeFileState(['failures' => 0, 'open_until' => 0]);
    }

    private static function now(): int
    {
        return is_callable(self::$clockOverride) ? (int) (self::$clockOverride)() : time();
    }

    private static function statePath(): string
    {
        if (self::$statePathOverride !== null && self::$statePathOverride !== '') {
            return self::$statePathOverride;
        }

        return dirname(__DIR__, 3) . '/storage/logs/sms_2fa_circuit_breaker.json';
    }

    /** @return array{failures: int, open_until: int} */
    private static function readFileState(): array
    {
        $path = self::statePath();
        if (!is_file($path)) {
            return ['failures' => 0, 'open_until' => 0];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return ['failures' => 0, 'open_until' => 0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['failures' => 0, 'open_until' => 0];
        }

        return [
            'failures'   => isset($decoded['failures']) && is_numeric($decoded['failures']) ? (int) $decoded['failures'] : 0,
            'open_until' => isset($decoded['open_until']) && is_numeric($decoded['open_until']) ? (int) $decoded['open_until'] : 0,
        ];
    }

    /** @param array{failures: int, open_until: int} $state */
    private static function writeFileState(array $state): void
    {
        $path = self::statePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, (string) json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
