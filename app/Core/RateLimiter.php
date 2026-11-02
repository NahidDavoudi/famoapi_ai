<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiter backed by Redis (primary) with automatic database fallback (auth_throttle).
 */
final class RateLimiter
{
    public static function key(string $scope, string $value): string
    {
        return substr($scope . ':' . hash('sha256', $value), 0, 191);
    }

    public static function isUsingRedis(): bool
    {
        return RedisClient::isAvailable();
    }

    public static function status(string $key, ?int $max = null): array
    {
        if (RedisClient::isAvailable()) {
            return self::redisStatus($key, $max);
        }

        return self::dbStatus($key, $max);
    }

    public static function attempt(string $key, int $max, int $windowSeconds, ?int $lockoutSeconds = null): array
    {
        $window = max(1, $windowSeconds);
        $lockout = max(1, $lockoutSeconds ?? $windowSeconds);
        $max = max(1, $max);

        if (RedisClient::isAvailable()) {
            return self::redisAttempt($key, $max, $window, $lockout);
        }

        return self::dbAttempt($key, $max, $window, $lockout);
    }

    public static function clear(string $key): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del(
                self::redisAttemptsKey($key),
                self::redisLockKey($key)
            );
        }

        if (Database::hasConnection()) {
            try {
                $stmt = Database::getConnection()->prepare('DELETE FROM auth_throttle WHERE throttle_key = :k');
                $stmt->execute(['k' => $key]);
            } catch (\Throwable) {
            }
        }
    }

    public static function prune(): int
    {
        // Redis keys expire automatically via native TTL (SETEX / EXPIRE).
        if (!Database::hasConnection()) {
            return 0;
        }

        try {
            $now = gmdate('Y-m-d H:i:s');
            $dayAgo = gmdate('Y-m-d H:i:s', time() - 86400);
            $stmt = Database::getConnection()->prepare(
                'DELETE FROM auth_throttle
                 WHERE (locked_until IS NULL OR locked_until < :now)
                   AND window_started_at < :day_ago'
            );
            $stmt->execute(['now' => $now, 'day_ago' => $dayAgo]);
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function redisStatus(string $key, ?int $max = null): array
    {
        $attemptsKey = self::redisAttemptsKey($key);
        $lockKey = self::redisLockKey($key);

        $rawAttempts = RedisClient::get($attemptsKey);
        $attempts = $rawAttempts !== null ? (int) $rawAttempts : 0;

        $retryAfter = 0;
        $rawLockUntil = RedisClient::get($lockKey);
        if ($rawLockUntil !== null) {
            $retryAfter = max(0, (int) $rawLockUntil - time());
            if ($retryAfter === 0) {
                $lockTtl = RedisClient::ttl($lockKey);
                if ($lockTtl > 0) {
                    $retryAfter = $lockTtl;
                }
            }
        }

        return [
            'allowed'     => $retryAfter === 0 && ($max === null || $attempts < $max),
            'retry_after' => $retryAfter,
            'attempts'    => $attempts,
        ];
    }

    private static function redisAttempt(string $key, int $max, int $window, int $lockout): array
    {
        $attemptsKey = self::redisAttemptsKey($key);
        $lockKey = self::redisLockKey($key);

        $attempts = RedisClient::incr($attemptsKey);
        if ($attempts === null) {
            return self::dbAttempt($key, $max, $window, $lockout);
        }

        if ($attempts === 1 || RedisClient::ttl($attemptsKey) < 0) {
            RedisClient::expire($attemptsKey, $window);
        }

        if ($attempts >= $max) {
            $existingLock = RedisClient::get($lockKey);
            $now = time();
            if ($existingLock === null || (int) $existingLock <= $now) {
                RedisClient::setex($lockKey, $lockout, (string) ($now + $lockout));
            }
        }

        return self::redisStatus($key, $max);
    }

    private static function dbStatus(string $key, ?int $max = null): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT attempts, locked_until FROM auth_throttle WHERE throttle_key = :k LIMIT 1'
        );
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['allowed' => true, 'retry_after' => 0, 'attempts' => 0];
        }

        $lockedUntil = $row['locked_until'] !== null ? (int) strtotime((string) $row['locked_until'] . ' UTC') : 0;
        $retryAfter = max(0, $lockedUntil - time());
        $attempts = (int) $row['attempts'];

        return [
            'allowed'     => $retryAfter === 0 && ($max === null || $attempts < $max),
            'retry_after' => $retryAfter,
            'attempts'    => $attempts,
        ];
    }

    private static function dbAttempt(string $key, int $max, int $window, int $lockout): array
    {
        $pdo = Database::getConnection();
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $windowCutoff = gmdate('Y-m-d H:i:s', $nowTs - $window);
        $lockUntil = gmdate('Y-m-d H:i:s', $nowTs + $lockout);
        $initialLocked = $max <= 1 ? $lockUntil : null;

        $sql = 'INSERT INTO auth_throttle (throttle_key, attempts, window_started_at, locked_until, updated_at)
                VALUES (:k, 1, :now_ins, :init_lock, :now_upd)
                ON CONFLICT (throttle_key) DO UPDATE SET
                    attempts = CASE
                        WHEN auth_throttle.window_started_at < :cutoff THEN 1
                        ELSE auth_throttle.attempts + 1
                    END,
                    window_started_at = CASE
                        WHEN auth_throttle.window_started_at < :cutoff_w THEN :now_w
                        ELSE auth_throttle.window_started_at
                    END,
                    locked_until = CASE
                        WHEN (CASE WHEN auth_throttle.window_started_at < :cutoff_l THEN 1 ELSE auth_throttle.attempts + 1 END) >= :max_att
                            THEN :lock_until
                        ELSE auth_throttle.locked_until
                    END,
                    updated_at = :now_u';

        $pdo->prepare($sql)->execute([
            'k'          => $key,
            'now_ins'    => $now,
            'init_lock'  => $initialLocked,
            'now_upd'    => $now,
            'cutoff'     => $windowCutoff,
            'cutoff_w'   => $windowCutoff,
            'now_w'      => $now,
            'cutoff_l'   => $windowCutoff,
            'max_att'    => $max,
            'lock_until' => $lockUntil,
            'now_u'      => $now,
        ]);

        return self::dbStatus($key, $max);
    }

    private static function redisAttemptsKey(string $key): string
    {
        return 'rl:attempts:' . $key;
    }

    private static function redisLockKey(string $key): string
    {
        return 'rl:lock:' . $key;
    }
}
