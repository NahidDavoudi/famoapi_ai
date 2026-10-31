<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\RateLimitMiddleware;
use App\Core\RedisClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RedisClient::reset();
        $this->db->exec("DELETE FROM auth_throttle WHERE throttle_key LIKE 'test:%'");
    }

    protected function tearDown(): void
    {
        RedisClient::reset();
        parent::tearDown();
    }

    public function testLocksAfterMaxAttemptsAndClears(): void
    {
        $key = RateLimiter::key('test:login', 'user-a|1.2.3.4');
        for ($i = 0; $i < 3; $i++) {
            $status = RateLimiter::attempt($key, 3, 900, 900);
        }
        $this->assertFalse($status['allowed']);
        $this->assertGreaterThan(0, $status['retry_after']);

        RateLimiter::clear($key);
        $this->assertTrue(RateLimiter::status($key, 3)['allowed']);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $a = RateLimiter::key('test:login', 'user-a|1.1.1.1');
        $b = RateLimiter::key('test:login', 'user-a|2.2.2.2');
        RateLimiter::attempt($a, 2, 900, 900);
        RateLimiter::attempt($a, 2, 900, 900);
        $this->assertFalse(RateLimiter::status($a, 2)['allowed']);
        $this->assertTrue(RateLimiter::status($b, 2)['allowed']);
    }

    public function testRedisRateLimiterLocksAfterMaxAttemptsAndClears(): void
    {
        RedisClient::useInMemoryDriver(true);

        $key = RateLimiter::key('test:redis:public', '203.0.113.10');
        $s1 = RateLimiter::attempt($key, 3, 60, 60);
        $s2 = RateLimiter::attempt($key, 3, 60, 60);
        $s3 = RateLimiter::attempt($key, 3, 60, 60);

        $this->assertTrue($s1['allowed']);
        $this->assertTrue($s2['allowed']);
        $this->assertFalse($s3['allowed']);
        $this->assertGreaterThan(0, $s3['retry_after']);
        $this->assertFalse(RateLimiter::status($key, 3)['allowed']);

        RateLimiter::clear($key);
        $afterClear = RateLimiter::status($key, 3);
        $this->assertTrue($afterClear['allowed']);
        $this->assertSame(0, $afterClear['attempts']);
    }

    public function testRateLimitMiddlewareAllowsWithinLimitAndThrowsWhenExceeded(): void
    {
        RedisClient::useInMemoryDriver(true);

        $middleware = new RateLimitMiddleware('test:public:ip', 2, 60, 60);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/public/courses', [
            'REMOTE_ADDR' => '198.51.100.25',
        ]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $res1 = $middleware->process($request, $handler);
        $this->assertSame('2', $res1->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('1', $res1->getHeaderLine('X-RateLimit-Remaining'));

        $this->expectException(RateLimitedException::class);
        $middleware->process($request, $handler);
    }
}

