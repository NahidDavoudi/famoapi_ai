<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Redis client supporting:
 * 1. Native PHP ext-redis (\Redis) when available
 * 2. Pure-PHP RESP2 stream socket client when ext-redis is not installed
 * 3. In-memory driver for unit testing without an external Redis daemon
 *
 * Includes downtime state tracking & critical error logging with error message
 * and downtime duration whenever Redis becomes unreachable or recovers.
 */
final class RedisClient
{
    private static ?\Redis $extClient = null;
    /** @var resource|null */
    private static $socket = null;
    private static ?bool $connected = null;
    private static bool $useInMemory = false;
    private static bool $simulateFailure = false;
    private static string $simulatedError = 'Simulated Redis connection failure';
    private static ?int $testNow = null;

    private static ?string $lastError = null;
    private static ?string $customLogPath = null;
    private static ?string $customDowntimeStatePath = null;
    private static bool $inLogging = false;

    /** @var array<string, array{value: string, exp: ?int}> */
    private static array $memoryStrings = [];
    /** @var array<string, array<string, bool>> */
    private static array $memorySets = [];

    private static array $config = [];

    public static function configure(array $config = []): void
    {
        self::disconnect();
        $defaultConfig = is_file(__DIR__ . '/../../config/redis.php')
            ? require __DIR__ . '/../../config/redis.php'
            : [];
        self::$config = array_merge($defaultConfig, $config);
    }

    public static function useInMemoryDriver(bool $enable = true): void
    {
        self::$useInMemory = $enable;
        self::$simulateFailure = false;
        self::$memoryStrings = [];
        self::$memorySets = [];
        self::$connected = $enable ? true : null;
        self::$lastError = null;
    }

    public static function simulateFailure(bool $enable = true, string $errorMessage = 'Connection refused'): void
    {
        self::$simulateFailure = $enable;
        self::$simulatedError = $errorMessage;
        if ($enable) {
            self::$connected = false;
            self::recordFailure('simulateFailure', $errorMessage);
        } else {
            self::$connected = self::$useInMemory ? true : null;
            self::recordRecoveryIfNeeded();
        }
    }

    public static function setTestNow(?int $timestamp): void
    {
        self::$testNow = $timestamp;
    }

    public static function setLogPath(?string $path): void
    {
        self::$customLogPath = $path;
    }

    public static function setDowntimeStatePath(?string $path): void
    {
        self::$customDowntimeStatePath = $path;
    }

    public static function reset(): void
    {
        self::disconnect();
        self::$useInMemory = false;
        self::$simulateFailure = false;
        self::$testNow = null;
        self::$lastError = null;
        self::$customLogPath = null;
        self::$customDowntimeStatePath = null;
        self::$memoryStrings = [];
        self::$memorySets = [];
        self::$config = [];
    }

    public static function isEnabled(): bool
    {
        if (self::$useInMemory) {
            return true;
        }
        $cfg = self::resolvedConfig();
        return !empty($cfg['enabled']);
    }

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * @return array{down_since: float, last_error: string, last_logged_at: float}|null
     */
    public static function getDowntimeState(): ?array
    {
        $path = self::downtimeStatePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && isset($decoded['down_since']) ? $decoded : null;
    }

    public static function isAvailable(): bool
    {
        if (self::$simulateFailure) {
            return false;
        }

        if (self::$useInMemory) {
            return true;
        }

        if (self::$connected !== null) {
            return self::$connected;
        }

        $cfg = self::resolvedConfig();
        if (empty($cfg['enabled'])) {
            self::$connected = false;
            return false;
        }

        self::$connected = self::connect($cfg);
        if (self::$connected) {
            self::recordRecoveryIfNeeded();
        }
        return self::$connected;
    }

    public static function ping(): bool
    {
        if (self::$simulateFailure) {
            self::recordFailure('PING', self::$simulatedError);
            return false;
        }

        if (!self::isAvailable()) {
            return false;
        }

        if (self::$useInMemory) {
            self::recordRecoveryIfNeeded();
            return true;
        }

        try {
            if (self::$extClient !== null) {
                $res = @self::$extClient->ping();
                if ($res === true || $res === '+PONG' || $res === 'PONG') {
                    self::recordRecoveryIfNeeded();
                    return true;
                }
                self::recordFailure('PING', 'Unexpected ext-redis PING reply');
                self::disconnect();
                return false;
            }

            $res = self::sendCommand(['PING']);
            if ($res === 'PONG' || $res === true) {
                self::recordRecoveryIfNeeded();
                return true;
            }
            self::recordFailure('PING', 'Unexpected RESP PING reply');
            self::disconnect();
            return false;
        } catch (\Throwable $e) {
            self::recordFailure('PING', $e->getMessage());
            self::disconnect();
            return false;
        }
    }

    /**
     * @return array{status: string, connected: bool, latency_ms: float}
     */
    public static function checkHealth(): array
    {
        $start = microtime(true);
        $cfg = self::resolvedConfig();
        $enabled = !empty($cfg['enabled']) || self::$useInMemory;

        $connected = $enabled ? self::ping() : false;
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        $status = $connected
            ? 'connected'
            : ($enabled ? 'disconnected' : 'disabled');

        return [
            'status'     => $status,
            'connected'  => $connected,
            'latency_ms' => $latencyMs,
        ];
    }

    public static function get(string $key): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }

        $prefixed = self::prefixKey($key);

        if (self::$useInMemory) {
            self::evictExpiredMemoryKey($prefixed);
            return self::$memoryStrings[$prefixed]['value'] ?? null;
        }

        try {
            if (self::$extClient !== null) {
                $val = self::$extClient->get($prefixed);
                return $val === false ? null : (string) $val;
            }
            $res = self::sendCommand(['GET', $prefixed]);
            return $res === null ? null : (string) $res;
        } catch (\Throwable $e) {
            self::recordFailure('GET', $e->getMessage());
            self::disconnect();
            return null;
        }
    }

    public static function setex(string $key, int $ttl, string $value): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $ttl = max(1, $ttl);
        $prefixed = self::prefixKey($key);

        if (self::$useInMemory) {
            self::$memoryStrings[$prefixed] = [
                'value' => $value,
                'exp'   => self::now() + $ttl,
            ];
            return true;
        }

        try {
            if (self::$extClient !== null) {
                return (bool) self::$extClient->setex($prefixed, $ttl, $value);
            }
            $res = self::sendCommand(['SETEX', $prefixed, (string) $ttl, $value]);
            return $res === 'OK' || $res === true;
        } catch (\Throwable $e) {
            self::recordFailure('SETEX', $e->getMessage());
            self::disconnect();
            return false;
        }
    }

    public static function set(string $key, string $value, ?int $ttl = null): bool
    {
        if ($ttl !== null) {
            return self::setex($key, $ttl, $value);
        }

        if (!self::isAvailable()) {
            return false;
        }

        $prefixed = self::prefixKey($key);

        if (self::$useInMemory) {
            self::$memoryStrings[$prefixed] = [
                'value' => $value,
                'exp'   => null,
            ];
            return true;
        }

        try {
            if (self::$extClient !== null) {
                return (bool) self::$extClient->set($prefixed, $value);
            }
            $res = self::sendCommand(['SET', $prefixed, $value]);
            return $res === 'OK' || $res === true;
        } catch (\Throwable $e) {
            self::recordFailure('SET', $e->getMessage());
            self::disconnect();
            return false;
        }
    }

    public static function del(string ...$keys): int
    {
        if ($keys === [] || !self::isAvailable()) {
            return 0;
        }

        $prefixedKeys = array_map([self::class, 'prefixKey'], $keys);

        if (self::$useInMemory) {
            $removed = 0;
            foreach ($prefixedKeys as $k) {
                if (isset(self::$memoryStrings[$k])) {
                    unset(self::$memoryStrings[$k]);
                    $removed++;
                }
                if (isset(self::$memorySets[$k])) {
                    unset(self::$memorySets[$k]);
                    $removed++;
                }
            }
            return $removed;
        }

        try {
            if (self::$extClient !== null) {
                return (int) self::$extClient->del($prefixedKeys);
            }
            return (int) self::sendCommand(array_merge(['DEL'], $prefixedKeys));
        } catch (\Throwable $e) {
            self::recordFailure('DEL', $e->getMessage());
            self::disconnect();
            return 0;
        }
    }

    public static function incr(string $key): ?int
    {
        if (!self::isAvailable()) {
            return null;
        }

        $prefixed = self::prefixKey($key);

        if (self::$useInMemory) {
            self::evictExpiredMemoryKey($prefixed);
            $current = isset(self::$memoryStrings[$prefixed])
                ? (int) self::$memoryStrings[$prefixed]['value']
                : 0;
            $exp = self::$memoryStrings[$prefixed]['exp'] ?? null;
            $next = $current + 1;
            self::$memoryStrings[$prefixed] = [
                'value' => (string) $next,
                'exp'   => $exp,
            ];
            return $next;
        }

        try {
            if (self::$extClient !== null) {
                $val = self::$extClient->incr($prefixed);
                return $val === false ? null : (int) $val;
            }
            $res = self::sendCommand(['INCR', $prefixed]);
            return $res === null ? null : (int) $res;
        } catch (\Throwable $e) {
            self::recordFailure('INCR', $e->getMessage());
            self::disconnect();
            return null;
        }
    }

    public static function expire(string $key, int $ttl): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $ttl = max(1, $ttl);
        $prefixed = self::prefixKey($key);

        if (self::$useInMemory) {
            self::evictExpiredMemoryKey($prefixed);
            if (isset(self::$memoryStrings[$prefixed])) {
                self::$memoryStrings[$prefixed]['exp'] = self::now() + $ttl;
                return true;
            }
            return false;
        }

        try {
            if (self::$extClient !== null) {
                return (bool) self::$extClient->expire($prefixed, $ttl);
            }
            return (int) self::sendCommand(['EXPIRE', $prefixed, (string) $ttl]) === 1;
        } catch (\Throwable $e) {
            self::recordFailure('EXPIRE', $e->getMessage());
            self::disconnect();
            return false;
        }
    }

    public static function ttl(string $key): int
    {
        if (!self::isAvailable()) {
            return -2;
        }

        $prefixed = self::prefixKey($key);

        if (self::$useInMemory) {
            self::evictExpiredMemoryKey($prefixed);
            if (!isset(self::$memoryStrings[$prefixed])) {
                return -2;
            }
            $exp = self::$memoryStrings[$prefixed]['exp'];
            if ($exp === null) {
                return -1;
            }
            return max(0, $exp - self::now());
        }

        try {
            if (self::$extClient !== null) {
                return (int) self::$extClient->ttl($prefixed);
            }
            return (int) self::sendCommand(['TTL', $prefixed]);
        } catch (\Throwable $e) {
            self::recordFailure('TTL', $e->getMessage());
            self::disconnect();
            return -2;
        }
    }

    /**
     * Evaluate a Lua script atomically against Redis (ext-redis, RESP2 socket, or in-memory mirror).
     * Keys passed in $keys are automatically prefixed with REDIS_PREFIX.
     *
     * @param array<int, string> $keys
     * @param array<int, scalar> $args
     */
    public static function eval(string $script, array $keys = [], array $args = []): mixed
    {
        if (!self::isAvailable()) {
            return null;
        }

        $prefixedKeys = array_map([self::class, 'prefixKey'], $keys);
        $strArgs = array_map(static fn ($v): string => (string) $v, $args);

        if (self::$useInMemory) {
            return self::evalInMemory($script, $prefixedKeys, $strArgs);
        }

        try {
            if (self::$extClient !== null) {
                $res = @self::$extClient->eval($script, array_merge($prefixedKeys, $strArgs), count($prefixedKeys));
                if ($res === false) {
                    $err = self::$extClient->getLastError() ?: 'Redis EVAL returned false';
                    self::$extClient->clearLastError();
                    self::recordFailure('EVAL', $err);
                    return null;
                }
                return $res;
            }

            return self::sendCommand(array_merge(
                ['EVAL', $script, (string) count($prefixedKeys)],
                $prefixedKeys,
                $strArgs
            ));
        } catch (\Throwable $e) {
            self::recordFailure('EVAL', $e->getMessage());
            self::disconnect();
            return null;
        }
    }

    public static function sAdd(string $setKey, string ...$members): int
    {
        if ($members === [] || !self::isAvailable()) {
            return 0;
        }

        $prefixed = self::prefixKey($setKey);

        if (self::$useInMemory) {
            if (!isset(self::$memorySets[$prefixed])) {
                self::$memorySets[$prefixed] = [];
            }
            $added = 0;
            foreach ($members as $member) {
                if (!isset(self::$memorySets[$prefixed][$member])) {
                    self::$memorySets[$prefixed][$member] = true;
                    $added++;
                }
            }
            return $added;
        }

        try {
            if (self::$extClient !== null) {
                return (int) self::$extClient->sAdd($prefixed, ...$members);
            }
            return (int) self::sendCommand(array_merge(['SADD', $prefixed], $members));
        } catch (\Throwable $e) {
            self::recordFailure('SADD', $e->getMessage());
            self::disconnect();
            return 0;
        }
    }

    /**
     * @return array<int, string>
     */
    public static function sMembers(string $setKey): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $prefixed = self::prefixKey($setKey);

        if (self::$useInMemory) {
            return array_keys(self::$memorySets[$prefixed] ?? []);
        }

        try {
            if (self::$extClient !== null) {
                $members = self::$extClient->sMembers($prefixed);
                return is_array($members) ? array_values(array_map('strval', $members)) : [];
            }
            $res = self::sendCommand(['SMEMBERS', $prefixed]);
            return is_array($res) ? array_values(array_map('strval', $res)) : [];
        } catch (\Throwable $e) {
            self::recordFailure('SMEMBERS', $e->getMessage());
            self::disconnect();
            return [];
        }
    }

    /**
     * In-memory evaluator mirroring Redis Lua semantics for fixed-window counters and core redis.call ops.
     *
     * @param array<int, string> $prefixedKeys
     * @param array<int, string> $args
     */
    private static function evalInMemory(string $script, array $prefixedKeys, array $args): mixed
    {
        // Pattern 1: Fixed-window rate limiter script:
        // INCR KEYS[1], conditional EXPIRE KEYS[1] ARGV[1], return {current, ttl}
        if (str_contains($script, "redis.call('INCR'") || str_contains($script, 'redis.call("INCR"')) {
            $prefixed = $prefixedKeys[0] ?? '';
            if ($prefixed === '') {
                return null;
            }
            $window = max(1, (int) ($args[0] ?? 60));

            self::evictExpiredMemoryKey($prefixed);
            $current = isset(self::$memoryStrings[$prefixed])
                ? (int) self::$memoryStrings[$prefixed]['value']
                : 0;
            $exp = self::$memoryStrings[$prefixed]['exp'] ?? null;
            $next = $current + 1;
            self::$memoryStrings[$prefixed] = [
                'value' => (string) $next,
                'exp'   => $exp,
            ];

            $ttl = self::memoryTtlRaw($prefixed);
            if ($next === 1 || $ttl < 0) {
                self::$memoryStrings[$prefixed]['exp'] = self::now() + $window;
                $ttl = $window;
            }

            // Also support optional lock key if script writes KEYS[2] when limit exceeded
            if (isset($prefixedKeys[1]) && isset($args[1]) && isset($args[2])) {
                $max = max(1, (int) $args[1]);
                $lockout = max(1, (int) $args[2]);
                if ($next >= $max) {
                    $lockKey = $prefixedKeys[1];
                    self::evictExpiredMemoryKey($lockKey);
                    $existingLock = self::$memoryStrings[$lockKey]['value'] ?? null;
                    $now = self::now();
                    if ($existingLock === null || (int) $existingLock <= $now) {
                        self::$memoryStrings[$lockKey] = [
                            'value' => (string) ($now + $lockout),
                            'exp'   => $now + $lockout,
                        ];
                    }
                }
            }

            return [$next, max(0, $ttl)];
        }

        // Pattern 2: Simple GET / SET / SETEX / DEL single-op Lua scripts
        if (str_contains($script, "redis.call('SETEX'") || str_contains($script, 'redis.call("SETEX"')) {
            $prefixed = $prefixedKeys[0] ?? '';
            $ttl = max(1, (int) ($args[0] ?? 1));
            $value = (string) ($args[1] ?? '');
            self::$memoryStrings[$prefixed] = [
                'value' => $value,
                'exp'   => self::now() + $ttl,
            ];
            return 'OK';
        }

        if (str_contains($script, "redis.call('GET'") || str_contains($script, 'redis.call("GET"')) {
            $prefixed = $prefixedKeys[0] ?? '';
            self::evictExpiredMemoryKey($prefixed);
            return self::$memoryStrings[$prefixed]['value'] ?? null;
        }

        if (str_contains($script, "redis.call('DEL'") || str_contains($script, 'redis.call("DEL"')) {
            $removed = 0;
            foreach ($prefixedKeys as $k) {
                if (isset(self::$memoryStrings[$k])) {
                    unset(self::$memoryStrings[$k]);
                    $removed++;
                }
            }
            return $removed;
        }

        return null;
    }

    private static function memoryTtlRaw(string $prefixedKey): int
    {
        self::evictExpiredMemoryKey($prefixedKey);
        if (!isset(self::$memoryStrings[$prefixedKey])) {
            return -2;
        }
        $exp = self::$memoryStrings[$prefixedKey]['exp'];
        if ($exp === null) {
            return -1;
        }
        return max(0, $exp - self::now());
    }

    private static function now(): int
    {
        return self::$testNow ?? time();
    }

    private static function evictExpiredMemoryKey(string $prefixedKey): void
    {
        if (isset(self::$memoryStrings[$prefixedKey])) {
            $exp = self::$memoryStrings[$prefixedKey]['exp'];
            if ($exp !== null && $exp <= self::now()) {
                unset(self::$memoryStrings[$prefixedKey]);
            }
        }
    }

    private static function prefixKey(string $key): string
    {
        $cfg = self::resolvedConfig();
        $prefix = (string) ($cfg['prefix'] ?? 'famo:');
        if ($prefix !== '' && str_starts_with($key, $prefix)) {
            return $key;
        }
        return $prefix . $key;
    }

    public static function recordFailure(string $context, string $errorMessage): void
    {
        self::$lastError = $errorMessage;
        if (self::$inLogging || !self::isEnabled()) {
            return;
        }

        self::$inLogging = true;
        try {
            $nowFloat = microtime(true);
            $state = self::getDowntimeState();
            $isInitialTransition = $state === null;
            $downSince = $state['down_since'] ?? $nowFloat;
            $lastLoggedAt = (float) ($state['last_logged_at'] ?? 0.0);
            $duration = round(max(0.0, $nowFloat - (float) $downSince), 3);

            $newState = [
                'down_since'     => $downSince,
                'last_error'     => $errorMessage,
                'last_logged_at' => $isInitialTransition || ($nowFloat - $lastLoggedAt >= 60.0) ? $nowFloat : $lastLoggedAt,
            ];
            self::writeDowntimeState($newState);

            // Log immediately on transition or every 60s while still unreachable
            if ($isInitialTransition || ($nowFloat - $lastLoggedAt >= 60.0)) {
                $msg = sprintf(
                    '[%s] [CRITICAL] Redis unreachable (%s): error="%s" downtime_seconds=%.3f',
                    gmdate('Y-m-d H:i:s'),
                    $context,
                    str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $errorMessage),
                    $duration
                );
                self::writeCriticalLog($msg);
            }
        } finally {
            self::$inLogging = false;
        }
    }

    public static function recordRecoveryIfNeeded(): void
    {
        if (self::$inLogging) {
            return;
        }

        $state = self::getDowntimeState();
        if ($state === null) {
            return;
        }

        self::$inLogging = true;
        try {
            $duration = round(max(0.0, microtime(true) - (float) $state['down_since']), 3);
            $lastError = (string) ($state['last_error'] ?? 'unknown');
            $msg = sprintf(
                '[%s] [CRITICAL] Redis recovered: downtime_seconds=%.3f last_error="%s"',
                gmdate('Y-m-d H:i:s'),
                $duration,
                str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $lastError)
            );
            self::writeCriticalLog($msg);
            @unlink(self::downtimeStatePath());
        } finally {
            self::$inLogging = false;
        }
    }

    public static function writeCriticalLog(string $line): void
    {
        error_log($line);
        $logPath = self::$customLogPath ?? (__DIR__ . '/../../storage/logs/app.log');
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir)) {
            @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private static function downtimeStatePath(): string
    {
        if (self::$customDowntimeStatePath !== null) {
            return self::$customDowntimeStatePath;
        }
        return __DIR__ . '/../../storage/cache/redis_downtime.json';
    }

    private static function writeDowntimeState(array $state): void
    {
        $path = self::downtimeStatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir)) {
            @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }

    private static function resolvedConfig(): array
    {
        $envEnabled = $_ENV['REDIS_ENABLED'] ?? getenv('REDIS_ENABLED');
        $envUrl = (string) ($_ENV['REDIS_URL'] ?? getenv('REDIS_URL') ?: '');
        $envHost = (string) ($_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '');

        $enabled = self::$config['enabled'] ?? (
            $envEnabled !== false && $envEnabled !== ''
                ? filter_var($envEnabled, FILTER_VALIDATE_BOOLEAN)
                : ($envUrl !== '' || $envHost !== '')
        );

        $host = (string) (self::$config['host'] ?? ($envHost !== '' ? $envHost : '127.0.0.1'));
        $port = (int) (self::$config['port'] ?? ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379));
        $password = self::$config['password'] ?? ($_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: null);
        $database = (int) (self::$config['database'] ?? ($_ENV['REDIS_DB'] ?? getenv('REDIS_DB') ?: 0));
        $prefix = (string) (self::$config['prefix'] ?? ($_ENV['REDIS_PREFIX'] ?? getenv('REDIS_PREFIX') ?: 'famo:'));
        $timeout = (float) (self::$config['timeout'] ?? ($_ENV['REDIS_TIMEOUT'] ?? getenv('REDIS_TIMEOUT') ?: 1.5));
        $scheme = 'tcp';

        $url = (string) (self::$config['url'] ?? $envUrl);
        if ($url !== '') {
            $parts = parse_url($url);
            if (is_array($parts)) {
                if (!empty($parts['scheme']) && in_array(strtolower($parts['scheme']), ['rediss', 'tls', 'ssl'], true)) {
                    $scheme = 'tls';
                }
                if (!empty($parts['host'])) {
                    $host = $parts['host'];
                }
                if (!empty($parts['port'])) {
                    $port = (int) $parts['port'];
                }
                if (isset($parts['pass']) && $parts['pass'] !== '') {
                    $password = rawurldecode($parts['pass']);
                }
                if (!empty($parts['path'])) {
                    $dbPath = ltrim($parts['path'], '/');
                    if ($dbPath !== '' && ctype_digit($dbPath)) {
                        $database = (int) $dbPath;
                    }
                }
            }
        }

        return [
            'enabled'  => (bool) $enabled,
            'scheme'   => $scheme,
            'host'     => $host,
            'port'     => $port > 0 ? $port : 6379,
            'password' => $password !== '' ? $password : null,
            'database' => max(0, $database),
            'prefix'   => $prefix,
            'timeout'  => $timeout > 0 ? $timeout : 1.5,
        ];
    }

    private static function connect(array $cfg): bool
    {
        // 1. Prefer native ext-redis when available
        if (extension_loaded('redis') && class_exists(\Redis::class)) {
            try {
                $redis = new \Redis();
                $host = $cfg['scheme'] === 'tls' ? 'tls://' . $cfg['host'] : $cfg['host'];
                $ok = @$redis->connect($host, (int) $cfg['port'], (float) $cfg['timeout']);
                if (!$ok) {
                    $err = $redis->getLastError() ?: "Unable to connect to Redis {$host}:{$cfg['port']}";
                    self::recordFailure('connect:ext-redis', $err);
                    return false;
                }
                if ($cfg['password'] !== null && $cfg['password'] !== '') {
                    if (!@$redis->auth((string) $cfg['password'])) {
                        $err = $redis->getLastError() ?: 'Redis AUTH failed';
                        self::recordFailure('auth:ext-redis', $err);
                        return false;
                    }
                }
                if ($cfg['database'] > 0) {
                    if (!@$redis->select((int) $cfg['database'])) {
                        $err = $redis->getLastError() ?: 'Redis SELECT failed';
                        self::recordFailure('select:ext-redis', $err);
                        return false;
                    }
                }
                self::$extClient = $redis;
                return true;
            } catch (\Throwable $e) {
                self::$extClient = null;
                self::recordFailure('connect:ext-redis', $e->getMessage());
                return false;
            }
        }

        // 2. Pure-PHP RESP2 socket connection fallback
        try {
            $uri = sprintf('%s://%s:%d', $cfg['scheme'], $cfg['host'], $cfg['port']);
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client($uri, $errno, $errstr, (float) $cfg['timeout']);
            if (!is_resource($sock)) {
                self::recordFailure('connect:socket', $errstr !== '' ? "{$errstr} ({$errno})" : "Socket connection failed ({$errno})");
                return false;
            }
            $sec = (int) floor((float) $cfg['timeout']);
            $usec = (int) (((float) $cfg['timeout'] - $sec) * 1_000_000);
            stream_set_timeout($sock, max(1, $sec), max(0, $usec));
            self::$socket = $sock;

            if ($cfg['password'] !== null && $cfg['password'] !== '') {
                $authRes = self::sendCommand(['AUTH', (string) $cfg['password']]);
                if ($authRes !== 'OK') {
                    self::recordFailure('auth:socket', 'Redis AUTH failed');
                    self::disconnect();
                    return false;
                }
            }
            if ($cfg['database'] > 0) {
                $selRes = self::sendCommand(['SELECT', (string) $cfg['database']]);
                if ($selRes !== 'OK') {
                    self::recordFailure('select:socket', 'Redis SELECT failed');
                    self::disconnect();
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            self::recordFailure('connect:socket', $e->getMessage());
            self::disconnect();
            return false;
        }
    }

    private static function disconnect(): void
    {
        if (self::$extClient !== null) {
            try {
                @self::$extClient->close();
            } catch (\Throwable) {
            }
            self::$extClient = null;
        }
        if (is_resource(self::$socket)) {
            @fclose(self::$socket);
        }
        self::$socket = null;
        self::$connected = null;
    }

    /**
     * Send a RESP2 command over the socket and parse the reply.
     *
     * @param array<int, string> $args
     */
    private static function sendCommand(array $args): mixed
    {
        if (!is_resource(self::$socket)) {
            throw new \RuntimeException('Redis socket is not connected.');
        }

        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $str = (string) $arg;
            $payload .= '$' . strlen($str) . "\r\n" . $str . "\r\n";
        }

        $written = @fwrite(self::$socket, $payload);
        if ($written === false || $written < strlen($payload)) {
            self::disconnect();
            throw new \RuntimeException('Failed to write to Redis socket.');
        }

        return self::readReply();
    }

    private static function readReply(): mixed
    {
        $line = @fgets(self::$socket);
        if ($line === false || $line === '') {
            self::disconnect();
            throw new \RuntimeException('Empty response from Redis socket.');
        }

        $prefix = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");

        return match ($prefix) {
            '+' => $body,
            '-' => throw new \RuntimeException('Redis error: ' . $body),
            ':' => (int) $body,
            '$' => self::readBulkString((int) $body),
            '*' => self::readArray((int) $body),
            default => throw new \RuntimeException('Unknown RESP reply prefix: ' . $prefix),
        };
    }

    private static function readBulkString(int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        $data = '';
        $remaining = $length + 2; // include trailing CRLF
        while ($remaining > 0) {
            $chunk = @fread(self::$socket, $remaining);
            if ($chunk === false || $chunk === '') {
                self::disconnect();
                throw new \RuntimeException('Unexpected EOF reading bulk string from Redis.');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return substr($data, 0, $length);
    }

    /**
     * @return array<int, mixed>|null
     */
    private static function readArray(int $count): ?array
    {
        if ($count < 0) {
            return null;
        }
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = self::readReply();
        }
        return $items;
    }
}
