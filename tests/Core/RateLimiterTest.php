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

        $middleware = new RateLimitMiddleware('test:public:ip', 2, 60);
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

        $res2 = $middleware->process($request, $handler);
        $this->assertSame('2', $res2->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $res2->getHeaderLine('X-RateLimit-Remaining'));

        $this->expectException(RateLimitedException::class);
        $middleware->process($request, $handler);
    }

    public function testFixedWindowResetsAfterWindowAndFailsOpenWhenRedisDown(): void
    {
        RedisClient::useInMemoryDriver(true);
        $now = 1_760_100_000;
        RedisClient::setTestNow($now);

        $key = RateLimiter::key('test:fw', '198.51.100.30');
        $a1 = RateLimiter::attemptFixedWindow($key, 2, 60);
        $a2 = RateLimiter::attemptFixedWindow($key, 2, 60);
        $a3 = RateLimiter::attemptFixedWindow($key, 2, 60);

        $this->assertTrue($a1['allowed']);
        $this->assertTrue($a2['allowed']);
        $this->assertFalse($a3['allowed']);
        $this->assertGreaterThan(0, $a3['retry_after']);

        // Window resets at +61s
        RedisClient::setTestNow($now + 61);
        $a4 = RateLimiter::attemptFixedWindow($key, 2, 60);
        $this->assertTrue($a4['allowed']);
        $this->assertSame(1, $a4['attempts']);

        // Fail-open when Redis goes down
        RedisClient::simulateFailure(true, 'Redis timeout');
        $degraded = RateLimiter::attemptFixedWindow($key, 1, 60);
        $this->assertTrue($degraded['allowed']);
        $this->assertTrue($degraded['degraded']);
    }

    public function testAuthAttemptEnforcesPerIpAndIdentifierAndAllowsGenerousSharedIp(): void
    {
        RedisClient::useInMemoryDriver(true);
        $_ENV['RATE_LIMIT_AUTH_USER_IP_MAX'] = '2';
        $_ENV['RATE_LIMIT_AUTH_IP_MAX'] = '100';

        $middleware = RateLimitMiddleware::forAuthAttempt();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $makeReq = static function (string $username): ServerRequestInterface {
            return (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/v1/auth/login', ['REMOTE_ADDR' => '198.51.100.77'])
                ->withParsedBody(['username' => $username, 'password' => 'secret']);
        };

        $this->assertSame(200, $middleware->process($makeReq('09120001111'), $handler)->getStatusCode());
        $this->assertSame(200, $middleware->process($makeReq('09120001111'), $handler)->getStatusCode());

        // Different user on the same shared Wi-Fi IP still allowed
        $this->assertSame(200, $middleware->process($makeReq('09120002222'), $handler)->getStatusCode());

        // 3rd attempt for 09120001111 from same IP is blocked
        $this->expectException(RateLimitedException::class);
        $middleware->process($makeReq('09120001111'), $handler);
    }

    public function testAuthenticatedRoleLimitsAndBotPrefixExemption(): void
    {
        RedisClient::useInMemoryDriver(true);
        $_ENV['RATE_LIMIT_API_STUDENT_MAX'] = '2';
        $_ENV['RATE_LIMIT_API_STAFF_MAX'] = '4';

        $middleware = RateLimitMiddleware::forAuthenticatedUser();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $studentReq = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/plans')
            ->withAttribute('user', (object) ['sub' => 501, 'role' => 'student']);
        $adminReq = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/students')
            ->withAttribute('user', (object) ['sub' => 502, 'role' => 'admin']);
        $botReq = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/bot/ping', ['REMOTE_ADDR' => '198.51.100.88']);

        // Bot route bypasses per-IP middleware limits entirely
        $botRes = RateLimitMiddleware::forPublicIp()->process($botReq, $handler);
        $this->assertFalse($botRes->hasHeader('X-RateLimit-Limit'));

        // Student limit = 2, Admin limit = 4
        $this->assertSame('2', $middleware->process($studentReq, $handler)->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('4', $middleware->process($adminReq, $handler)->getHeaderLine('X-RateLimit-Limit'));
    }
}


