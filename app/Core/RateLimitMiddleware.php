<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redis-backed (with DB fallback) rate limiting middleware for API routes.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $scope = 'public:ip',
        private ?int $maxAttempts = null,
        private ?int $windowSeconds = null
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $max = $this->maxAttempts ?? $this->envInt('PUBLIC_RATE_LIMIT_MAX', 60);
        $window = $this->windowSeconds ?? $this->envInt('PUBLIC_RATE_LIMIT_WINDOW', 60);

        if ($max <= 0) {
            return $handler->handle($request);
        }

        $ip = ClientIp::fromRequest($request) ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = RateLimiter::key($this->scope, $ip);

        $status = RateLimiter::attempt($key, $max, $window, $window);
        if (!$status['allowed']) {
            throw new RateLimitedException($status['retry_after'] > 0 ? $status['retry_after'] : $window);
        }

        $remaining = max(0, $max - (int) ($status['attempts'] ?? 0));
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    private function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }
}
