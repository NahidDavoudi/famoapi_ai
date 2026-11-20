<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Monitors Redis connectivity across requests and logs CRITICAL alerts
 * capturing the error message and downtime duration when Redis becomes
 * unreachable or recovers.
 */
final class RedisMonitorMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (RedisClient::isEnabled()) {
            $available = RedisClient::isAvailable();
            if (!$available) {
                $error = RedisClient::getLastError() ?? 'Redis connection unavailable';
                RedisClient::recordFailure(
                    $request->getMethod() . ' ' . $request->getUri()->getPath(),
                    $error
                );
            } else {
                RedisClient::recordRecoveryIfNeeded();
            }
        }

        $response = $handler->handle($request);

        if (RedisClient::isEnabled() && !RedisClient::isAvailable()) {
            $error = RedisClient::getLastError() ?? 'Redis connection unavailable';
            RedisClient::recordFailure(
                $request->getMethod() . ' ' . $request->getUri()->getPath(),
                $error
            );
        }

        return $response;
    }
}
