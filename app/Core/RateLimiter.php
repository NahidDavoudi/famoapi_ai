<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Redis-backed fixed-window rate limiter using atomic Lua execution (INCR + EXPIRE + TTL),
 * plus lockout-capable auth throttle helpers with optional DB fallback.
 */
final class RateLimiter
{
    public const FIXED_WINDOW_LUA = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if tonumber(current) == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
else
    local ttl = redis.call('TTL', KEYS[1])
    if ttl < 0 then
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
    end
end
local remaining_ttl = redis.call('TTL', KEYS[1])
if remaining_ttl < 0 then
    remaining_ttl = tonumber(ARGV[1])
end
return {current, remaining_ttl}
LUA;

    /**
     * Load normalized rate-limit config dynamically from config/rate_limits.php.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $path = __DIR__ . '/../../config/rate_limits.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                return $loaded;
            }
        }
        return [
            'auth_user_ip_max'       => 5,
            'auth_user_ip_window'    => 60,
            'auth_ip_max'            => 100,
            'auth_ip_window'         => 900,
            'sms_phone_max'          => 3,
            'sms_phone_window'       => 600,
            'sms_ip_max'             => 10,
            'sms_ip_window'          => 3600,
            'api_student_max'        => 120,
            'api_staff_max'          => 300,
            'api_auth_window'        => 60,
            'public_ip_max'          => 120,
            'public_ip_window'       => 60,
            'public_exam_ip_max'     => 20,
            'public_exam_ip_window'  => 60,
            'exempt_prefixes'        => ['/api/v1/bot'],
            'revocation_fail_mode'       => 'open',
            'revocation_admin_fail_mode' => 'closed',
        ];
    }

    public static function key(string $scope, string $value): string
    {
        return substr($scope . ':' . hash('sha256', strtolower(trim($value))), 0, 191);
    }

    public static function isUsingRedis(): bool
    {
        return RedisClient::isAvailable();
    }

    /**
     * Atomic fixed-window counter via Redis Lua script.
     * Fails open with a warning log if Redis is disabled or unreachable.
     *
     * @return array{allowed: bool, retry_after: int, attempts: int, remaining: int, limit: int, degraded: bool}
     */
    public static function attemptFixedWindow(string $key, int $max, int $windowSeconds): array
    {
        $max = max(1, $max);
        $window = max(1, $windowSeconds);
        $redisKey = self::fixedWindowKey($key);

        if (!RedisClient::isAvailable()) {
            self::logFailOpen('attemptFixedWindow', $key);
            return [
                'allowed'     => true,
                'retry_after' => 0,
                'attempts'    => 0,
                'remaining'   => $max,
                'limit'       => $max,
                'degraded'    => true,
            ];
        }

        $res = RedisClient::eval(self::FIXED_WINDOW_LUA, [$redisKey], [(string) $window]);
        if (!is_array($res) || !isset($res[0])) {
            self::logFailOpen('attemptFixedWindow:eval', $key);
            return [
                'allowed'     => true,
                'retry_after' => 0,
                'attempts'    => 0,
                'remaining'   => $max,
                'limit'       => $max,
                'degraded'    => true,
            ];
        }

        $attempts = (int) $res[0];
        $ttl = max(1, (int) ($res[1] ?? $window));
        $allowed = $attempts <= $max;
        $remaining = max(0, $max - $attempts);

        return [
            'allowed'     => $allowed,
            'retry_after' => $allowed ? 0 : $ttl,
            'attempts'    => $attempts,
            'remaining'   => $remaining,
            'limit'       => $max,
            'degraded'    => false,
        ];
    }

    public static function clearFixedWindow(string $key): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del(self::fixedWindowKey($key));
        }
    }

    public static function status(string $key, ?int $max = null): array
    {
        if (RedisClient::isAvailable()) {
            return self::redisStatus($key, $max);
        }

        if (Database::hasConnection()) {
            return self::dbStatus($key, $max);
        }

        return ['allowed' => true, 'retry_after' => 0, 'attempts' => 0];
    }

    public static function attempt(string $key, int $max, int $windowSeconds, ?int $lockoutSeconds = null): array
    {
        $window = max(1, $windowSeconds);
        $lockout = max(1, $lockoutSeconds ?? $windowSeconds);
        $max = max(1, $max);

        if (RedisClient::isAvailable()) {
            return self::redisAttempt($key, $max, $window, $lockout);
        }

        if (Database::hasConnection()) {
            return self::dbAttempt($key, $max, $window, $lockout);
        }

        self::logFailOpen('attempt', $key);
        return ['allowed' => true, 'retry_after' => 0, 'attempts' => 0];
    }

    public static function clear(string $key): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del(
                self::redisAttemptsKey($key),
                self::redisLockKey($key),
                self::fixedWindowKey($key)
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

        $res = RedisClient::eval(
            self::FIXED_WINDOW_LUA,
            [$attemptsKey, $lockKey],
            [(string) $window, (string) $max, (string) $lockout]
        );

        if (!is_array($res) || !isset($res[0])) {
            if (Database::hasConnection()) {
                return self::dbAttempt($key, $max, $window, $lockout);
            }
            self::logFailOpen('redisAttempt:eval', $key);
            return ['allowed' => true, 'retry_after' => 0, 'attempts' => 0];
        }

        $attempts = (int) $res[0];
        if ($attempts >= $max && RedisClient::get($lockKey) === null) {
            $now = time();
            RedisClient::setex($lockKey, $lockout, (string) ($now + $lockout));
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
                ON DUPLICATE KEY UPDATE
                    locked_until = CASE
                        WHEN (CASE WHEN window_started_at < :cutoff_l THEN 1 ELSE attempts + 1 END) >= :max_att
                            THEN :lock_until
                        ELSE locked_until
                    END,
                    attempts = CASE
                        WHEN window_started_at < :cutoff THEN 1
                        ELSE attempts + 1
                    END,
                    window_started_at = CASE
                        WHEN window_started_at < :cutoff_w THEN :now_w
                        ELSE window_started_at
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

    private static function logFailOpen(string $context, string $key): void
    {
        $reason = RedisClient::getLastError()
            ?? (RedisClient::isEnabled() ? 'Redis connection unavailable' : 'Redis disabled');
        error_log(sprintf(
            '[%s] [WARNING] RateLimiter::%s failing open (key=%s): %s',
            gmdate('Y-m-d H:i:s'),
            $context,
            $key,
            $reason
        ));
    }

    private static function fixedWindowKey(string $key): string
    {
        return 'rl:fw:' . $key;
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
