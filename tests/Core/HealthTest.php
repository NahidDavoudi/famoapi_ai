<?php

namespace Tests\Core;

use App\Core\Database;
use App\Core\RedisClient;
use Tests\TestCase;

class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RedisClient::useInMemoryDriver(true);
    }

    protected function tearDown(): void
    {
        RedisClient::reset();
        parent::tearDown();
    }

    public function testHealthEndpointReturnsConnectedDatabaseAndRedisStatus(): void
    {
        $request = $this->createRequest('GET', '/health');
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame('healthy', $data['data']['status']);
        $this->assertSame('connected', $data['data']['database']['status']);
        $this->assertTrue($data['data']['database']['connected']);
        $this->assertSame('connected', $data['data']['redis']['status']);
        $this->assertTrue($data['data']['redis']['connected']);
        $this->assertSame('1.0.0', $data['data']['version']);
        $this->assertNotEmpty($data['data']['time']);
    }

    public function testApiV1HealthEndpointMatchesHealthStructure(): void
    {
        $request = $this->createRequest('GET', '/api/v1/health');
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame('healthy', $data['data']['status']);
        $this->assertTrue($data['data']['database']['connected']);
        $this->assertTrue($data['data']['redis']['connected']);
    }

    public function testHealthEndpointReportsDisabledRedisWithoutDegradingHealthyStatus(): void
    {
        RedisClient::reset();
        RedisClient::configure(['enabled' => false]);

        $request = $this->createRequest('GET', '/health');
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame('healthy', $data['data']['status']);
        $this->assertTrue($data['data']['database']['connected']);
        $this->assertSame('disabled', $data['data']['redis']['status']);
        $this->assertFalse($data['data']['redis']['connected']);
    }

    public function testHealthEndpointReturns503WhenDatabaseIsDisconnected(): void
    {
        $savedEnv = $_ENV;
        try {
            Database::reset();
            unset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER']);

            $request = $this->createRequest('GET', '/health');
            $response = $this->app->handle($request);
            $data = $this->getResponseData($response);

            $this->assertSame(503, $response->getStatusCode());
            $this->assertFalse($data['success']);
            $this->assertSame('unhealthy', $data['data']['status']);
            $this->assertSame('disconnected', $data['data']['database']['status']);
            $this->assertFalse($data['data']['database']['connected']);
        } finally {
            $_ENV = $savedEnv;
        }
    }
}
