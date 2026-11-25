<?php
declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Core\Auth;
use App\Core\Authorization;
use App\Core\RedisClient;
use App\Core\SmsService;
use App\Core\TotpService;
use App\Core\TwoFactor\AdminTwoFactorService;
use App\Core\TwoFactor\EmailChannel;
use App\Core\TwoFactor\SmsChannel;
use App\Core\TwoFactor\SmsCircuitBreaker;
use App\Core\TwoFactor\TotpChannel;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\AuthService;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FA\Support\Constants as Google2FAConstants;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

final class ProgrammableSmsService extends SmsService
{
    public bool $shouldSucceed = true;
    public ?\Throwable $exceptionToThrow = null;
    public string $errorMessage = 'Melipayamak subscription expired';
    /** @var array<int, array{mobile: string, code: string}> */
    public array $sent = [];

    public function sendVerificationCode(string $mobile, string $code): bool
    {
        $this->sent[] = ['mobile' => $mobile, 'code' => $code];
        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }
        return $this->shouldSucceed;
    }

    public function getLastError(): ?string
    {
        return $this->shouldSucceed ? null : $this->errorMessage;
    }
}

class AdminMultiChannel2faTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];
    private ProgrammableSmsService $fakeSms;
    /** @var array<int, array{to: string, subject: string, body: string, code: string}> */
    private array $sentEmails = [];
    private bool $emailShouldFail = false;
    private string $emailFailureMessage = 'SMTP connect() failed: 535 Authentication failed';
    private Google2FA $google2fa;
    private int $fakeNow;
    private string $tempLogPath;
    private string $tempCircuitPath;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'ADMIN_2FA_CHAIN',
            'ADMIN_2FA_METHOD',
            'ADMIN_SMS_ENABLED',
            'MAIL_HOST',
            'MAIL_PORT',
            'MAIL_ENCRYPTION',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_FROM',
            'MAIL_FROM_NAME',
            'TOTP_ENCRYPTION_KEY',
            'TOTP_ISSUER',
            'AUTH_2FA_MAX_ATTEMPTS',
            'AUTH_2FA_CHALLENGE_TTL',
            'AUTH_2FA_WINDOW_SECONDS',
            'AUTH_2FA_LOCKOUT_SECONDS',
            'AUTH_2FA_SEND_USER_HOURLY_MAX',
            'AUTH_2FA_SEND_IP_HOURLY_MAX',
            'TRUSTED_PROXIES',
            'TRUSTED_PROXY_HEADER',
        ] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['ADMIN_2FA_CHAIN'] = 'sms,email,totp';
        $_ENV['ADMIN_2FA_METHOD'] = 'totp';
        $_ENV['ADMIN_SMS_ENABLED'] = 'true';
        $_ENV['MAIL_HOST'] = 'smtp.gmail.com';
        $_ENV['MAIL_PORT'] = '465';
        $_ENV['MAIL_ENCRYPTION'] = 'ssl';
        $_ENV['MAIL_USERNAME'] = 'noreply@famoacademy.ir';
        $_ENV['MAIL_PASSWORD'] = 'super-secret-gmail-app-password-xyz';
        $_ENV['MAIL_FROM'] = 'noreply@famoacademy.ir';
        $_ENV['MAIL_FROM_NAME'] = 'FAMO Academy';
        $_ENV['TOTP_ENCRYPTION_KEY'] = 'unit-test-multi-channel-totp-key-32bytes!';
        $_ENV['TOTP_ISSUER'] = 'FAMO Academy';
        $_ENV['AUTH_2FA_MAX_ATTEMPTS'] = '5';
        $_ENV['AUTH_2FA_CHALLENGE_TTL'] = '300';
        $_ENV['AUTH_2FA_WINDOW_SECONDS'] = '600';
        $_ENV['AUTH_2FA_LOCKOUT_SECONDS'] = '600';
        $_ENV['AUTH_2FA_SEND_USER_HOURLY_MAX'] = '20';
        $_ENV['AUTH_2FA_SEND_IP_HOURLY_MAX'] = '30';
        $_ENV['TRUSTED_PROXIES'] = '';
        $_ENV['TRUSTED_PROXY_HEADER'] = '';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';

        $this->fakeNow = 1760000000;
        $this->tempLogPath = sys_get_temp_dir() . '/famo_admin_2fa_test_' . bin2hex(random_bytes(6)) . '.log';
        $this->tempCircuitPath = sys_get_temp_dir() . '/famo_sms_cb_test_' . bin2hex(random_bytes(6)) . '.json';

        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        SmsCircuitBreaker::setStatePath($this->tempCircuitPath);
        SmsCircuitBreaker::setClock(fn(): int => $this->fakeNow);
        SmsCircuitBreaker::reset();

        $this->fakeSms = new ProgrammableSmsService();
        $this->sentEmails = [];
        $this->emailShouldFail = false;

        $this->google2fa = new Google2FA();
        $this->google2fa->setAlgorithm(Google2FAConstants::SHA1);
        $this->google2fa->setOneTimePasswordLength(6);
        $this->google2fa->setKeyRegeneration(30);

        $this->ensureChallengesTableExists();
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

        SmsCircuitBreaker::reset();
        SmsCircuitBreaker::setClock(null);
        SmsCircuitBreaker::setStatePath(null);
        RedisClient::reset();

        if (is_file($this->tempLogPath)) {
            @unlink($this->tempLogPath);
        }
        if (is_file($this->tempCircuitPath)) {
            @unlink($this->tempCircuitPath);
        }

        parent::tearDown();
    }

    private function ensureChallengesTableExists(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/016_admin_2fa_challenges.sql');
        if (is_string($sql) && trim($sql) !== '') {
            $this->db->exec($sql);
        }
    }

    private function cleanupTestRows(): void
    {
        $this->db->exec("DELETE FROM admin_2fa_challenges WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'mc2fa_admin_%')");
        $this->db->exec("DELETE FROM login_codes WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'mc2fa_admin_%')");
        $this->db->exec("DELETE FROM users WHERE username LIKE 'mc2fa_admin_%'");
        $this->db->exec('DELETE FROM auth_throttle');
    }

    private function seedAdmin(
        string $username,
        string $password = 'StrongAdmin#2026',
        string $phone = '09120004455',
        ?string $email = 'admin@famoacademy.ir'
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, phone, email, password_hash, role, full_name, is_active, created_at)
             VALUES (:u, :p, :e, :h, :r, :f, 1, NOW())'
        );
        $stmt->execute([
            'u' => $username,
            'p' => $phone,
            'e' => $email,
            'h' => password_hash($password, PASSWORD_DEFAULT),
            'r' => 'admin',
            'f' => 'Admin ' . $username,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function makeController(): AuthController
    {
        $totpService = new TotpService($this->google2fa);
        $emailChannel = new EmailChannel(function (array $payload): void {
            if ($this->emailShouldFail) {
                throw new \RuntimeException($this->emailFailureMessage . ' password=' . ($_ENV['MAIL_PASSWORD'] ?? ''));
            }
            preg_match('/\b(\d{6})\b/', (string) ($payload['text_body'] ?? ''), $m);
            $this->sentEmails[] = [
                'to'      => (string) $payload['to'],
                'subject' => (string) $payload['subject'],
                'body'    => (string) $payload['text_body'],
                'code'    => $m[1] ?? '',
            ];
        });

        $admin2fa = new AdminTwoFactorService(
            new SmsChannel($this->fakeSms),
            $emailChannel,
            new TotpChannel($totpService),
            fn(): int => $this->fakeNow,
            $this->tempLogPath
        );

        return new AuthController(new AuthService($this->fakeSms, null, $totpService, $admin2fa));
    }

    private function requestWithIp(string $method, string $uri, array $body, string $ip): ServerRequestInterface
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $req = $this->requestFactory->createServerRequest($method, $uri, $_SERVER);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $req
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', 'http://localhost');
    }

    /**
     * Scenario 1: SMS disabled (ADMIN_SMS_ENABLED=false) -> SMS skipped without calling provider ->
     * Email OTP sent & accepted -> verified JWT issued with two_fa_verified=true.
     */
    public function testSmsDisabledSkipsImmediatelyAndFallsBackToEmail(): void
    {
        $_ENV['ADMIN_SMS_ENABLED'] = 'false';

        $adminId = $this->seedAdmin('mc2fa_admin_email_ok', 'StrongAdmin#2026', '09120004401', 'ops@famoacademy.ir');
        (new TotpService($this->google2fa))->enrollAdmin($adminId, 'FAMO Academy');
        $controller = $this->makeController();

        $loginRes = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_email_ok',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.10'),
            $this->createResponse()
        );
        $this->assertSame(202, $loginRes->getStatusCode());
        $loginJson = json_decode((string) $loginRes->getBody(), true);

        $this->assertTrue($loginJson['data']['requires_2fa']);
        $this->assertSame('email', $loginJson['data']['channel']);
        $this->assertSame('Code sent via email', $loginJson['data']['channel_message']);
        $this->assertCount(0, $this->fakeSms->sent, 'SMS provider must not be called when ADMIN_SMS_ENABLED=false');
        $this->assertCount(1, $this->sentEmails);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sentEmails[0]['code']);
        $this->assertStringContainsString('5 minutes', $this->sentEmails[0]['body']);
        $this->assertStringContainsString('change your password', $this->sentEmails[0]['body']);

        // Confirm OTP is never stored in plaintext in DB
        $row = $this->db->query('SELECT otp_salt, otp_hash FROM admin_2fa_challenges WHERE user_id = ' . $adminId . ' ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertNotEmpty($row['otp_salt']);
        $this->assertNotEmpty($row['otp_hash']);
        $this->assertNotSame($this->sentEmails[0]['code'], $row['otp_hash']);

        // Verify email OTP
        $verifyRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $this->sentEmails[0]['code'],
                'challenge_token' => $loginJson['data']['challenge_token'],
            ], '203.0.113.10'),
            $this->createResponse()
        );
        $this->assertSame(200, $verifyRes->getStatusCode());
        $verifyJson = json_decode((string) $verifyRes->getBody(), true);
        $this->assertSame('email', $verifyJson['data']['auth_method']);
        $decoded = Auth::verifyToken((string) $verifyJson['data']['token']);
        $this->assertTrue((bool) ($decoded['two_fa_verified'] ?? false));
        $this->assertTrue(Authorization::hasAdminTwoFaVerified($decoded));
    }

    /**
     * Scenario 2: SMS fails + Email fails -> falls back directly to Authenticator app (TOTP)
     * without erroring out, and accepts valid RFC 6238 TOTP code.
     */
    public function testSmsAndEmailBothFailFallsBackDirectlyToTotp(): void
    {
        $adminId = $this->seedAdmin('mc2fa_admin_totp_fb', 'StrongAdmin#2026', '09120004402', 'ops2@famoacademy.ir');
        $enrolled = (new TotpService($this->google2fa))->enrollAdmin($adminId, 'FAMO Academy');

        $this->fakeSms->shouldSucceed = false;
        $this->emailShouldFail = true;
        $controller = $this->makeController();

        $loginRes = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_totp_fb',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.11'),
            $this->createResponse()
        );

        $this->assertSame(202, $loginRes->getStatusCode());
        $loginJson = json_decode((string) $loginRes->getBody(), true);
        $this->assertSame('totp', $loginJson['data']['channel']);
        $this->assertSame('Enter the code from your authenticator app', $loginJson['data']['channel_message']);
        $this->assertCount(1, $this->fakeSms->sent);

        $totpCode = $this->google2fa->getCurrentOtp($enrolled['secret']);
        $verifyRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $totpCode,
                'challenge_token' => $loginJson['data']['challenge_token'],
            ], '203.0.113.11'),
            $this->createResponse()
        );
        $this->assertSame(200, $verifyRes->getStatusCode());
        $verifyJson = json_decode((string) $verifyRes->getBody(), true);
        $this->assertSame('totp', $verifyJson['data']['auth_method']);
    }

    /**
     * Scenario 3: Invalid, expired (>5 min), and reused OTP codes are all rejected with generic 401.
     */
    public function testInvalidExpiredAndReusedOtpRejected(): void
    {
        $_ENV['ADMIN_SMS_ENABLED'] = 'false';
        $adminId = $this->seedAdmin('mc2fa_admin_expiry', 'StrongAdmin#2026', '09120004403', 'ops3@famoacademy.ir');
        $controller = $this->makeController();

        // Login #1 -> invalid code then valid code then reuse
        $login1 = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_expiry',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.12'),
            $this->createResponse()
        );
        $j1 = json_decode((string) $login1->getBody(), true);
        $otp1 = $this->sentEmails[0]['code'];
        $wrongCode = $otp1 === '000000' ? '111111' : '000000';

        $badRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $wrongCode,
                'challenge_token' => $j1['data']['challenge_token'],
            ], '203.0.113.12'),
            $this->createResponse()
        );
        $this->assertSame(401, $badRes->getStatusCode());

        // Valid code succeeds once
        $okRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $otp1,
                'challenge_token' => $j1['data']['challenge_token'],
            ], '203.0.113.12'),
            $this->createResponse()
        );
        $this->assertSame(200, $okRes->getStatusCode());

        // Reusing the same OTP must fail
        $reuseRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $otp1,
                'challenge_token' => $j1['data']['challenge_token'],
            ], '203.0.113.12'),
            $this->createResponse()
        );
        $this->assertSame(401, $reuseRes->getStatusCode());

        // Login #2 -> advance clock by 301s (>5 min) -> expired code rejected
        $login2 = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_expiry',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.13'),
            $this->createResponse()
        );
        $j2 = json_decode((string) $login2->getBody(), true);
        $otp2 = $this->sentEmails[1]['code'];

        $this->fakeNow += 301;
        $expiredRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $otp2,
                'challenge_token' => $j2['data']['challenge_token'],
            ], '203.0.113.13'),
            $this->createResponse()
        );
        $this->assertSame(401, $expiredRes->getStatusCode());
    }

    /**
     * Scenario 4: Single-use recovery code works once and fails on reuse.
     */
    public function testRecoveryCodeWorksOnceAndFailsOnReuse(): void
    {
        $_ENV['ADMIN_SMS_ENABLED'] = 'false';
        $adminId = $this->seedAdmin('mc2fa_admin_recov', 'StrongAdmin#2026', '09120004404', 'ops4@famoacademy.ir');
        $enrolled = (new TotpService($this->google2fa))->enrollAdmin($adminId, 'FAMO Academy');
        $recoveryCode = $enrolled['recovery_codes'][0];
        $controller = $this->makeController();

        $login1 = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_recov',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.14'),
            $this->createResponse()
        );
        $j1 = json_decode((string) $login1->getBody(), true);

        $okRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $recoveryCode,
                'challenge_token' => $j1['data']['challenge_token'],
            ], '203.0.113.14'),
            $this->createResponse()
        );
        $this->assertSame(200, $okRes->getStatusCode());
        $okJson = json_decode((string) $okRes->getBody(), true);
        $this->assertSame('recovery', $okJson['data']['auth_method']);
        $this->assertSame(7, $okJson['data']['remaining_recovery_codes']);

        // Second login with same recovery code must fail
        $login2 = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_recov',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.15'),
            $this->createResponse()
        );
        $j2 = json_decode((string) $login2->getBody(), true);

        $reuseRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $recoveryCode,
                'challenge_token' => $j2['data']['challenge_token'],
            ], '203.0.113.15'),
            $this->createResponse()
        );
        $this->assertSame(401, $reuseRes->getStatusCode());
    }

    /**
     * Scenario 5: Rate limits (verify lockout after 5 attempts, 60s resend cooldown, max 3 sends/session)
     * plus SMS circuit breaker (3 consecutive failures -> skip SMS for 10 minutes).
     */
    public function testRateLimitsCooldownMaxSendsAndSmsCircuitBreaker(): void
    {
        $adminId = $this->seedAdmin('mc2fa_admin_limits', 'StrongAdmin#2026', '09120004405', 'ops5@famoacademy.ir');
        (new TotpService($this->google2fa))->enrollAdmin($adminId, 'FAMO Academy');
        $controller = $this->makeController();

        // Part A: SMS circuit breaker opens after 3 consecutive SMS failures -> 4th attempt skips SMS completely
        $this->fakeSms->shouldSucceed = false;
        for ($i = 1; $i <= 3; $i++) {
            $res = $controller->login(
                $this->requestWithIp('POST', '/api/v1/auth/login', [
                    'username' => 'mc2fa_admin_limits',
                    'password' => 'StrongAdmin#2026',
                ], '203.0.113.2' . $i),
                $this->createResponse()
            );
            $this->assertSame(202, $res->getStatusCode());
            $j = json_decode((string) $res->getBody(), true);
            $this->assertSame('email', $j['data']['channel']);
        }
        $this->assertCount(3, $this->fakeSms->sent);
        $this->assertTrue(SmsCircuitBreaker::isOpen());

        // 4th login within 10 minutes: SMS must NOT be called at all (circuit open -> immediate email fallback)
        $res4 = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_limits',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.24'),
            $this->createResponse()
        );
        $this->assertSame(202, $res4->getStatusCode());
        $j4 = json_decode((string) $res4->getBody(), true);
        $this->assertCount(3, $this->fakeSms->sent, 'Circuit breaker must skip SMS while open');
        $this->assertSame('email', $j4['data']['channel']);

        // Part B: 60s resend cooldown and max 3 sends per login session
        $tooSoon = $controller->resend2fa(
            $this->requestWithIp('POST', '/api/v1/auth/2fa/resend', [
                'user_id'         => $adminId,
                'challenge_token' => $j4['data']['challenge_token'],
            ], '203.0.113.24'),
            $this->createResponse()
        );
        $this->assertSame(429, $tooSoon->getStatusCode());
        $this->assertNotEmpty($tooSoon->getHeaderLine('Retry-After'));

        // Advance 61s -> send #2 allowed
        $this->fakeNow += 61;
        $resend2 = $controller->resend2fa(
            $this->requestWithIp('POST', '/api/v1/auth/2fa/resend', [
                'user_id'         => $adminId,
                'challenge_token' => $j4['data']['challenge_token'],
            ], '203.0.113.24'),
            $this->createResponse()
        );
        $this->assertSame(200, $resend2->getStatusCode());

        // Advance 61s -> send #3 allowed
        $this->fakeNow += 61;
        $resend3 = $controller->resend2fa(
            $this->requestWithIp('POST', '/api/v1/auth/2fa/resend', [
                'user_id'         => $adminId,
                'challenge_token' => $j4['data']['challenge_token'],
            ], '203.0.113.24'),
            $this->createResponse()
        );
        $this->assertSame(200, $resend3->getStatusCode());

        // Advance 61s -> send #4 blocked (max 3 sends/session)
        $this->fakeNow += 61;
        $resend4 = $controller->resend2fa(
            $this->requestWithIp('POST', '/api/v1/auth/2fa/resend', [
                'user_id'         => $adminId,
                'challenge_token' => $j4['data']['challenge_token'],
            ], '203.0.113.24'),
            $this->createResponse()
        );
        $this->assertSame(429, $resend4->getStatusCode());

        // Part C: 5 failed verify attempts trigger 429 lockout
        for ($a = 1; $a <= 5; $a++) {
            $fail = $controller->verify2fa(
                $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                    'user_id'         => $adminId,
                    'code'            => '00000' . $a,
                    'challenge_token' => $j4['data']['challenge_token'],
                ], '203.0.113.25'),
                $this->createResponse()
            );
            $this->assertSame(401, $fail->getStatusCode());
        }
        $locked = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => '123456',
                'challenge_token' => $j4['data']['challenge_token'],
            ], '203.0.113.25'),
            $this->createResponse()
        );
        $this->assertSame(429, $locked->getStatusCode());
    }

    /**
     * Scenario 6: Manual switch ("Didn't receive it? Send via email" and "Use authenticator app instead")
     * + log hygiene verifying zero OTP/secret/password leakage.
     */
    public function testManualSwitchToEmailAndAuthenticatorAndZeroSecretLogging(): void
    {
        $adminId = $this->seedAdmin('mc2fa_admin_switch', 'StrongAdmin#2026', '09120004406', 'ops6@famoacademy.ir');
        $enrolled = (new TotpService($this->google2fa))->enrollAdmin($adminId, 'FAMO Academy');
        $controller = $this->makeController();

        // SMS succeeds first
        $loginRes = $controller->login(
            $this->requestWithIp('POST', '/api/v1/auth/login', [
                'username' => 'mc2fa_admin_switch',
                'password' => 'StrongAdmin#2026',
            ], '203.0.113.30'),
            $this->createResponse()
        );
        $this->assertSame(202, $loginRes->getStatusCode());
        $loginJson = json_decode((string) $loginRes->getBody(), true);
        $this->assertSame('sms', $loginJson['data']['channel']);
        $this->assertSame('Code sent via SMS', $loginJson['data']['channel_message']);
        $this->assertTrue($loginJson['data']['secondary_actions']['send_via_email']);
        $this->assertTrue($loginJson['data']['secondary_actions']['use_authenticator']);
        $smsOtp = $this->fakeSms->sent[0]['code'];

        // Secondary action 1: "Didn't receive it? Send via email"
        $emailSwitchRes = $controller->switch2faChannel(
            $this->requestWithIp('POST', '/api/v1/auth/2fa/switch-channel', [
                'user_id'         => $adminId,
                'challenge_token' => $loginJson['data']['challenge_token'],
                'channel'         => 'email',
            ], '203.0.113.30'),
            $this->createResponse()
        );
        $this->assertSame(200, $emailSwitchRes->getStatusCode());
        $emailSwitchJson = json_decode((string) $emailSwitchRes->getBody(), true);
        $this->assertSame('email', $emailSwitchJson['data']['channel']);
        $this->assertSame('Code sent via email', $emailSwitchJson['data']['channel_message']);
        $this->assertCount(1, $this->sentEmails);
        $emailOtp = $this->sentEmails[0]['code'];

        // Secondary action 2: "Use authenticator app instead"
        $totpSwitchRes = $controller->switch2faChannel(
            $this->requestWithIp('POST', '/api/v1/auth/2fa/switch-channel', [
                'user_id'         => $adminId,
                'challenge_token' => $loginJson['data']['challenge_token'],
                'channel'         => 'totp',
            ], '203.0.113.30'),
            $this->createResponse()
        );
        $this->assertSame(200, $totpSwitchRes->getStatusCode());
        $totpSwitchJson = json_decode((string) $totpSwitchRes->getBody(), true);
        $this->assertSame('totp', $totpSwitchJson['data']['channel']);
        $this->assertSame('Enter the code from your authenticator app', $totpSwitchJson['data']['channel_message']);

        // Complete login with valid TOTP code
        $totpCode = $this->google2fa->getCurrentOtp($enrolled['secret']);
        $verifyRes = $controller->verify2fa(
            $this->requestWithIp('POST', '/api/v1/auth/verify-2fa', [
                'user_id'         => $adminId,
                'code'            => $totpCode,
                'challenge_token' => $loginJson['data']['challenge_token'],
            ], '203.0.113.30'),
            $this->createResponse()
        );
        $this->assertSame(200, $verifyRes->getStatusCode());
        $verifyJson = json_decode((string) $verifyRes->getBody(), true);
        $this->assertSame('totp', $verifyJson['data']['auth_method']);

        // Log hygiene: ensure no OTP, TOTP secret, recovery code, or MAIL_PASSWORD ever hit the log
        if (is_file($this->tempLogPath)) {
            $logText = (string) file_get_contents($this->tempLogPath);
            $this->assertStringNotContainsString($smsOtp, $logText);
            $this->assertStringNotContainsString($emailOtp, $logText);
            $this->assertStringNotContainsString($enrolled['secret'], $logText);
            $this->assertStringNotContainsString($enrolled['recovery_codes'][0], $logText);
            $this->assertStringNotContainsString((string) $_ENV['MAIL_PASSWORD'], $logText);
        }
    }
}
