<?php
declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Core\Auth;
use App\Core\EnvValidator;
use App\Core\JwtRevocation;
use App\Core\OtpService;
use App\Core\RateLimiter;
use App\Core\RedisClient;
use App\Core\SmsService;
use App\Core\TrustedDevice;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\AuthService;
use Firebase\JWT\JWT;
use Slim\Psr7\Response;
use Tests\TestCase;

final class CapturingFakeSmsService extends SmsService
{
    public bool $shouldSucceed = true;

    /** @var array<int, array{mobile: string, code: string}> */
    public array $sent = [];

    public function sendVerificationCode(string $mobile, string $code): bool
    {
        $this->sent[] = ['mobile' => $mobile, 'code' => $code];
        return $this->shouldSucceed;
    }
}

class PasswordResetAndStudent2faTest extends TestCase
{
    private string $tmpLog;
    private string $tmpState;
    /** @var array<string, mixed> */
    private array $envBackup = [];

    private CapturingFakeSmsService $fakeSms;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'STUDENT_2FA_MODE',
            'TRUSTED_DEVICE_COOKIE_NAME',
            'PASSWORD_FORGOT_TIMING_FLOOR_MS',
            'RATE_LIMIT_SMS_IP_MAX',
            'RATE_LIMIT_SMS_IP_WINDOW',
            'RATE_LIMIT_SMS_PHONE_MAX',
            'RATE_LIMIT_SMS_PHONE_WINDOW',
            'RATE_LIMIT_AUTH_IP_MAX',
            'RATE_LIMIT_AUTH_IP_WINDOW',
            'RATE_LIMIT_AUTH_USER_IP_MAX',
            'RATE_LIMIT_AUTH_USER_IP_WINDOW',
            'TRUSTED_PROXIES',
            'TRUSTED_PROXY_HEADER',
        ] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['STUDENT_2FA_MODE'] = 'off';
        $_ENV['PASSWORD_FORGOT_TIMING_FLOOR_MS'] = '0';
        $_ENV['TRUSTED_PROXIES'] = '';
        $_ENV['TRUSTED_PROXY_HEADER'] = '';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        $this->tmpLog = sys_get_temp_dir() . '/famo_pwd_reset_' . bin2hex(random_bytes(4)) . '.log';
        $this->tmpState = sys_get_temp_dir() . '/famo_pwd_state_' . bin2hex(random_bytes(4)) . '.json';
        RedisClient::setLogPath($this->tmpLog);
        RedisClient::setDowntimeStatePath($this->tmpState);

        $this->fakeSms = new CapturingFakeSmsService();

        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRows();

        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        RedisClient::reset();
        @unlink($this->tmpLog);
        @unlink($this->tmpState);

        parent::tearDown();
    }

    private function cleanupTestRows(): void
    {
        $this->db->exec("DELETE FROM login_codes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'prtest_%' OR phone LIKE '09777777%')");
        $this->db->exec("DELETE FROM users WHERE username LIKE 'prtest_%' OR phone LIKE '09777777%'");
        $this->db->exec("DELETE FROM students WHERE phone LIKE '09777777%'");
        $this->db->exec("DELETE FROM auth_throttle");
    }

    private function seedUser(
        string $username,
        string $phone,
        string $password,
        string $role = 'student',
        int $isActive = 1,
        ?int $linkedId = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, phone, password_hash, role, full_name, linked_id, is_active, created_at)
             VALUES (:u, :p, :h, :r, :f, :l, :a, NOW())'
        );
        $stmt->execute([
            'u' => $username,
            'p' => $phone,
            'h' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
            'f' => 'Test User ' . $username,
            'l' => $linkedId,
            'a' => $isActive,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function extractCookieValue(string $setCookieHeader, string $cookieName): ?string
    {
        if (preg_match('/(?:^|,\s*)' . preg_quote($cookieName, '/') . '=([^;\r\n]+)/', $setCookieHeader, $m) === 1) {
            return rawurldecode($m[1]);
        }
        return null;
    }

    public function testForgotPasswordAntiEnumerationAcrossExistingMissingInactiveAndPlaceholderPhones(): void
    {
        $this->seedUser('prtest_stu_enum', '09777777001', 'pass1234', 'student', 1);
        $this->seedUser('prtest_stu_inact', '09777777002', 'pass1234', 'student', 0);

        $service = new AuthService($this->fakeSms);

        $existingRes = $service->forgotPassword('09777777001');
        $missingRes = $service->forgotPassword('09777777999');
        $inactiveRes = $service->forgotPassword('09777777002');
        $placeholderRes = $service->forgotPassword('09000000000');

        $this->assertSame($existingRes, $missingRes);
        $this->assertSame($existingRes, $inactiveRes);
        $this->assertSame($existingRes, $placeholderRes);
        $this->assertSame(300, $existingRes['expires_in']);

        // Only the active non-placeholder phone received an SMS via the existing single verification pattern
        $this->assertCount(1, $this->fakeSms->sent);
        $this->assertSame('09777777001', $this->fakeSms->sent[0]['mobile']);
        $this->assertNotNull(RedisClient::get('famo:otp:password_reset:09777777001'));
        $this->assertNull(RedisClient::get('famo:otp:password_reset:09777777999'));
        $this->assertNull(RedisClient::get('famo:otp:password_reset:09777777002'));
        $this->assertNull(RedisClient::get('famo:otp:password_reset:09000000000'));
    }

    public function testForgotPasswordRefusesAmbiguousSharedPhonesUnlessUsernameDisambiguates(): void
    {
        $this->seedUser('prtest_twin_a', '09777777003', 'pass1234', 'student', 1);
        $this->seedUser('prtest_twin_b', '09777777003', 'pass5678', 'student', 1);

        $service = new AuthService($this->fakeSms);

        // Without username: uniform response, zero SMS sent, zero OTPs stored
        $ambiguousRes = $service->forgotPassword('09777777003');
        $this->assertSame(300, $ambiguousRes['expires_in']);
        $this->assertCount(0, $this->fakeSms->sent);
        $this->assertNull(RedisClient::get('famo:otp:password_reset:09777777003'));

        // With username: resolves uniquely to twin_b
        $service->forgotPassword('09777777003', 'prtest_twin_b');
        $this->assertCount(1, $this->fakeSms->sent);

        $verifyRes = $service->verifyPasswordResetOtp('09777777003', $this->fakeSms->sent[0]['code'], 'prtest_twin_b');
        $service->resetPasswordWithToken($verifyRes['reset_token'], 'newSecret99');

        // Only twin_b's password changed; twin_a keeps original password
        $twinBLogin = $service->login('prtest_twin_b', 'newSecret99');
        $this->assertArrayHasKey('token', $twinBLogin);

        $twinALogin = $service->login('prtest_twin_a', 'pass1234');
        $this->assertArrayHasKey('token', $twinALogin);
    }

    public function testEndToEndHttpPasswordResetRevokesAllPriorJwtsAndEnforcesSingleUse(): void
    {
        $phone = '09777777004';
        $userId = $this->seedUser('prtest_e2e_user', $phone, 'oldPass123', 'teacher', 1);

        // Prior active JWT minted before reset (iat < epoch)
        $priorJwt = JWT::encode([
            'jti'  => bin2hex(random_bytes(16)),
            'typ'  => 'access',
            'sub'  => $userId,
            'role' => 'teacher',
            'iat'  => time() - 10,
            'exp'  => time() + 3600,
        ], (string) $_ENV['JWT_SECRET'], 'HS256');

        // Prior JWT currently works
        $meBefore = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $priorJwt,
            ])
        );
        $this->assertSame(200, $meBefore->getStatusCode());

        // Step 1: forgot via controller (with fake SMS capturing code)
        $authController = new AuthController(new AuthService($this->fakeSms));
        $forgotReq = $this->jsonRequest('POST', '/api/v1/auth/password/forgot', [
            'phone' => $phone,
        ], ['Origin' => 'http://localhost']);
        $forgotResp = $authController->forgotPassword($forgotReq, new Response());
        $this->assertSuccessResponse($forgotResp, ['message', 'expires_in']);
        $this->assertCount(1, $this->fakeSms->sent);
        $otpCode = $this->fakeSms->sent[0]['code'];

        // Purpose isolation check through HTTP route: password_reset OTP cannot pass login verify-2fa
        $wrongPurposeResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/verify-2fa', [
                'user_id' => $userId,
                'code'    => $otpCode,
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($wrongPurposeResp, 401);

        // Step 2: verify via full Slim HTTP route
        $verifyResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/verify', [
                'phone' => $phone,
                'code'  => $otpCode,
            ], ['Origin' => 'http://localhost'])
        );
        $verifyData = $this->assertSuccessResponse($verifyResp, ['reset_token', 'expires_in']);
        $resetToken = (string) $verifyData['data']['reset_token'];
        $this->assertSame(600, $verifyData['data']['expires_in']);

        // Replay of the OTP must fail (single use)
        $otpReplayResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/verify', [
                'phone' => $phone,
                'code'  => $otpCode,
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($otpReplayResp, 401, null, 'OTP_INVALID');

        // Step 3: reset via full Slim HTTP route
        $resetResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/reset', [
                'token'        => $resetToken,
                'new_password' => 'brandNewPass88',
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertSuccessResponse($resetResp, ['message']);

        // Reset token is single-use: replaying it returns 401 INVALID_RESET_TOKEN
        $tokenReplayResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/reset', [
                'token'        => $resetToken,
                'new_password' => 'anotherPass99',
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($tokenReplayResp, 401, null, 'INVALID_RESET_TOKEN');

        // Password hash updated in DB
        $userRow = $this->fetchOne('users', ['id' => $userId]);
        $this->assertTrue(password_verify('brandNewPass88', (string) ($userRow['password_hash'] ?? '')));

        // All prior JWTs revoked
        $meAfter = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $priorJwt,
            ])
        );
        $this->assertJsonResponse($meAfter, 401, null, 'UNAUTHORIZED');
    }

    public function testForgotRateLimitsApplyAndFailOpenWhenRedisDownWhileOtpEndpointsFailClosed(): void
    {
        $_ENV['RATE_LIMIT_SMS_PHONE_MAX'] = '2';
        $_ENV['RATE_LIMIT_SMS_PHONE_WINDOW'] = '600';

        for ($i = 0; $i < 2; $i++) {
            $ok = $this->handleRequest(
                $this->jsonRequest('POST', '/api/v1/auth/password/forgot', [
                    'phone' => '09777777005',
                ], ['Origin' => 'http://localhost'])
            );
            $this->assertSame(200, $ok->getStatusCode());
        }

        // 3rd request hits sms per-phone fixed window -> 429 RATE_LIMITED
        $limited = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/forgot', [
                'phone' => '09777777005',
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($limited, 429, null, 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $limited->getHeaderLine('Retry-After'));

        // Simulate Redis outage: rate limiter fails OPEN, while OTP issue/verify/reset fail CLOSED (503)
        RedisClient::simulateFailure(true, 'Redis connection lost');

        $forgotOutage = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/forgot', [
                'phone' => '09777777005',
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($forgotOutage, 503, null, 'OTP_SERVICE_UNAVAILABLE');

        $verifyOutage = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/verify', [
                'phone' => '09777777005',
                'code'  => '123456',
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($verifyOutage, 503, null, 'OTP_SERVICE_UNAVAILABLE');

        $resetOutage = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/reset', [
                'token'        => 'any-token-value',
                'new_password' => 'validPass123',
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($resetOutage, 503, null, 'OTP_SERVICE_UNAVAILABLE');
    }

    public function testStudent2faModesOffNewDeviceWithCookieAndAlways(): void
    {
        $phone = '09777777006';
        $password = 'studentPass1';
        $studentAId = $this->seedUser('prtest_stu_2fa_a', $phone, $password, 'student', 1);
        $this->seedUser('prtest_stu_2fa_b', '09777777007', $password, 'student', 1);

        // 1. Default OFF mode: direct 200 login without OTP
        $_ENV['STUDENT_2FA_MODE'] = 'off';
        $offResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/login', [
                'username' => $phone,
                'password' => $password,
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertSuccessResponse($offResp, ['token', 'user']);

        // 2. NEW_DEVICE mode without cookie -> 202 requires_2fa
        $_ENV['STUDENT_2FA_MODE'] = 'new_device';
        $controller = new AuthController(new AuthService($this->fakeSms));
        $login202Resp = $controller->login(
            $this->jsonRequest('POST', '/api/v1/auth/login', [
                'username' => $phone,
                'password' => $password,
            ], ['Origin' => 'http://localhost']),
            new Response()
        );
        $login202Data = $this->assertJsonResponse($login202Resp, 202, ['requires_2fa', 'user_id', 'phone_mask']);
        $this->assertTrue($login202Data['data']['requires_2fa']);
        $this->assertCount(1, $this->fakeSms->sent);
        $otpCode = $this->fakeSms->sent[0]['code'];

        // Purpose isolation: login_2fa OTP cannot be used for password_reset
        $crossPurpose = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/password/verify', [
                'phone' => $phone,
                'code'  => $otpCode,
            ], ['Origin' => 'http://localhost'])
        );
        $this->assertJsonResponse($crossPurpose, 401);

        // Verify 2FA sets HttpOnly famo_td trusted-device cookie stored hashed per user in Redis
        $verify2faResp = $controller->verify2fa(
            $this->jsonRequest('POST', '/api/v1/auth/verify-2fa', [
                'user_id' => $studentAId,
                'code'    => $otpCode,
            ], ['Origin' => 'http://localhost']),
            new Response()
        );
        $this->assertSuccessResponse($verify2faResp, ['token', 'user']);

        $setCookie = $verify2faResp->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('famo_td=', $setCookie);
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('Max-Age=2592000', $setCookie);

        $trustedToken = $this->extractCookieValue($setCookie, TrustedDevice::cookieName());
        $this->assertNotNull($trustedToken);
        $expectedRedisKey = TrustedDevice::redisKey($studentAId, TrustedDevice::hashToken($studentAId, $trustedToken));
        $this->assertNotNull(RedisClient::get($expectedRedisKey));

        // Subsequent login for student A WITH trusted-device cookie bypasses 2FA -> 200 OK
        $trustedLoginResp = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/login', [
                'username' => $phone,
                'password' => $password,
            ], ['Origin' => 'http://localhost'], [TrustedDevice::cookieName() => $trustedToken])
        );
        $this->assertSuccessResponse($trustedLoginResp, ['token', 'user']);

        // Student B using Student A's trusted-device cookie must NOT bypass 2FA
        $studentBAttempt = $controller->login(
            $this->jsonRequest('POST', '/api/v1/auth/login', [
                'username' => '09777777007',
                'password' => $password,
            ], ['Origin' => 'http://localhost'], [TrustedDevice::cookieName() => $trustedToken]),
            new Response()
        );
        $this->assertSame(202, $studentBAttempt->getStatusCode());

        // 3. ALWAYS mode -> even Student A with a valid trusted-device cookie requires 2FA
        $_ENV['STUDENT_2FA_MODE'] = 'always';
        $alwaysResp = $controller->login(
            $this->jsonRequest('POST', '/api/v1/auth/login', [
                'username' => $phone,
                'password' => $password,
            ], ['Origin' => 'http://localhost'], [TrustedDevice::cookieName() => $trustedToken]),
            new Response()
        );
        $this->assertSame(202, $alwaysResp->getStatusCode());
    }

    public function testStudent2faRejectsPlaceholderPhoneWith422AndLogsNeverLeakSensitiveData(): void
    {
        $logFile = __DIR__ . '/../../../storage/logs/app.log';
        $beforeSize = is_file($logFile) ? (int) filesize($logFile) : 0;

        $_ENV['STUDENT_2FA_MODE'] = 'always';
        $this->seedUser('prtest_placeholder', '09000000000', 'pass1234', 'student', 1);

        $service = new AuthService($this->fakeSms);
        try {
            $service->login('prtest_placeholder', 'pass1234');
            $this->fail('Placeholder phone 09000000000 must be rejected when student 2FA is active');
        } catch (\App\Core\ApiException $e) {
            $this->assertSame(422, $e->getHttpStatus());
            $this->assertSame('2FA_PHONE_REQUIRED', $e->getErrorCode());
        }

        // Force SMS failure on forgotPassword and verify logs contain neither OTP nor full phone
        $realPhone = '09777777008';
        $this->seedUser('prtest_log_hygiene', $realPhone, 'pass1234', 'student', 1);
        $this->fakeSms->shouldSucceed = false;
        $service->forgotPassword($realPhone);

        $this->assertCount(1, $this->fakeSms->sent);
        $capturedOtp = $this->fakeSms->sent[0]['code'];

        if (is_file($logFile)) {
            $content = (string) file_get_contents($logFile, false, null, $beforeSize);
            $this->assertStringNotContainsString($capturedOtp, $content);
            $this->assertStringNotContainsString($realPhone, $content);
        }
    }

    public function testEnvValidatorAcceptsAllowedStudent2faModesAndRejectsInvalid(): void
    {
        foreach (['off', 'new_device', 'always'] as $validMode) {
            $_ENV['STUDENT_2FA_MODE'] = $validMode;
            $errors = EnvValidator::validateOptionalFormats();
            $this->assertSame([], array_values(array_filter(
                $errors,
                static fn (string $msg): bool => str_contains($msg, 'STUDENT_2FA_MODE')
            )));
        }

        $_ENV['STUDENT_2FA_MODE'] = 'sms_only';
        $errors = EnvValidator::validateOptionalFormats();
        $this->assertNotEmpty(array_filter(
            $errors,
            static fn (string $msg): bool => str_contains($msg, 'STUDENT_2FA_MODE')
        ));
    }
}
