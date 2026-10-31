<?php

namespace Tests\Core;

use App\Core\Cache;
use App\Core\PublicCacheMiddleware;
use App\Core\RedisClient;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'famo_cache_test_' . bin2hex(random_bytes(6));
        RedisClient::reset();
        Cache::configure($this->dir, true);
    }

    protected function tearDown(): void
    {
        RedisClient::reset();
        Cache::configure($this->dir, false);
        $this->removeDirectory($this->dir);
        parent::tearDown();
    }

    public function testRememberRunsCallbackOnceAndReturnsCachedValue(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return ['value' => 42];
        };

        $first = Cache::remember('group', 'key', 60, $callback);
        $second = Cache::remember('group', 'key', 60, $callback);

        self::assertSame(['value' => 42], $first);
        self::assertSame(['value' => 42], $second);
        self::assertSame(1, $calls);
    }

    public function testNullIsNotCached(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return null;
        };

        self::assertNull(Cache::remember('group', 'missing', 60, $callback));
        self::assertNull(Cache::remember('group', 'missing', 60, $callback));
        self::assertSame(2, $calls);
    }

    public function testExpiredEntryRunsCallbackAgain(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return 'fresh';
        };

        Cache::remember('group', 'ttl', 1, $callback);
        sleep(2);
        Cache::remember('group', 'ttl', 1, $callback);

        self::assertSame(2, $calls);
    }

    public function testDisabledCacheAlwaysRunsCallback(): void
    {
        Cache::configure($this->dir, false);

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return 'value';
        };

        Cache::remember('group', 'key', 60, $callback);
        Cache::remember('group', 'key', 60, $callback);

        self::assertSame(2, $calls);
    }

    public function testForgetRemovesEntry(): void
    {
        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;
            return 'value';
        };

        Cache::remember('group', 'key', 60, $callback);
        Cache::forget('group', 'key');
        Cache::remember('group', 'key', 60, $callback);

        self::assertSame(2, $calls);
    }

    public function testFlushGroupRemovesAllEntriesInGroupOnly(): void
    {
        Cache::remember('group', 'a', 60, fn() => 'a');
        Cache::remember('group', 'b', 60, fn() => 'b');
        Cache::remember('other', 'c', 60, fn() => 'c');

        Cache::flushGroup('group');

        $calls = 0;
        Cache::remember('group', 'a', 60, function () use (&$calls) {
            $calls++;
            return 'a';
        });
        Cache::remember('other', 'c', 60, function () use (&$calls) {
            $calls++;
            return 'c';
        });

        self::assertSame(1, $calls);
    }

    public function testRedisBackedCacheRememberForgetAndFlushGroup(): void
    {
        RedisClient::useInMemoryDriver(true);

        $calls = 0;
        $first = Cache::remember('public', 'courses', 300, function () use (&$calls) {
            $calls++;
            return [['id' => 1, 'title' => 'Math']];
        });
        $second = Cache::remember('public', 'courses', 300, function () use (&$calls) {
            $calls++;
            return [['id' => 1, 'title' => 'Math']];
        });

        self::assertSame([['id' => 1, 'title' => 'Math']], $first);
        self::assertSame([['id' => 1, 'title' => 'Math']], $second);
        self::assertSame(1, $calls);

        Cache::remember('public', 'instructors', 300, fn() => [['id' => 2]]);
        Cache::remember('other', 'keep', 300, fn() => 'persisted');

        Cache::flushGroup('public');

        $recomputed = false;
        Cache::remember('public', 'courses', 300, function () use (&$recomputed) {
            $recomputed = true;
            return [['id' => 1, 'title' => 'Math']];
        });
        self::assertTrue($recomputed);

        $otherRecomputed = false;
        $kept = Cache::remember('other', 'keep', 300, function () use (&$otherRecomputed) {
            $otherRecomputed = true;
            return 'new';
        });
        self::assertFalse($otherRecomputed);
        self::assertSame('persisted', $kept);
    }

    public function testPublicCacheMiddlewareCachesResponseInRedisAndInvalidatesOnFlush(): void
    {
        RedisClient::useInMemoryDriver(true);

        $middleware = new PublicCacheMiddleware(300);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/public/courses');

        $handlerCalls = 0;
        $handler = new class($handlerCalls) implements RequestHandlerInterface {
            private int $calls;
            public function __construct(int &$calls)
            {
                $this->calls = &$calls;
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                $response = (new ResponseFactory())->createResponse(200);
                $response->getBody()->write(json_encode(['success' => true, 'calls' => $this->calls]));
                return $response->withHeader('Content-Type', 'application/json');
            }
        };

        $firstResponse = $middleware->process($request, $handler);
        self::assertSame('MISS', $firstResponse->getHeaderLine('X-Cache'));
        self::assertSame(1, $handlerCalls);

        $secondResponse = $middleware->process($request, $handler);
        self::assertSame('HIT', $secondResponse->getHeaderLine('X-Cache'));
        self::assertSame(1, $handlerCalls);
        self::assertSame('{"success":true,"calls":1}', (string) $secondResponse->getBody());

        Cache::flushGroup('public');

        $thirdResponse = $middleware->process($request, $handler);
        self::assertSame('MISS', $thirdResponse->getHeaderLine('X-Cache'));
        self::assertSame(2, $handlerCalls);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

