<?php

namespace App\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    private const DEFAULT_ALLOWED_HEADERS = [
        'Content-Type',
        'Authorization',
        'Accept',
        'Origin',
        'X-Requested-With',
        'X-Auth-Mode',
        'X-HTTP-Method-Override',
        'Cache-Control',
        'Pragma',
        'X-Client-UUID',
    ];

    public static function applyHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = rtrim(trim($request->getHeaderLine('Origin')), '/');
        $originAllowed = AuthCookie::isOriginAllowed($origin);

        if ($originAllowed) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        } elseif ($origin === '') {
            $configuredOrigins = AuthCookie::allowedOrigins();
            if (in_array('*', $configuredOrigins, true)) {
                $response = $response->withHeader('Access-Control-Allow-Origin', '*');
            }
        }

        $allowedHeaders = self::DEFAULT_ALLOWED_HEADERS;
        $requestedHeaders = trim($request->getHeaderLine('Access-Control-Request-Headers'));
        if ($requestedHeaders !== '') {
            $existingLower = array_map('strtolower', $allowedHeaders);
            foreach (explode(',', $requestedHeaders) as $header) {
                $clean = trim($header);
                if ($clean !== ''
                    && preg_match('/^[a-zA-Z0-9-]+$/', $clean) === 1
                    && !in_array(strtolower($clean), $existingLower, true)) {
                    $allowedHeaders[] = $clean;
                    $existingLower[] = strtolower($clean);
                }
            }
        }

        return $response
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $allowedHeaders))
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = rtrim(trim($request->getHeaderLine('Origin')), '/');
        $originAllowed = AuthCookie::isOriginAllowed($origin);
        $method = strtoupper($request->getMethod());
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($method === 'OPTIONS') {
            if ($origin !== '' && !$originAllowed) {
                return self::applyHeaders($request, (new Response())->withStatus(403));
            }

            return self::applyHeaders($request, (new Response())
                ->withStatus(204)
                ->withHeader('Access-Control-Max-Age', '86400'));
        }

        $cookieAuthRoute = str_starts_with($request->getUri()->getPath(), '/api/v1/auth/');
        $cookieRequest = AuthCookie::isPresent($request);
        if ($isWrite && !$originAllowed && ($cookieAuthRoute || $cookieRequest)) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'success' => false,
                'data' => null,
                'pagination' => null,
                'error' => ['code' => 'CSRF_ORIGIN_REJECTED', 'message' => 'مبدأ درخواست مجاز نیست'],
            ], JSON_UNESCAPED_UNICODE));

            return self::applyHeaders($request, $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json; charset=utf-8'));
        }

        return self::applyHeaders($request, $handler->handle($request));
    }
}
