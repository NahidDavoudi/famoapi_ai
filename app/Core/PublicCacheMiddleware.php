<?php
declare(strict_types=1);

namespace App\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Caches public GET endpoint responses in Redis (when available) under the
 * 'public' cache group and attaches HTTP Cache-Control headers.
 */
class PublicCacheMiddleware implements MiddlewareInterface
{
    private const GROUP = 'public';

    public function __construct(
        private int $maxAge = 300,
        private string $visibility = 'public'
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ( str_starts_with($path, '/api/v1/public/exam-results/') ) {
            return $handler->handle($request);
        }
        $query = $request->getUri()->getQuery();
        // Individual blog post detail increments views on each visit in BlogService::getPost
        $isPostDetailWithViewCounter = preg_match('#^/api/v1/public/blog/posts/[^/]+$#', $path) === 1;
        $cacheKey = 'http_response:' . $path . ($query !== '' ? '?' . $query : '');

        if (!$isPostDetailWithViewCounter && Cache::isUsingRedis()) {
            $cachedHit = false;
            $cachedBody = Cache::remember(self::GROUP, $cacheKey, $this->maxAge, function () use (&$cachedHit) {
                $cachedHit = true; // callback ran -> cache miss
                return null;
            });

            if (is_string($cachedBody) && !$cachedHit) {
                $response = new Response();
                $response->getBody()->write($cachedBody);
                return $response
                    ->withStatus(200)
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withHeader('Cache-Control', "{$this->visibility}, max-age={$this->maxAge}")
                    ->withHeader('X-Cache', 'HIT')
                    ->withAddedHeader('Vary', 'Origin');
            }
        }

        $response = $handler->handle($request);

        if ($response->getStatusCode() !== 200 || $response->hasHeader('Cache-Control')) {
            return $response;
        }

        if (!$isPostDetailWithViewCounter && Cache::isUsingRedis()) {
            $bodyContent = (string) $response->getBody();
            if ($bodyContent !== '') {
                Cache::remember(self::GROUP, $cacheKey, $this->maxAge, static fn() => $bodyContent);
            }
            $response = $response->withHeader('X-Cache', 'MISS');
        }

        return $response
            ->withHeader('Cache-Control', "{$this->visibility}, max-age={$this->maxAge}")
            ->withAddedHeader('Vary', 'Origin');
    }
}
