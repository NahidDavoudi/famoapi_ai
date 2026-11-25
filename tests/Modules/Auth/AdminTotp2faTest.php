<?php
declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Core\Auth;
use App\Core\Authorization;
use App\Core\EnvValidator;
use App\Core\RateLimiter;
use App\Core\RedisClient;
use App\Core\SecretBox;
use App\Core\SmsService;
use App\Core\TotpService;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\AuthService;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FA\Support\Constants as Google2FAConstants;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Tests\TestCase;

final class NoOpCapturingSmsService extends SmsService
{
    /** @var array<int, array{mobile: string, code: string}> */
    public array $sent = [];

    public function sendVerificationCode(string $mobile, string $code): bool
    {
        $this->sent[] = ['mobile' => $mobile, 'code' => $code];
        return true;
    }
}

class AdminTotp2faTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];
    private NoOpCapturingSmsService $fakeSms;
    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'ADMIN_2FA_CHAIN',
            'ADMIN_2FA_METHOD',
            'ADMIN_SMS_ENABLED',
            'TOTP_ENCRYPTION_KEY',
            'TOTP_ISSUER',
            'AUTH_2FA_MAX_ATTEMPTS',
            'AUTH_2FA_CHALLENGE_TTL',
            'AUTH_2FA_WINDOW_SECONDS',
            'AUTH_2FA_LOCKOUT_SECONDS',
            'TRUSTED_PROXIES',
            'TRUSTED_PROXY_HEADER',
        ] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
        }

        unset($_ENV['ADMIN_2FA_CHAIN']);
        $_ENV['ADMIN_2FA_METHOD'] = 'totp';
        $_ENV['ADMIN_SMS_ENABLED'] = 'true';
        $_ENV['TOTP_ENCRYPTION_KEY'] = 'unit-test-totp-encryption-key-32bytes-minimum!';
        $_ENV['TOTP_ISSUER'] = 'FAMO Academy';
        $_ENV['AUTH_2FA_MAX_ATTEMPTS'] = '5';
        $_ENV['AUTH_2FA_CHALLENGE_TTL'] = '300';
        $_ENV['AUTH_2FA_WINDOW_SECONDS'] = '600';
        $_ENV['AUTH_2FA_LOCKOUT_SECONDS'] = '600';
        $_ENV['TRUSTED_PROXIES'] = '';
        $_ENV['TRUSTED_PROXY_HEADER'] = '';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.55';

        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        \App\Core\TwoFactor\SmsCircuitBreaker::reset();

        $this->fakeSms = new NoOpCapturingSmsService();
        $this->google2fa = new Google2FA();
        $this->google2fa->setAlgorithm(Google2FAConstants::SHA1);
        $this->google2fa->setOneTimePasswordLength(6);
        $this->google2fa->setKeyRegeneration(30);

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

        \App\Core\TwoFactor\SmsCircuitBreaker::reset();
        RedisClient::reset();
        parent::tearDown();
    }

    private function cleanupTestRows(): void
    {
        try {
            $this->db->exec("DELETE FROM admin_2fa_challenges WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'totp_admin_%')");
        } catch (\Throwable) {
            // Table may not exist before migration 016 is applied
        }
        $this->db->exec("DELETE FROM login_codes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'totp_admin_%')");
        $this->db->exec("DELETE FROM users WHERE username LIKE 'totp_admin_%'");
        $this->db->exec("DELETE FROM auth_throttle");
    }

    private function seedAdmin(string $username, string $password = 'StrongAdmin#2026', string $phone = '09120001122'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, phone, password_hash, role, full_name, is_active, created_at)
             VALUES (:u, :p, :h, :r, :f, 1, NOW())'
        );
        $stmt->execute([
            'u' => $username,
            'p' => $phone,
            'h' => password_hash($password, PASSWORD_DEFAULT),
            'r' => 'admin',
            'f' => 'Admin ' . $username,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function makeController(): AuthController
    {
        return new AuthController(new AuthService($this->fakeSms, null, new TotpService($this->google2fa)));
    }

    private function requestWithIp(string $method, string $uri, array $body, string $ip, array $headers = [], array $cookies = []): ServerRequestInterface
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $req = $this->requestFactory->createServerRequest($method, $uri, $_SERVER);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        foreach (array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Origin' => 'http://localhost'], $headers) as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        if ($cookies !== []) {
            $req = $req->withCookieParams($cookies);
        }
        return $req;
    }

    public function testEnrollEncryptsSecretAtRestAndStoresOnlyHashedRecoveryCodes(): void
    {
        $adminId = $this->seedAdmin('totp_admin_enroll');
        $totp = new TotpService($this->google2fa);

        $enrolled = $totp->enrollAdmin($adminId, 'FAMO Academy');

        $this->assertSame($adminId, $enrolled['user_id']);
        $this->assertGreaterThanOrEqual(32, strlen($enrolled['secret']));
        $this->assertCount(8, $enrolled['recovery_codes']);
        $this->assertStringStartsWith('otpauth://totp/', $enrolled['otpauth_uri']);
        $this->assertStringContainsString('secret=' . $enrolled['secret'], $enrolled['otpauth_uri']);
        $this->assertStringContainsString('algorithm=SHA1', $enrolled['otpauth_uri']);
        $this->assertStringContainsString('digits=6', $enrolled['otpauth_uri']);
        $this->assertStringContainsString('period=30', $enrolled['otpauth_uri']);

        $row = $this->fetchOne('users', ['id' => $adminId]);
        $this->assertSame(1, (int) $row['totp_enabled']);
        $this->assertNull($row['totp_last_used_step']);

        // Secret MUST be encrypted at rest (never plaintext Base32)
        $storedSecret = (string) $row['totp_secret'];
        $this->assertStringStartsWith('v1:', $storedSecret);
        $this->assertStringNotContainsString($enrolled['secret'], $storedSecret);
        $this->assertSame(
            $enrolled['secret'],
            SecretBox::decrypt($storedSecret, 'user:' . $adminId . ':totp_secret')
        );

        // Wrong user AAD must fail decryption
        $this->expectException(\RuntimeException::class);
        try {
            SecretBox::decrypt($storedSecret, 'user:' . ($adminId + 1) . ':totp_secret');
        } finally {
            // Recovery codes MUST be hashed (none stored in plaintext)
            $storedCodesRaw = (string) $row['recovery_codes'];
            foreach ($enrolled['recovery_codes'] as $plainCode) {
                $this->assertStringNotContainsString($plainCode, $storedCodesRaw);
                $this->assertStringNotContainsString(str_replace('-', '', $plainCode), $storedCodesRaw);
            }
            $decodedHashes = json_decode($storedCodesRaw, true);
            $this->assertIsArray($decodedHashes);
            $this->assertCount(8, $decodedHashes);
        }
    }

    public function testValidTotpLoginIssuesVerifiedSessionWithZeroSmsAndRejectsReplay(): void
    {
        $adminId = $this->seedAdmin('totp_admin_login', 'SecretPass#99');
        $totp = new TotpService($this->google2fa);
        $enrolled = $totp->enrollAdmin($adminId);

        $controller = $this->makeController();
        $ip = '198.51.100.60';

        // Step 1: Username + password -> 202 requires_2fa (two_fa_method = totp), ZERO SMS sent
        $loginResp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_login',
                'password' => 'SecretPass#99',
            ], $ip),
            new Response()
        );
        $loginData = $this->assertJsonResponse($loginResp, 202, ['requires_2fa', 'two_fa_method', 'user_id', 'challenge_token']);
        $this->assertTrue($loginData['data']['requires_2fa']);
        $this->assertSame('totp', $loginData['data']['two_fa_method']);
        $this->assertNull($loginData['data']['phone_mask']);
        $this->assertCount(0, $this->fakeSms->sent, 'SMS must not be dispatched when ADMIN_2FA_METHOD=totp');

        // Prior unverified token sent alongside verify-2fa should be blacklisted on session regeneration
        $priorToken = Auth::encode(['id' => $adminId, 'role' => 'admin', 'two_fa_verified' => false]);
        $priorClaims = Auth::decode($priorToken);

        // Step 2: Submit current valid 6-digit RFC 6238 code
        $validCode = $this->google2fa->getCurrentOtp($enrolled['secret']);
        $verifyResp = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $validCode,
                'challenge_token' => $loginData['data']['challenge_token'],
            ], $ip, ['Authorization' => 'Bearer ' . $priorToken]),
            new Response()
        );
        $verifyData = $this->assertSuccessResponse($verifyResp, ['token', 'user', 'auth_method']);
        $this->assertSame('totp', $verifyData['data']['auth_method']);

        $newToken = (string) $verifyData['data']['token'];
        $newClaims = Auth::decode($newToken);
        $this->assertNotSame($priorClaims->jti, $newClaims->jti, 'Fresh session jti must be generated after 2FA');
        $this->assertTrue($newClaims->two_fa_verified);
        $this->assertSame(['pwd', 'totp'], $newClaims->amr);

        // Verified token grants access to protected routes
        $meResp = $this->handleRequest(
            $this->requestWithIp('GET', '/api/v1/auth/me', [], $ip, ['Authorization' => 'Bearer ' . $newToken])
        );
        $this->assertSuccessResponse($meResp, ['id', 'username', 'role']);

        // Replay check: even after starting a fresh password login, reusing the exact same TOTP code MUST fail!
        $login2Resp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_login',
                'password' => 'SecretPass#99',
            ], $ip),
            new Response()
        );
        $login2Data = $this->assertJsonResponse($login2Resp, 202, ['challenge_token']);

        $replayResp = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $validCode,
                'challenge_token' => $login2Data['data']['challenge_token'],
            ], $ip),
            new Response()
        );
        $this->assertJsonResponse($replayResp, 401, null, '2FA_ERROR');
    }

    public function testDriftWindowAllowsPlusMinusOneStepAndRejectsOlderOrTwoStepsAway(): void
    {
        $adminId = $this->seedAdmin('totp_admin_drift');
        $totp = new TotpService($this->google2fa);
        $enrolled = $totp->enrollAdmin($adminId);

        $baseStep = 59000000; // Deterministic 30s time-step
        $baseTimestamp = $baseStep * 30 + 10;

        $codeMinus2 = $this->google2fa->oathHotp($enrolled['secret'], $baseStep - 2);
        $codeMinus1 = $this->google2fa->oathHotp($enrolled['secret'], $baseStep - 1);
        $codePlus1 = $this->google2fa->oathHotp($enrolled['secret'], $baseStep + 1);
        $codePlus2 = $this->google2fa->oathHotp($enrolled['secret'], $baseStep + 2);

        $userRow = $this->fetchOne('users', ['id' => $adminId]);

        // -2 and +2 steps outside ±1 window are rejected
        foreach ([$codeMinus2, $codePlus2] as $outOfWindow) {
            try {
                $totp->verifyAdminCode($userRow, $outOfWindow, $baseTimestamp);
                $this->fail('Code outside ±1 window must be rejected');
            } catch (\App\Core\ApiException $e) {
                $this->assertSame(401, $e->getHttpStatus());
                $this->assertSame('2FA_ERROR', $e->getErrorCode());
            }
        }

        // -1 step is accepted
        $resMinus1 = $totp->verifyAdminCode($userRow, $codeMinus1, $baseTimestamp);
        $this->assertSame('totp', $resMinus1['method']);
        $this->assertSame($baseStep - 1, $resMinus1['matched_step']);

        // Reload row; same step (-1) is rejected as replay
        $userRow = $this->fetchOne('users', ['id' => $adminId]);
        $this->assertSame($baseStep - 1, (int) $userRow['totp_last_used_step']);
        try {
            $totp->verifyAdminCode($userRow, $codeMinus1, $baseTimestamp);
            $this->fail('Reused time-step must be rejected');
        } catch (\App\Core\ApiException $e) {
            $this->assertSame('2FA_ERROR', $e->getErrorCode());
        }

        // +1 step is strictly greater than last_used_step -> accepted
        $resPlus1 = $totp->verifyAdminCode($userRow, $codePlus1, $baseTimestamp);
        $this->assertSame($baseStep + 1, $resPlus1['matched_step']);

        // Current step (baseStep < baseStep + 1) is now older than last_used_step -> rejected
        $userRow = $this->fetchOne('users', ['id' => $adminId]);
        $codeCurrent = $this->google2fa->oathHotp($enrolled['secret'], $baseStep);
        try {
            $totp->verifyAdminCode($userRow, $codeCurrent, $baseTimestamp);
            $this->fail('Older time-step <= totp_last_used_step must be rejected');
        } catch (\App\Core\ApiException $e) {
            $this->assertSame('2FA_ERROR', $e->getErrorCode());
        }
    }

    public function testRecoveryCodeWorksOnceAndIsImmediatelyInvalidated(): void
    {
        $adminId = $this->seedAdmin('totp_admin_recovery', 'RecoverPass#1');
        $totp = new TotpService($this->google2fa);
        $enrolled = $totp->enrollAdmin($adminId);
        $firstRecovery = $enrolled['recovery_codes'][0];

        $controller = $this->makeController();
        $ip = '198.51.100.61';

        // Step 1
        $loginResp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_recovery',
                'password' => 'RecoverPass#1',
            ], $ip),
            new Response()
        );
        $loginData = $this->assertJsonResponse($loginResp, 202, ['challenge_token']);

        // Step 2 using lowercase recovery code without hyphen -> accepted once
        $verifyResp = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => strtolower(str_replace('-', '', $firstRecovery)),
                'challenge_token' => $loginData['data']['challenge_token'],
            ], $ip),
            new Response()
        );
        $verifyData = $this->assertSuccessResponse($verifyResp, ['token', 'user', 'auth_method', 'remaining_recovery_codes']);
        $this->assertSame('recovery', $verifyData['data']['auth_method']);
        $this->assertSame(7, $verifyData['data']['remaining_recovery_codes']);

        // Attempting to reuse the same recovery code on a new login MUST fail
        $login2Resp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_recovery',
                'password' => 'RecoverPass#1',
            ], $ip),
            new Response()
        );
        $login2Data = $this->assertJsonResponse($login2Resp, 202, ['challenge_token']);

        $reuseResp = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $firstRecovery,
                'challenge_token' => $login2Data['data']['challenge_token'],
            ], $ip),
            new Response()
        );
        $this->assertJsonResponse($reuseResp, 401, null, '2FA_ERROR');
        $this->assertSame(7, $totp->statusForUser($adminId)['remaining_recovery_codes']);
    }

    public function testRateLimitEnforcesFiveAttemptsPerTenMinutesPerAccountAndPerIp(): void
    {
        $adminA = $this->seedAdmin('totp_admin_rl_a', 'PassA#1234', '09120002201');
        $adminB = $this->seedAdmin('totp_admin_rl_b', 'PassB#1234', '09120002202');
        $totp = new TotpService($this->google2fa);
        $totp->enrollAdmin($adminA);
        $totp->enrollAdmin($adminB);

        $controller = $this->makeController();

        // 1. Per-account cap: 5 failures for adminA across 5 distinct IPs -> 6th attempt from a 6th IP is 429
        for ($i = 1; $i <= 5; $i++) {
            $resp = $controller->verify2fa(
                $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                    'user_id' => $adminA,
                    'code'    => '000000',
                ], '198.51.100.' . (10 + $i)),
                new Response()
            );
            $this->assertJsonResponse($resp, 401, null, '2FA_ERROR');
        }

        $accountLocked = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id' => $adminA,
                'code'    => '000000',
            ], '198.51.100.99'),
            new Response()
        );
        $this->assertJsonResponse($accountLocked, 429, null, 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $accountLocked->getHeaderLine('Retry-After'));

        // 2. Per-IP cap: 5 failures from one shared IP across different user IDs -> 6th attempt from that IP is 429
        $sharedIp = '203.0.113.77';
        for ($i = 0; $i < 5; $i++) {
            $resp = $controller->verify2fa(
                $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                    'user_id' => 999000 + $i,
                    'code'    => '000000',
                ], $sharedIp),
                new Response()
            );
            $this->assertJsonResponse($resp, 401, null, '2FA_ERROR');
        }

        $ipLocked = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id' => $adminB,
                'code'    => '000000',
            ], $sharedIp),
            new Response()
        );
        $this->assertJsonResponse($ipLocked, 429, null, 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $ipLocked->getHeaderLine('Retry-After'));
    }

    public function testUnverifiedAdminTokenIsBlockedByAuthMiddlewareAndAuthorization(): void
    {
        $adminId = $this->seedAdmin('totp_admin_guard');

        // Default Auth::encode sets two_fa_verified = false
        $unverifiedToken = Auth::encode(['id' => $adminId, 'role' => 'admin']);
        $decoded = Auth::decode($unverifiedToken);
        $this->assertFalse($decoded->two_fa_verified);

        $blockedRes = $this->handleRequest(
            $this->requestWithIp('GET', '/api/v1/auth/me', [], '198.51.100.62', [
                'Authorization' => 'Bearer ' . $unverifiedToken,
            ])
        );
        $this->assertJsonResponse($blockedRes, 401, null, 'UNAUTHORIZED');

        // Defense-in-depth: Authorization('admin') also rejects admin user object without two_fa_verified === true
        $authz = new Authorization('admin');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $denied = $authz->process(
            $this->createRequest('GET', '/admin-guard')->withAttribute('user', (object) ['role' => 'admin', 'two_fa_verified' => false]),
            $handler
        );
        $this->assertSame(403, $denied->getStatusCode());

        $allowed = $authz->process(
            $this->createRequest('GET', '/admin-guard')->withAttribute('user', (object) ['role' => 'admin', 'two_fa_verified' => true]),
            $handler
        );
        $this->assertSame(200, $allowed->getStatusCode());
    }

    public function testSwitchBackToSmsModeAndUnenrolledAdminGenericErrorAndReset(): void
    {
        $adminId = $this->seedAdmin('totp_admin_switch', 'SwitchPass#1', '09120003344');
        $controller = $this->makeController();
        $ip = '198.51.100.63';

        // In totp mode, unenrolled admin gets generic 401 AUTH_ERROR (same as wrong password)
        $unenrolledResp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_switch',
                'password' => 'SwitchPass#1',
            ], $ip),
            new Response()
        );
        $this->assertJsonResponse($unenrolledResp, 401, null, 'AUTH_ERROR');
        $this->assertCount(0, $this->fakeSms->sent);

        // Switching ADMIN_2FA_METHOD=sms restores the SMS OTP flow intact
        $_ENV['ADMIN_2FA_METHOD'] = 'sms';
        $smsLoginResp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_switch',
                'password' => 'SwitchPass#1',
            ], $ip),
            new Response()
        );
        $smsLoginData = $this->assertJsonResponse($smsLoginResp, 202, ['requires_2fa', 'two_fa_method', 'phone_mask']);
        $this->assertSame('sms', $smsLoginData['data']['two_fa_method']);
        $this->assertCount(1, $this->fakeSms->sent);

        $smsVerifyResp = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id' => $adminId,
                'code'    => $this->fakeSms->sent[0]['code'],
            ], $ip),
            new Response()
        );
        $smsVerifyData = $this->assertSuccessResponse($smsVerifyResp, ['token', 'user']);
        $this->assertTrue(Auth::decode((string) $smsVerifyData['data']['token'])->two_fa_verified);

        // Enroll then reset wipes all TOTP columns cleanly
        $totp = new TotpService($this->google2fa);
        $totp->enrollAdmin($adminId);
        $this->assertTrue($totp->statusForUser($adminId)['totp_enabled']);

        $totp->resetAdmin($adminId);
        $afterReset = $this->fetchOne('users', ['id' => $adminId]);
        $this->assertSame(0, (int) $afterReset['totp_enabled']);
        $this->assertNull($afterReset['totp_secret']);
        $this->assertNull($afterReset['totp_last_used_step']);
        $this->assertNull($afterReset['recovery_codes']);
    }

    public function testEnvValidatorAcceptsTotpAndSmsAndRejectsInvalidAdmin2faMethod(): void
    {
        foreach (['totp', 'sms'] as $valid) {
            $_ENV['ADMIN_2FA_METHOD'] = $valid;
            $errors = EnvValidator::validateOptionalFormats();
            $this->assertSame([], array_values(array_filter(
                $errors,
                static fn (string $m): bool => str_contains($m, 'ADMIN_2FA_METHOD')
            )));
        }

        $_ENV['ADMIN_2FA_METHOD'] = 'email';
        $errors = EnvValidator::validateOptionalFormats();
        $this->assertNotEmpty(array_filter(
            $errors,
            static fn (string $m): bool => str_contains($m, 'ADMIN_2FA_METHOD')
        ));
    }

    public function testLogsNeverContainTotpSecretOtpCodeOrRecoveryCode(): void
    {
        $logFile = __DIR__ . '/../../../storage/logs/app.log';
        $beforeSize = is_file($logFile) ? (int) filesize($logFile) : 0;

        $adminId = $this->seedAdmin('totp_admin_loghygiene', 'HygienePass#1');
        $totp = new TotpService($this->google2fa);
        $enrolled = $totp->enrollAdmin($adminId);
        $otpCode = $this->google2fa->getCurrentOtp($enrolled['secret']);
        $recoveryCode = $enrolled['recovery_codes'][0];

        $controller = $this->makeController();
        $ip = '198.51.100.64';

        $loginResp = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'totp_admin_loghygiene',
                'password' => 'HygienePass#1',
            ], $ip),
            new Response()
        );
        $loginData = $this->assertJsonResponse($loginResp, 202, ['challenge_token']);

        // Failed + valid TOTP + recovery attempts
        $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => '111111',
                'challenge_token' => $loginData['data']['challenge_token'],
            ], $ip),
            new Response()
        );
        $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $otpCode,
                'challenge_token' => $loginData['data']['challenge_token'],
            ], $ip),
            new Response()
        );

        if (is_file($logFile)) {
            $logDelta = (string) file_get_contents($logFile, false, null, $beforeSize);
            $this->assertStringNotContainsString($enrolled['secret'], $logDelta);
            $this->assertStringNotContainsString($otpCode, $logDelta);
            $this->assertStringNotContainsString($recoveryCode, $logDelta);
        }
    }
}
