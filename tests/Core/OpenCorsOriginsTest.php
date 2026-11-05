<?php

use App\Core\AuthCookie;
use App\Core\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class OpenCorsOriginsTest extends TestCase
{
    private ?string $originalCorsOrigins = null;
    private bool $hadCorsOrigins = false;

    protected function setUp(): void
    {
        $this->hadCorsOrigins = array_key_exists('CORS_ORIGINS', $_ENV);
        $this->originalCorsOrigins = $this->hadCorsOrigins ? (string) $_ENV['CORS_ORIGINS'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->hadCorsOrigins) {
            $_ENV['CORS_ORIGINS'] = $this->originalCorsOrigins;
        } else {
            unset($_ENV['CORS_ORIGINS']);
        }
        parent::tearDown();
    }

    public function testConfiguredCorsOriginsFromEnvAreInjectedForPreflightAndRequests(): void
    {
        $_ENV['CORS_ORIGINS'] = 'https://auth.famoacademy.ir, https://client.example:8443';

        $origin = 'https://client.example:8443';
        $request = (new ServerRequestFactory())->createServerRequest('OPTIONS', '/api/v1/auth/login')
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'content-type, x-custom-header');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $response = (new CorsMiddleware())->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($origin, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('x-custom-header', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertTrue(AuthCookie::isOriginAllowed('https://auth.famoacademy.ir'));
        self::assertTrue(AuthCookie::isOriginAllowed($origin));
        self::assertFalse(AuthCookie::isOriginAllowed('null'));
    }

    public function testWildcardCorsOriginsAllowsAnyValidHttpOrigin(): void
    {
        $_ENV['CORS_ORIGINS'] = '*';

        self::assertTrue(AuthCookie::isOriginAllowed('http://any-site.test'));
        self::assertFalse(AuthCookie::isOriginAllowed('null'));
    }
}
