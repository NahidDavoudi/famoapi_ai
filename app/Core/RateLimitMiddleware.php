<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redis-backed fixed-window rate limiting middleware supporting:
 * - ip: public/unauthenticated per-IP limits (defaults to 120/min)
 * - auth_user: per-user limits after AuthMiddleware (120/min student, 300/min admin/supporter/teacher)
 * - auth_attempt: 5/min per (IP + identifier) + 100/15min per IP (shared Wi-Fi/CGNAT safe)
 * - sms_send: 3/10min per phone (when present) + 10/hour per IP
 *
 * Exempts `/api/v1/bot/*` from per-IP limits and fails open with a warning log if Redis is down/disabled.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public const MODE_IP           = 'ip';
    public const MODE_AUTH_USER    = 'auth_user';
    public const MODE_AUTH_ATTEMPT = 'auth_attempt';
    public const MODE_SMS_SEND     = 'sms_send';

    public function __construct(
        private string $scope = 'public:ip',
        private ?int $maxAttempts = null,
        private ?int $windowSeconds = null,
        private string $mode = self::MODE_IP,
        private ?string $onlyWhenBodyKeyTrue = null
    ) {
    }

    public static function forPublicIp(): self
    {
        return new self('public:ip', null, null, self::MODE_IP);
    }

    public static function forPublicExam(): self
    {
        $cfg = RateLimiter::config();
        return new self(
            'public:exam_result:ip',
            (int) ($cfg['public_exam_ip_max'] ?? 20),
            (int) ($cfg['public_exam_ip_window'] ?? 60),
            self::MODE_IP
        );
    }

    public static function forAuthenticatedUser(): self
    {
        return new self('api:user', null, null, self::MODE_AUTH_USER);
    }

    public static function forAuthAttempt(): self
    {
        return new self('auth:attempt', null, null, self::MODE_AUTH_ATTEMPT);
    }

    public static function forSmsEndpoint(?string $onlyWhenBodyKeyTrue = null): self
    {
        return new self('sms:endpoint', null, null, self::MODE_SMS_SEND, $onlyWhenBodyKeyTrue);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cfg = RateLimiter::config();
        $path = $request->getUri()->getPath();

        // Exempt server-to-server bot gateway routes from per-IP limits.
        foreach ((array) ($cfg['exempt_prefixes'] ?? ['/api/v1/bot']) as $prefix) {
            if ($prefix !== '' && str_starts_with($path, (string) $prefix)) {
                return $handler->handle($request);
            }
        }

        if ($this->onlyWhenBodyKeyTrue !== null) {
            $body = $request->getParsedBody();
            if (!is_array($body) || empty($body[$this->onlyWhenBodyKeyTrue])) {
                return $handler->handle($request);
            }
        }

        $ip = ClientIp::fromRequest($request) ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $result = match ($this->mode) {
            self::MODE_AUTH_USER    => $this->checkAuthUser($request, $cfg, $ip),
            self::MODE_AUTH_ATTEMPT => $this->checkAuthAttempt($request, $cfg, $ip),
            self::MODE_SMS_SEND     => $this->checkSmsSend($request, $cfg, $ip),
            default                 => $this->checkPublicIp($cfg, $ip),
        };

        if ($result === null) {
            return $handler->handle($request);
        }

        if (!$result['allowed']) {
            throw new RateLimitedException($result['retry_after'] > 0 ? $result['retry_after'] : 60);
        }

        $response = $handler->handle($request);

        if (!empty($result['degraded'])) {
            return $response;
        }

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{allowed: bool, retry_after: int, limit: int, remaining: int, degraded: bool}|null
     */
    private function checkPublicIp(array $cfg, string $ip): ?array
    {
        $max = $this->maxAttempts ?? (int) ($cfg['public_ip_max'] ?? 120);
        $window = $this->windowSeconds ?? (int) ($cfg['public_ip_window'] ?? 60);

        if ($max <= 0) {
            return null;
        }

        return RateLimiter::attemptFixedWindow(RateLimiter::key($this->scope, $ip), $max, $window);
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{allowed: bool, retry_after: int, limit: int, remaining: int, degraded: bool}|null
     */
    private function checkAuthUser(ServerRequestInterface $request, array $cfg, string $ip): ?array
    {
        $decoded = $request->getAttribute('user');
        $userId = is_object($decoded) && isset($decoded->sub) ? (int) $decoded->sub : 0;
        $role = is_object($decoded) && isset($decoded->role) ? strtolower(trim((string) $decoded->role)) : 'student';

        $window = $this->windowSeconds ?? (int) ($cfg['api_auth_window'] ?? 60);
        $staffRoles = ['admin', 'supporter', 'teacher'];
        $max = $this->maxAttempts ?? (
            in_array($role, $staffRoles, true)
                ? (int) ($cfg['api_staff_max'] ?? 300)
                : (int) ($cfg['api_student_max'] ?? 120)
        );

        if ($max <= 0) {
            return null;
        }

        $identifier = $userId > 0 ? "uid:{$userId}" : "ip:{$ip}";
        return RateLimiter::attemptFixedWindow(RateLimiter::key($this->scope, $identifier), $max, $window);
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{allowed: bool, retry_after: int, limit: int, remaining: int, degraded: bool}|null
     */
    private function checkAuthAttempt(ServerRequestInterface $request, array $cfg, string $ip): ?array
    {
        $ipMax = (int) ($cfg['auth_ip_max'] ?? 100);
        $ipWindow = (int) ($cfg['auth_ip_window'] ?? 900);
        $ipStatus = RateLimiter::attemptFixedWindow(RateLimiter::key('auth:ip', $ip), $ipMax, $ipWindow);
        if (!$ipStatus['allowed']) {
            return $ipStatus;
        }

        $body = $request->getParsedBody();
        $identifier = '';
        if (is_array($body)) {
            $rawId = $body['username']
                ?? $body['phone']
                ?? $body['user_id']
                ?? $body['challenge_id']
                ?? $body['token']
                ?? $body['reset_token']
                ?? '';
            if (is_scalar($rawId) && trim((string) $rawId) !== '') {
                $trimmed = trim((string) $rawId);
                $identifier = PhoneNumber::normalize($trimmed) ?? strtolower($trimmed);
            }
        }

        if ($identifier !== '') {
            $userIpMax = $this->maxAttempts ?? (int) ($cfg['auth_user_ip_max'] ?? 5);
            $userIpWindow = $this->windowSeconds ?? (int) ($cfg['auth_user_ip_window'] ?? 60);
            $userIpStatus = RateLimiter::attemptFixedWindow(
                RateLimiter::key('auth:ip_id', $ip . '|' . $identifier),
                $userIpMax,
                $userIpWindow
            );
            if (!$userIpStatus['allowed']) {
                return $userIpStatus;
            }
            return $userIpStatus;
        }

        return $ipStatus;
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{allowed: bool, retry_after: int, limit: int, remaining: int, degraded: bool}|null
     */
    private function checkSmsSend(ServerRequestInterface $request, array $cfg, string $ip): ?array
    {
        $ipMax = $this->maxAttempts ?? (int) ($cfg['sms_ip_max'] ?? 10);
        $ipWindow = $this->windowSeconds ?? (int) ($cfg['sms_ip_window'] ?? 3600);
        $ipStatus = RateLimiter::attemptFixedWindow(RateLimiter::key('sms:ip_win', $ip), $ipMax, $ipWindow);
        if (!$ipStatus['allowed']) {
            return $ipStatus;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['phone']) && is_scalar($body['phone']) && trim((string) $body['phone']) !== '') {
            $rawPhone = trim((string) $body['phone']);
            $normalizedPhone = PhoneNumber::normalize($rawPhone) ?? $rawPhone;
            $phoneMax = (int) ($cfg['sms_phone_max'] ?? 3);
            $phoneWindow = (int) ($cfg['sms_phone_window'] ?? 600);
            $phoneStatus = RateLimiter::attemptFixedWindow(
                RateLimiter::key('sms:phone_win', $normalizedPhone),
                $phoneMax,
                $phoneWindow
            );
            if (!$phoneStatus['allowed']) {
                return $phoneStatus;
            }
            return $phoneStatus;
        }

        return $ipStatus;
    }
}
