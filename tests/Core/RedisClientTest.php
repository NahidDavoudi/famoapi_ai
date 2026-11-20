<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\RateLimiter;
use App\Core\RedisClient;
use App\Core\RedisMonitorMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class RedisClientTest extends TestCase
{
    private string $tmpLog;
    private string $tmpState;

    protected function setUp(): void
    {
        parent::setUp();
        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        $this->tmpLog = sys_get_temp_dir() . '/famo_redis_test_' . bin2hex(random_bytes(4)) . '.log';
        $this->tmpState = sys_get_temp_dir() . '/famo_redis_state_' . bin2hex(random_bytes(4)) . '.json';
        RedisClient::setLogPath($this->tmpLog);
        RedisClient::setDowntimeStatePath($this->tmpState);
    }

    protected function tearDown(): void
    {
        RedisClient::reset();
        @unlink($this->tmpLog);
        @unlink($this->tmpState);
        parent::tearDown();
    }

    public function testEvalFixedWindowMatchesRedisLuaSemanticsAndResetsAfterExpiry(): void
    {
        $now = 1_760_000_000;
        RedisClient::setTestNow($now);

        $r1 = RedisClient::eval(RateLimiter::FIXED_WINDOW_LUA, ['rl:fw:unit'], ['60']);
        $this->assertSame([1, 60], $r1);

        RedisClient::setTestNow($now + 15);
        $r2 = RedisClient::eval(RateLimiter::FIXED_WINDOW_LUA, ['rl:fw:unit'], ['60']);
        $this->assertSame([2, 45], $r2);

        // Once the 60s window expires, counter resets back to 1 with a fresh 60s TTL.
        RedisClient::setTestNow($now + 61);
        $r3 = RedisClient::eval(RateLimiter::FIXED_WINDOW_LUA, ['rl:fw:unit'], ['60']);
        $this->assertSame([1, 60], $r3);
    }

    public function testEvalHandlesAlreadyPrefixedKeyWithoutDoublePrefixing(): void
    {
        RedisClient::configure(['enabled' => true, 'prefix' => 'famo:']);
        RedisClient::useInMemoryDriver(true);
        RedisClient::setTestNow(1_760_000_000);

        $res = RedisClient::eval(RateLimiter::FIXED_WINDOW_LUA, ['famo:rl:fw:prefixed'], ['30']);
        $this->assertSame([1, 30], $res);
        $this->assertSame('1', RedisClient::get('rl:fw:prefixed'));
    }

    public function testRedisMonitorMiddlewareLogsCriticalErrorAndDowntimeOnOutageAndRecovery(): void
    {
        $middleware = new RedisMonitorMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/auth/me');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };

        // Simulate Redis outage
        RedisClient::simulateFailure(true, 'Connection refused on 127.0.0.1:6379');
        $middleware->process($request, $handler);

        $this->assertFileExists($this->tmpLog);
        $this->assertFileExists($this->tmpState);
        $logBeforeRecovery = (string) file_get_contents($this->tmpLog);
        $this->assertStringContainsString('[CRITICAL] Redis unreachable', $logBeforeRecovery);
        $this->assertStringContainsString('Connection refused on 127.0.0.1:6379', $logBeforeRecovery);
        $this->assertStringContainsString('downtime_seconds=', $logBeforeRecovery);

        // Simulate recovery
        usleep(10_000);
        RedisClient::simulateFailure(false);
        $middleware->process($request, $handler);

        $logAfterRecovery = (string) file_get_contents($this->tmpLog);
        $this->assertStringContainsString('[CRITICAL] Redis recovered: downtime_seconds=', $logAfterRecovery);
        $this->assertFileDoesNotExist($this->tmpState);
    }
}
