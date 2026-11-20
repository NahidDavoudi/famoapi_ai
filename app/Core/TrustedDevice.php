<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Manages optional student 2FA policy (`STUDENT_2FA_MODE=off|new_device|always`)
 * and per-user trusted-device cookies (`famo_td`) for `new_device` mode.
 *
 * Security invariants:
 * - Cookie holds a random 256-bit token ID (64 hex chars) marked `HttpOnly`.
 * - Only an HMAC-SHA256 hash of the token is stored in Redis under
 *   `trusted_device:{user_id}:{hash}` with a 30-day TTL (2,592,000 seconds).
 * - Trust records are strictly scoped per user ID so one student's trusted
 *   browser cookie can never bypass 2FA for another student.
 */
final class TrustedDevice
{
    public const MODE_OFF        = 'off';
    public const MODE_NEW_DEVICE = 'new_device';
    public const MODE_ALWAYS     = 'always';

    public const VALID_MODES = [
        self::MODE_OFF,
        self::MODE_NEW_DEVICE,
        self::MODE_ALWAYS,
    ];

    public const TTL_SECONDS = 2592000; // 30 days

    public static function mode(): string
    {
        $raw = $_ENV['STUDENT_2FA_MODE'] ?? getenv('STUDENT_2FA_MODE');
        $normalized = is_string($raw) ? strtolower(trim($raw)) : '';
        if (in_array($normalized, self::VALID_MODES, true)) {
            return $normalized;
        }

        return self::MODE_OFF;
    }

    public static function cookieName(): string
    {
        $raw = trim((string) ($_ENV['TRUSTED_DEVICE_COOKIE_NAME'] ?? getenv('TRUSTED_DEVICE_COOKIE_NAME') ?: 'famo_td'));
        return $raw !== '' ? $raw : 'famo_td';
    }

    public static function tokenFromRequest(Request $request): ?string
    {
        $raw = $request->getCookieParams()[self::cookieName()] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        return preg_match('/^[0-9a-f]{64}$/', $trimmed) === 1 ? $trimmed : null;
    }

    public static function isTrusted(int $userId, ?string $rawToken): bool
    {
        if ($userId <= 0 || $rawToken === null || preg_match('/^[0-9a-f]{64}$/', $rawToken) !== 1) {
            return false;
        }

        if (!RedisClient::isAvailable()) {
            return false;
        }

        $hash = self::hashToken($userId, $rawToken);
        return RedisClient::get(self::redisKey($userId, $hash)) !== null;
    }

    public static function issueForUser(int $userId, Response $response, Request $request): Response
    {
        if ($userId <= 0 || !RedisClient::isAvailable()) {
            return $response;
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = self::hashToken($userId, $rawToken);
        $stored = RedisClient::setex(self::redisKey($userId, $hash), self::TTL_SECONDS, (string) time());
        if (!$stored) {
            return $response;
        }

        return $response->withAddedHeader(
            'Set-Cookie',
            self::cookieHeader($request, $rawToken, time() + self::TTL_SECONDS, self::TTL_SECONDS)
        );
    }

    public static function clearCookie(Response $response, Request $request): Response
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::cookieHeader($request, '', 1, 0)
        );
    }

    public static function redisKey(int $userId, string $tokenHash): string
    {
        return 'trusted_device:' . $userId . ':' . $tokenHash;
    }

    public static function hashToken(int $userId, string $rawToken): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';
        $key = is_string($secret) && trim($secret) !== ''
            ? hash_hmac('sha256', 'famo:trusted_device:v1', trim($secret))
            : hash('sha256', 'famo-trusted-device-fallback');

        return hash_hmac('sha256', $userId . '|' . $rawToken, $key);
    }

    private static function cookieHeader(Request $request, string $value, int $expires, int $maxAge): string
    {
        $name = self::cookieName();
        $cookie = rawurlencode($name) . '=' . rawurlencode($value);
        $cookie .= '; Path=/; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age=' . $maxAge;

        $host = strtolower($request->getUri()->getHost());
        $domain = trim((string) ($_ENV['JWT_COOKIE_DOMAIN'] ?? ''));
        if ($domain === '' && preg_match('/(^|\.)famoacademy\.ir$/i', $host)) {
            $domain = '.famoacademy.ir';
        }
        if ($domain !== '') {
            $cookie .= '; Domain=' . $domain;
        }

        $origin = parse_url($request->getHeaderLine('Origin'));
        $originHost = strtolower((string) ($origin['host'] ?? ''));
        $localHostRequest = in_array($originHost, ['localhost', '127.0.0.1'], true)
            && in_array($host, ['localhost', '127.0.0.1'], true);
        $cookieIsCrossSite = !$localHostRequest
            && $originHost !== ''
            && !str_ends_with($originHost, '.famoacademy.ir')
            && !str_ends_with($host, '.localhost');

        $sameSiteSetting = trim((string) ($_ENV['JWT_COOKIE_SAMESITE'] ?? ''));
        $sameSite = ucfirst(strtolower($sameSiteSetting !== '' ? $sameSiteSetting : ($cookieIsCrossSite ? 'None' : 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        $secureSettingPresent = array_key_exists('JWT_COOKIE_SECURE', $_ENV)
            && trim((string) $_ENV['JWT_COOKIE_SECURE']) !== '';
        $secureSetting = trim((string) ($_ENV['JWT_COOKIE_SECURE'] ?? ''));
        $secure = $secureSettingPresent
            ? filter_var($secureSetting, FILTER_VALIDATE_BOOLEAN)
            : ($domain !== '' || strtolower($request->getUri()->getScheme()) === 'https' || $cookieIsCrossSite);
        $secure = $secure || $sameSite === 'None';
        if ($secure) {
            $cookie .= '; Secure';
        }

        $cookie .= '; HttpOnly';
        $cookie .= '; SameSite=' . $sameSite;

        return $cookie;
    }
}
