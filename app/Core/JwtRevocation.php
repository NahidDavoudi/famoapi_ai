<?php
declare(strict_types=1);

namespace App\Core;

use Firebase\JWT\ExpiredException;

/**
 * Redis-backed JWT revocation manager supporting:
 * 1. Per-token blacklisting (`jwt:bl:{jti}`) on logout with TTL = remaining token lifetime.
 * 2. Per-user session epoch (`jwt:user_epoch:{user_id}`) with TTL = JWT_TTL on password change,
 *    admin password reset, and account deactivation.
 *
 * All keys are passed unprefixed to RedisClient so REDIS_PREFIX (e.g. `famo:`) is applied once.
 *
 * Same-second edge case (`iat < epoch`):
 * Unix timestamps have 1-second resolution. Using strict `<` (`$iat < $epoch`) ensures a fresh
 * session token issued immediately after `revokeAllForUser()` (e.g. in `change-password`) within
 * the same second (`iat === epoch`) remains accepted, while all older tokens (`iat <= epoch - 1`)
 * are rejected. Any earlier token minted in the same second *before* revocation would share `iat === epoch`;
 * shorter access TTLs or sub-second microsecond claims can close that 1s window if needed later.
 */
final class JwtRevocation
{
    public static function blacklistKey(string $jti): string
    {
        return 'jwt:bl:' . $jti;
    }

    public static function userEpochKey(int $userId): string
    {
        return 'jwt:user_epoch:' . $userId;
    }

    /**
     * Blacklist a raw encoded JWT string until its expiration.
     * Expired or malformed tokens are ignored safely so logout never fails.
     */
    public static function blacklistToken(string $token, ?int $now = null): bool
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return false;
        }

        try {
            $claims = Auth::decode($trimmed);
        } catch (ExpiredException) {
            return true;
        } catch (\Throwable) {
            return false;
        }

        return self::blacklistClaims($claims, $now);
    }

    /**
     * Blacklist already-decoded JWT claims (`jti`) for the remaining lifetime of the token.
     */
    public static function blacklistClaims(object $claims, ?int $now = null): bool
    {
        $jti = isset($claims->jti) && is_string($claims->jti) ? trim($claims->jti) : '';
        if ($jti === '') {
            return false;
        }

        $current = $now ?? time();
        $exp = isset($claims->exp) ? (int) $claims->exp : ($current + 60);
        $ttl = max(1, $exp - $current);

        if (!RedisClient::isAvailable()) {
            self::logRedisUnavailable('blacklistClaims', 'non-admin');
            return false;
        }

        return RedisClient::setex(self::blacklistKey($jti), $ttl, '1');
    }

    /**
     * Revoke all active tokens for a user issued prior to the current second.
     * TTL matches JWT_TTL so the epoch key self-cleans once any prior token has naturally expired.
     */
    public static function revokeAllForUser(int $userId, ?int $now = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $epoch = $now ?? time();
        $jwtTtl = max(1, (int) ($_ENV['JWT_TTL'] ?? getenv('JWT_TTL') ?: 86400));

        if (!RedisClient::isAvailable()) {
            self::logRedisUnavailable('revokeAllForUser', 'user_' . $userId);
            return false;
        }

        return RedisClient::setex(self::userEpochKey($userId), $jwtTtl, (string) $epoch);
    }

    /**
     * Determine whether decoded JWT claims have been revoked.
     */
    public static function isRevoked(object $claims): bool
    {
        $role = strtolower((string) ($claims->role ?? ''));

        if (!RedisClient::isAvailable()) {
            return self::applyFailMode('isRevoked', $role);
        }

        $jti = isset($claims->jti) && is_string($claims->jti) ? trim($claims->jti) : '';
        if ($jti !== '') {
            $blVal = RedisClient::get(self::blacklistKey($jti));
            if (!RedisClient::isAvailable()) {
                return self::applyFailMode('isRevoked:blacklist', $role);
            }
            if ($blVal !== null) {
                return true;
            }
        }

        $sub = isset($claims->sub) ? (int) $claims->sub : 0;
        if ($sub > 0) {
            $epochRaw = RedisClient::get(self::userEpochKey($sub));
            if (!RedisClient::isAvailable()) {
                return self::applyFailMode('isRevoked:epoch', $role);
            }
            if ($epochRaw !== null) {
                $epoch = (int) $epochRaw;
                $iat = isset($claims->iat) ? (int) $claims->iat : 0;
                if ($iat < $epoch) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function resolveFailMode(string $role): string
    {
        $normalizedRole = strtolower(trim($role));
        if ($normalizedRole === 'admin') {
            $raw = $_ENV['AUTH_REVOCATION_ADMIN_FAIL_MODE']
                ?? getenv('AUTH_REVOCATION_ADMIN_FAIL_MODE')
                ?: ($_ENV['AUTH_REVOCATION_FAIL_MODE'] ?? getenv('AUTH_REVOCATION_FAIL_MODE') ?: 'closed');
            $mode = strtolower(trim((string) $raw));
            return $mode === 'open' ? 'open' : 'closed';
        }

        $raw = $_ENV['AUTH_REVOCATION_FAIL_MODE'] ?? getenv('AUTH_REVOCATION_FAIL_MODE') ?: 'open';
        $mode = strtolower(trim((string) $raw));
        return $mode === 'closed' ? 'closed' : 'open';
    }

    private static function applyFailMode(string $context, string $role): bool
    {
        $mode = self::resolveFailMode($role);
        self::logRedisUnavailable($context, $role !== '' ? $role : 'unknown', $mode);
        return $mode === 'closed';
    }

    private static function logRedisUnavailable(string $context, string $role, ?string $failMode = null): void
    {
        $reason = RedisClient::getLastError()
            ?? (RedisClient::isEnabled() ? 'Redis connection unavailable' : 'Redis disabled');
        $modeLabel = $failMode !== null ? " fail_mode={$failMode}" : '';
        $line = sprintf(
            '[%s] [%s] JwtRevocation::%s skipped/triggered while Redis unavailable (role=%s%s): %s',
            gmdate('Y-m-d H:i:s'),
            $failMode === 'closed' ? 'CRITICAL' : 'WARNING',
            $context,
            $role,
            $modeLabel,
            $reason
        );

        RedisClient::recordFailure('JwtRevocation::' . $context, $reason);
        RedisClient::writeCriticalLog($line);
    }
}
