<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ApiException;
use App\Core\OtpService;
use App\Core\RedisClient;
use PHPUnit\Framework\TestCase;

class OtpServiceTest extends TestCase
{
    private string $tmpLog;
    private string $tmpState;

    protected function setUp(): void
    {
        parent::setUp();
        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        $this->tmpLog = sys_get_temp_dir() . '/famo_otp_test_' . bin2hex(random_bytes(4)) . '.log';
        $this->tmpState = sys_get_temp_dir() . '/famo_otp_state_' . bin2hex(random_bytes(4)) . '.json';
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

    public function testIssueStoresHashedOtpUnderPurposeBoundKeyWithoutPlaintext(): void
    {
        $now = 1_760_000_000;
        RedisClient::setTestNow($now);

        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_PASSWORD_RESET, '09123456789');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        $this->assertSame('famo:otp:password_reset:09123456789', $issued['key']);
        $this->assertSame(300, $issued['ttl']);

        $rawStored = RedisClient::get('famo:otp:password_reset:09123456789');
        $this->assertNotNull($rawStored);
        $this->assertStringNotContainsString($issued['code'], $rawStored);

        $decoded = json_decode($rawStored, true);
        $this->assertIsArray($decoded);
        $this->assertSame('password_reset', $decoded['purpose'] ?? null);
        $this->assertSame(0, $decoded['attempts'] ?? null);
        $this->assertSame(5, $decoded['max_attempts'] ?? null);
        $this->assertNotEmpty($decoded['hash'] ?? '');
        $this->assertNotEmpty($decoded['salt'] ?? '');
        $this->assertSame(300, RedisClient::ttl('famo:otp:password_reset:09123456789'));
    }

    public function testPurposeIsolationPreventsCrossPurposeVerification(): void
    {
        $now = 1_760_000_100;
        RedisClient::setTestNow($now);

        $service = new OtpService();
        $resetOtp = $service->issue(OtpService::PURPOSE_PASSWORD_RESET, '09123456789');
        $loginOtp = $service->issue(OtpService::PURPOSE_LOGIN_2FA, '09123456789');

        // A code issued for password_reset must NEVER validate for login_2fa
        try {
            $service->verify(OtpService::PURPOSE_LOGIN_2FA, '09123456789', $resetOtp['code']);
            if ($resetOtp['code'] !== $loginOtp['code']) {
                $this->fail('password_reset OTP must not validate under login_2fa');
            }
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }

        // And a code issued solely for login_2fa must NEVER validate for password_reset
        $onlyLogin = $service->issue(OtpService::PURPOSE_LOGIN_2FA, '09129998877');
        try {
            $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09129998877', $onlyLogin['code']);
            $this->fail('login_2fa OTP must not validate under password_reset');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }

        // Legitimate purpose verification still works
        $service->verify(OtpService::PURPOSE_LOGIN_2FA, '09129998877', $onlyLogin['code']);
        $this->assertTrue(true);
    }

    public function testRejectsUnsupportedPurpose(): void
    {
        $service = new OtpService();
        $this->expectException(\InvalidArgumentException::class);
        $service->issue('arbitrary_purpose', '09123456789');
    }

    public function testMaxFiveAttemptsInvalidatesOtpAndPreservesRemainingTtl(): void
    {
        $now = 1_760_000_200;
        RedisClient::setTestNow($now);

        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_PASSWORD_RESET, '09121112233', 300, 5, ['user_id' => 42]);
        $wrongCode = $issued['code'] === '000000' ? '999999' : '000000';

        // Advance 50s and fail 4 times; remaining TTL must NOT reset to 300s
        RedisClient::setTestNow($now + 50);
        for ($i = 1; $i <= 4; $i++) {
            try {
                $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112233', $wrongCode);
                $this->fail("Wrong attempt {$i} should throw");
            } catch (ApiException $e) {
                $this->assertSame(401, $e->getHttpStatus());
            }
        }

        $this->assertSame(250, RedisClient::ttl('famo:otp:password_reset:09121112233'));

        // 5th failed attempt deletes the key and returns 429 OTP_MAX_ATTEMPTS
        try {
            $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112233', $wrongCode);
            $this->fail('5th wrong attempt should exhaust attempts');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getHttpStatus());
            $this->assertSame('OTP_MAX_ATTEMPTS', $e->getErrorCode());
        }

        // Key is deleted so even the genuine code on attempt 6 fails
        $this->assertNull(RedisClient::get('famo:otp:password_reset:09121112233'));

        $this->expectException(ApiException::class);
        $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112233', $issued['code']);
    }

    public function testSucceedsOnFifthAttemptIfValidCodeProvided(): void
    {
        $now = 1_760_000_300;
        RedisClient::setTestNow($now);

        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_LOGIN_2FA, '09121112244', 300, 5, ['user_id' => 99]);
        $wrongCode = $issued['code'] === '000000' ? '999999' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            try {
                $service->verify(OtpService::PURPOSE_LOGIN_2FA, '09121112244', $wrongCode);
                $this->fail('Expected failure');
            } catch (ApiException $e) {
                $this->assertSame(401, $e->getHttpStatus());
            }
        }

        $meta = $service->verify(OtpService::PURPOSE_LOGIN_2FA, '09121112244', $issued['code']);
        $this->assertSame(99, $meta['user_id'] ?? null);
    }

    public function testOtpExpiresAfter300Seconds(): void
    {
        $now = 1_760_000_400;
        RedisClient::setTestNow($now);

        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_PASSWORD_RESET, '09121112255');

        // Valid at +299s
        RedisClient::setTestNow($now + 299);
        $this->assertNotNull(RedisClient::get('famo:otp:password_reset:09121112255'));

        // Expired at +300s
        RedisClient::setTestNow($now + 300);
        try {
            $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112255', $issued['code']);
            $this->fail('Expired OTP must be rejected');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertSame('OTP_INVALID', $e->getErrorCode());
        }
    }

    public function testOtpIsSingleUse(): void
    {
        RedisClient::setTestNow(1_760_000_500);

        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_PASSWORD_RESET, '09121112266', 300, 5, ['user_id' => 7]);

        $meta = $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112266', $issued['code']);
        $this->assertSame(7, $meta['user_id'] ?? null);
        $this->assertNull(RedisClient::get('famo:otp:password_reset:09121112266'));

        try {
            $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112266', $issued['code']);
            $this->fail('Reused OTP must be rejected');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }
    }

    public function testFailsClosedWhenRedisIsUnavailable(): void
    {
        RedisClient::setTestNow(1_760_000_600);

        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_PASSWORD_RESET, '09121112277');

        RedisClient::simulateFailure(true, 'Redis unreachable');

        try {
            $service->verify(OtpService::PURPOSE_PASSWORD_RESET, '09121112277', $issued['code']);
            $this->fail('OTP verify must fail closed when Redis is unavailable');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getHttpStatus());
            $this->assertSame('OTP_SERVICE_UNAVAILABLE', $e->getErrorCode());
        }

        try {
            $service->issue(OtpService::PURPOSE_LOGIN_2FA, '09121112277');
            $this->fail('OTP issue must fail closed when Redis is unavailable');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getHttpStatus());
            $this->assertSame('OTP_SERVICE_UNAVAILABLE', $e->getErrorCode());
        }
    }

    public function testNormalizesPersianAndArabicDigitsOnVerify(): void
    {
        $service = new OtpService();
        $issued = $service->issue(OtpService::PURPOSE_LOGIN_2FA, '09121112288');

        $persianCode = strtr($issued['code'], [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);

        $meta = $service->verify(OtpService::PURPOSE_LOGIN_2FA, '09121112288', $persianCode);
        $this->assertIsArray($meta);
    }
}
