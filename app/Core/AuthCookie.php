<?php

namespace App\Core;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthCookie
{
    public static function name(): string
    {
        return trim((string) ($_ENV['JWT_COOKIE_NAME'] ?? 'famo_jwt')) ?: 'famo_jwt';
    }

    public static function tokenFromRequest(Request $request): ?string
    {
        $token = $request->getCookieParams()[self::name()] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function isPresent(Request $request): bool
    {
        return self::tokenFromRequest($request) !== null;
    }

    public static function allowsCookieWrite(Request $request): bool
    {
        $origin = trim($request->getHeaderLine('Origin'));
        return self::isOriginAllowed($origin);
    }

    public static function rawCorsOrigins(): string
    {
        $candidates = [
            $_ENV['CORS_ORIGINS'] ?? null,
            $_SERVER['CORS_ORIGINS'] ?? null,
            getenv('CORS_ORIGINS') ?: null,
            $_ENV['CORS_ALLOWED_ORIGINS'] ?? null,
            $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null,
            getenv('CORS_ALLOWED_ORIGINS') ?: null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    public static function allowedOrigins(): array
    {
        $origins = array_values(array_filter(array_map(
            static fn (string $item): string => rtrim(trim($item), '/'),
            explode(',', self::rawCorsOrigins())
        )));
        if ($origins === []) {
            $origins = [
                'https://famoacademy.ir',
                'https://www.famoacademy.ir',
                'https://admin.famoacademy.ir',
                'https://dash.famoacademy.ir',
                'https://auth.famoacademy.ir',
                'http://localhost',
                'http://localhost:3000',
                'http://localhost:5173',
                'http://127.0.0.1',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:5173',
            ];
        }

        return $origins;
    }

    public static function isOriginAllowed(string $origin): bool
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '' || preg_match('/\s/', $origin) === 1) {
            return false;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('path', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || (strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) === 1);
        if (!$validHost) {
            return false;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        $normalizedOrigin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $originWithoutPort = $scheme . '://' . $host;

        foreach (self::allowedOrigins() as $allowedItem) {
            $allowed = strtolower(rtrim(trim($allowedItem), '/'));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === '*') {
                return !($scheme === 'http' && ($host === 'famoacademy.ir' || str_ends_with($host, '.famoacademy.ir')));
            }
            if ($allowed === $normalizedOrigin) {
                return true;
            }
            if (($allowed === 'http://localhost' || $allowed === 'http://127.0.0.1') && $allowed === $originWithoutPort) {
                return true;
            }
            if (str_contains($allowed, '*.')) {
                $pattern = '#^' . str_replace('\*\.', '([a-z0-9-]+\.)+', preg_quote($allowed, '#')) . '$#i';
                if (preg_match($pattern, $normalizedOrigin) === 1) {
                    return true;
                }
            }
        }

        // Always allow HTTPS on famoacademy.ir and its subdomains
        if ($scheme === 'https' && ($host === 'famoacademy.ir' || str_ends_with($host, '.famoacademy.ir'))) {
            return true;
        }

        // Allow localhost development origins
        if (in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.localhost')) {
            return true;
        }

        return false;
    }

    public static function issue(Response $response, Request $request, string $token): Response
    {
        $ttl = max(1, (int) ($_ENV['JWT_TTL'] ?? 86400));
        return $response->withAddedHeader('Set-Cookie', self::cookieHeader($request, $token, time() + $ttl, $ttl));
    }

    public static function clear(Response $response, Request $request): Response
    {
        return $response->withAddedHeader('Set-Cookie', self::cookieHeader($request, '', 1, 0));
    }

    private static function cookieHeader(Request $request, string $value, int $expires, int $maxAge): string
    {
        $name = self::name();
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
        $sameSiteOverride = array_key_exists('JWT_COOKIE_SAMESITE', $_ENV)
            && trim((string) $_ENV['JWT_COOKIE_SAMESITE']) !== '';
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
        // SameSite=None is rejected by browsers unless the cookie is Secure.
        $secure = $secure || $sameSite === 'None';
        if ($secure) {
            $cookie .= '; Secure';
        }

        $cookie .= '; HttpOnly';
        $cookie .= '; SameSite=' . $sameSite;

        return $cookie;
    }
}
