<?php

namespace Tests\Core;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\EnvValidator;
use App\Core\Validator;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
        parent::tearDown();
    }

    public function testEnvValidatorRejectsMissingJwtSecret(): void
    {
        unset($_ENV['JWT_SECRET']);
        putenv('JWT_SECRET');

        $this->expectException(ApiException::class);
        EnvValidator::validateCore(false);
    }

    public function testEnvValidatorRejectsMissingDatabaseVariablesWhenRequired(): void
    {
        unset($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER']);

        try {
            EnvValidator::validateCore(true);
            $this->fail('Expected ApiException for missing DB environment variables');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertSame('ENV_CONFIGURATION_ERROR', $e->getErrorCode());
        }
    }

    public function testEnvValidatorRejectsInvalidPositiveIntFormat(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-key';
        $_ENV['JWT_TTL'] = '-10';

        try {
            EnvValidator::validateCore(false);
            $this->fail('Expected ApiException for negative JWT_TTL');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertSame('ENV_CONFIGURATION_ERROR', $e->getErrorCode());
        }
    }

    public function testEnvValidatorRejectsInvalidBotServiceKeyOnBotRoutes(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-key';
        unset($_ENV['BOT_SERVICE_KEY']);
        putenv('BOT_SERVICE_KEY');

        $request = $this->createRequest('GET', '/api/v1/bot/links/resolve');
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('BOT_NOT_CONFIGURED', $data['error']['code']);
    }

    public function testEnvValidatorRejectsInvalidTelegramLoginSecretFormat(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-key';
        $_ENV['TELEGRAM_LOGIN_SECRET_KEY'] = 'not-a-64-char-hex-key';

        $request = $this->jsonRequest('POST', '/api/v1/auth/telegram/verify', [
            'id' => 12345,
            'auth_date' => time(),
            'hash' => str_repeat('a', 64),
        ]);
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('ENV_CONFIGURATION_ERROR', $data['error']['code']);
    }

    public function testRequestValidationMiddlewareRejectsEmptyPostBody(): void
    {
        $request = $this->createRequest('POST', '/api/v1/auth/login');
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('VALIDATION_ERROR', $data['error']['code']);
    }

    public function testRequestValidationMiddlewareRejectsMalformedRawJsonBody(): void
    {
        $factory = new ServerRequestFactory();
        $stream = (new StreamFactory())->createStream('{invalid-json');
        $request = $factory->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('VALIDATION_ERROR', $data['error']['code']);
    }

    public function testRequestValidationMiddlewareAllowsEmptyBodyOnLogout(): void
    {
        $request = $this->createRequest('POST', '/api/v1/auth/logout');
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
    }

    public function testAppointmentCreateValidatesMandatoryFieldsAndFormats(): void
    {
        $token = Auth::generateToken([
            'sub' => 1,
            'username' => 'admin',
            'role' => 'admin',
            'supporter_id' => null,
            'student_id' => null,
        ]);

        $request = $this->jsonRequest('POST', '/api/v1/appointments', [
            'student_id' => 'invalid',
            'appointment_date' => 'not-a-date',
        ], $token);
        $response = $this->app->handle($request);
        $data = $this->getResponseData($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $data['error']['code']);
    }

    public function testValidatorHandlesNonScalarValuesAndChainableRules(): void
    {
        $validator = new Validator();
        $validator
            ->required('name', 'نام', ['unexpected_array'])
            ->positiveInt('id', 'شناسه', '0')
            ->date('date', 'تاریخ', '2026-13-45')
            ->time('time', 'ساعت', '25:99')
            ->boolean('active', 'فعال', 'not-a-bool')
            ->arrayList('items', 'آیتم‌ها', 'not-an-array', true);

        $this->assertFalse($validator->passes());
        $errors = $validator->errors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('id', $errors);
        $this->assertArrayHasKey('date', $errors);
        $this->assertArrayHasKey('time', $errors);
        $this->assertArrayHasKey('active', $errors);
        $this->assertArrayHasKey('items', $errors);
    }
}
