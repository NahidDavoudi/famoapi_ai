<?php
declare(strict_types=1);

namespace App\Core;

/**
 * کش دو لایه: Redis (در صورت فعال و در دسترس بودن) با fallback خودکار به کش فایلی
 * ساختار فایل: {dir}/{group}/{md5(key)}.cache
 * ساختار کلید Redis: cache:{group}:{md5(key)} و ست گروه: cache:group:{group}
 */
class Cache
{
    private static string $dir = '';
    private static bool $enabled = true;

    public static function configure(string $dir, bool $enabled = true): void
    {
        self::$dir = rtrim($dir, '/\\');
        self::$enabled = $enabled;

        if ($enabled && self::$dir !== '' && !is_dir(self::$dir)) {
            @mkdir(self::$dir, 0775, true);
        }
    }

    public static function isUsingRedis(): bool
    {
        return self::$enabled && RedisClient::isAvailable();
    }

    /** کش hit → مقدار کش‌شده؛ miss → اجرای callback و ذخیره. نتیجه‌ی null هرگز ذخیره نمی‌شود. */
    public static function remember(string $group, string $key, int $ttl, callable $callback): mixed
    {
        if (!self::$enabled) {
            return $callback();
        }

        $hit = self::read($group, $key);
        if ($hit !== null) {
            return $hit['value'];
        }

        $value = $callback();

        if ($value !== null) {
            self::write($group, $key, $value, $ttl);
        }

        return $value;
    }

    public static function forget(string $group, string $key): void
    {
        if (RedisClient::isAvailable()) {
            RedisClient::del(self::redisKey($group, $key));
        }
        if (self::$dir !== '') {
            @unlink(self::path($group, $key));
        }
    }

    public static function flushGroup(string $group): void
    {
        if ($group === 'public') {
            foreach ([
                'supporters',
                'instructors',
                'courses',
                'http_response:/api/v1/public/supporters',
                'http_response:/api/v1/public/instructors',
                'http_response:/api/v1/public/courses',
                'http_response:/api/v1/public/blog/categories',
            ] as $knownKey) {
                self::forget($group, $knownKey);
            }
        }

        if (RedisClient::isAvailable()) {
            $groupSetKey = self::redisGroupSetKey($group);
            $members = RedisClient::sMembers($groupSetKey);
            if ($members !== []) {
                RedisClient::del($groupSetKey, ...$members);
            } else {
                RedisClient::del($groupSetKey);
            }
        }

        if (self::$dir === '') {
            return;
        }
        $dir = self::groupDir($group);
        if (!is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (str_ends_with($entry, '.cache') || str_ends_with($entry, '.tmp')) {
                    @unlink($dir . '/' . $entry);
                }
            }
        } else {
            foreach (@glob($dir . '/*.cache') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    public static function purgeExpired(): int
    {
        if (self::$dir === '') {
            return 0;
        }
        $removed = 0;
        foreach (glob(self::$dir . '/*/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $data = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($data) || $data['exp'] < time()) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    private static function read(string $group, string $key): ?array
    {
        if (RedisClient::isAvailable()) {
            $raw = RedisClient::get(self::redisKey($group, $key));
            if ($raw !== null) {
                $data = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($data) && array_key_exists('value', $data)) {
                    if (!isset($data['exp']) || $data['exp'] >= time()) {
                        return $data;
                    }
                    RedisClient::del(self::redisKey($group, $key));
                }
            }
            return null;
        }

        if (self::$dir === '') {
            return null;
        }

        $file = self::path($group, $key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $data = $raw === false ? null : @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data) || !isset($data['exp']) || $data['exp'] < time()) {
            @unlink($file);
            return null;
        }

        return $data;
    }

    private static function write(string $group, string $key, mixed $value, int $ttl): void
    {
        $ttl = max(1, $ttl);
        $payload = serialize(['exp' => time() + $ttl, 'value' => $value]);

        if (RedisClient::isAvailable()) {
            $rKey = self::redisKey($group, $key);
            $groupSetKey = self::redisGroupSetKey($group);
            if (RedisClient::setex($rKey, $ttl, $payload)) {
                RedisClient::sAdd($groupSetKey, $rKey);
                RedisClient::expire($groupSetKey, max($ttl, 86400));
                return;
            }
        }

        if (self::$dir === '') {
            return;
        }

        $dir = self::groupDir($group);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // شکست در نوشتن کش نباید برنامه را خراب کند
        }

        $file = self::path($group, $key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $file); // نوشتن اتمیک
        }
    }

    private static function normalizeGroup(string $group): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $group);
    }

    private static function redisKey(string $group, string $key): string
    {
        return 'cache:' . self::normalizeGroup($group) . ':' . md5($key);
    }

    private static function redisGroupSetKey(string $group): string
    {
        return 'cache:group:' . self::normalizeGroup($group);
    }

    private static function groupDir(string $group): string
    {
        return self::$dir . '/' . self::normalizeGroup($group);
    }

    private static function path(string $group, string $key): string
    {
        return self::groupDir($group) . '/' . md5($key) . '.cache';
    }
}
