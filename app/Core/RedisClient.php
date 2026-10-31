<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Redis client supporting:
 * 1. Native PHP ext-redis (\Redis) when available
 * 2. Pure-PHP RESP2 stream socket client when ext-redis is not installed
 * 3. In-memory driver for unit testing without an external Redis daemon
 */
final class RedisClient
{
    private static ?\Redis $extClient = null;
    /** @var resource|null */
    private static $socket = null;
    private static ?bool $connected = null;
    private static bool $useInMemory = false;

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
        self::$memoryStrings = [];
        self::$memorySets = [];
        self::$connected = $enable ? true : null;
    }

    public static function reset(): void
    {
        self::disconnect();
        self::$useInMemory = false;
        self::$memoryStrings = [];
        self::$memorySets = [];
        self::$config = [];
    }

    public static function isAvailable(): bool
    {
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
        return self::$connected;
    }

    public static function ping(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        if (self::$useInMemory) {
            return true;
        }

        try {
            if (self::$extClient !== null) {
                $res = @self::$extClient->ping();
                if ($res === true || $res === '+PONG' || $res === 'PONG') {
                    return true;
                }
                self::disconnect();
                return false;
            }

            $res = self::sendCommand(['PING']);
            if ($res === 'PONG' || $res === true) {
                return true;
            }
            self::disconnect();
            return false;
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
                'exp'   => time() + $ttl,
            ];
            return true;
        }

        try {
            if (self::$extClient !== null) {
                return (bool) self::$extClient->setex($prefixed, $ttl, $value);
            }
            $res = self::sendCommand(['SETEX', $prefixed, (string) $ttl, $value]);
            return $res === 'OK' || $res === true;
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
                self::$memoryStrings[$prefixed]['exp'] = time() + $ttl;
                return true;
            }
            return false;
        }

        try {
            if (self::$extClient !== null) {
                return (bool) self::$extClient->expire($prefixed, $ttl);
            }
            return (int) self::sendCommand(['EXPIRE', $prefixed, (string) $ttl]) === 1;
        } catch (\Throwable) {
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
            return max(0, $exp - time());
        }

        try {
            if (self::$extClient !== null) {
                return (int) self::$extClient->ttl($prefixed);
            }
            return (int) self::sendCommand(['TTL', $prefixed]);
        } catch (\Throwable) {
            return -2;
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            return [];
        }
    }

    private static function evictExpiredMemoryKey(string $prefixedKey): void
    {
        if (isset(self::$memoryStrings[$prefixedKey])) {
            $exp = self::$memoryStrings[$prefixedKey]['exp'];
            if ($exp !== null && $exp <= time()) {
                unset(self::$memoryStrings[$prefixedKey]);
            }
        }
    }

    private static function prefixKey(string $key): string
    {
        $cfg = self::resolvedConfig();
        $prefix = (string) ($cfg['prefix'] ?? 'famo:');
        return $prefix . $key;
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
                    return false;
                }
                if ($cfg['password'] !== null && $cfg['password'] !== '') {
                    if (!@$redis->auth((string) $cfg['password'])) {
                        return false;
                    }
                }
                if ($cfg['database'] > 0) {
                    if (!@$redis->select((int) $cfg['database'])) {
                        return false;
                    }
                }
                self::$extClient = $redis;
                return true;
            } catch (\Throwable) {
                self::$extClient = null;
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
                return false;
            }
            $sec = (int) floor((float) $cfg['timeout']);
            $usec = (int) (((float) $cfg['timeout'] - $sec) * 1_000_000);
            stream_set_timeout($sock, max(1, $sec), max(0, $usec));
            self::$socket = $sock;

            if ($cfg['password'] !== null && $cfg['password'] !== '') {
                $authRes = self::sendCommand(['AUTH', (string) $cfg['password']]);
                if ($authRes !== 'OK') {
                    self::disconnect();
                    return false;
                }
            }
            if ($cfg['database'] > 0) {
                $selRes = self::sendCommand(['SELECT', (string) $cfg['database']]);
                if ($selRes !== 'OK') {
                    self::disconnect();
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
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
