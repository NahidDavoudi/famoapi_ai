<?php
declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Core\Auth;
use App\Core\JwtRevocation;
use App\Core\RedisClient;
use App\Modules\Students\StudentService;
use App\Modules\Supporters\SupporterService;
use Tests\TestCase;

class JwtRevocationTest extends TestCase
{
    private string $tmpLog;
    private string $tmpState;
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        $this->tmpLog = sys_get_temp_dir() . '/famo_jwt_rev_' . bin2hex(random_bytes(4)) . '.log';
        $this->tmpState = sys_get_temp_dir() . '/famo_jwt_rev_state_' . bin2hex(random_bytes(4)) . '.json';
        RedisClient::setLogPath($this->tmpLog);
        RedisClient::setDowntimeStatePath($this->tmpState);

        foreach (['AUTH_REVOCATION_FAIL_MODE', 'AUTH_REVOCATION_ADMIN_FAIL_MODE'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
        }
        unset($_ENV['AUTH_REVOCATION_FAIL_MODE'], $_ENV['AUTH_REVOCATION_ADMIN_FAIL_MODE']);

        $this->db->exec("DELETE FROM users WHERE username LIKE 'revtest_%'");
        $this->db->exec("DELETE FROM students WHERE phone LIKE '09888888%'");
        $this->db->exec("DELETE FROM supporters WHERE phone LIKE '09888888%'");
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        $this->db->exec("DELETE FROM users WHERE username LIKE 'revtest_%'");
        $this->db->exec("DELETE FROM students WHERE phone LIKE '09888888%'");
        $this->db->exec("DELETE FROM supporters WHERE phone LIKE '09888888%'");
        RedisClient::reset();
        @unlink($this->tmpLog);
        @unlink($this->tmpState);
        parent::tearDown();
    }

    private function createUser(string $username, string $password, string $role = 'student', int $linkedId = 0, int $isActive = 1): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, phone, password_hash, role, full_name, linked_id, is_active, created_at)
             VALUES (:u, :p, :h, :r, :f, :l, :a, NOW())'
        );
        $stmt->execute([
            'u' => $username,
            'p' => str_starts_with($username, '09') ? $username : null,
            'h' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
            'f' => 'Revocation Test User',
            'l' => $linkedId,
            'a' => $isActive,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function testEncodeIncludesUniqueJtiAndAccessType(): void
    {
        $t1 = Auth::encode(['id' => 99, 'role' => 'student']);
        $t2 = Auth::encode(['id' => 99, 'role' => 'student']);

        $d1 = Auth::decode($t1);
        $d2 = Auth::decode($t2);

        $this->assertNotEmpty($d1->jti ?? '');
        $this->assertNotSame($d1->jti, $d2->jti);
        $this->assertSame('access', $d1->typ ?? null);
    }

    public function testLogoutBlacklistsTokenAndClearsCookie(): void
    {
        $userId = $this->createUser('revtest_logout', 'secret123', 'student');
        $token = Auth::encode(['id' => $userId, 'role' => 'student']);

        // Token works before logout
        $before = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $token,
            ])
        );
        $this->assertSame(200, $before->getStatusCode());

        // Logout clears cookie and blacklists jti
        $logout = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/logout', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $token,
            ])
        );
        $this->assertSame(200, $logout->getStatusCode());
        $this->assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));

        // Token is rejected with 401 afterwards
        $after = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $token,
            ])
        );
        $this->assertJsonResponse($after, 401, null, 'UNAUTHORIZED');
    }

    public function testChangePasswordRevokesOldTokensAndReturnsFreshActiveSession(): void
    {
        $userId = $this->createUser('revtest_chpwd', 'oldPass123', 'student');
        $now = time();
        $oldToken = Auth::encode([
            'id'   => $userId,
            'role' => 'student',
            'iat'  => $now - 5,
            'exp'  => $now + 3600,
        ]);

        $res = $this->handleRequest(
            $this->jsonRequest('POST', '/api/v1/auth/change-password', [
                'current_password' => 'oldPass123',
                'new_password'     => 'newPass456',
            ], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $oldToken,
            ])
        );
        $body = $this->assertSuccessResponse($res, ['message', 'token', 'user']);
        $newToken = $body['data']['token'];
        $this->assertNotSame($oldToken, $newToken);
        $this->assertStringContainsString('famo_jwt=' . $newToken, $res->getHeaderLine('Set-Cookie'));

        // Old token (iat < epoch) is revoked
        $oldAttempt = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $oldToken,
            ])
        );
        $this->assertJsonResponse($oldAttempt, 401, null, 'UNAUTHORIZED');

        // Fresh token (iat >= epoch) succeeds immediately
        $newAttempt = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $newToken,
            ])
        );
        $this->assertSame(200, $newAttempt->getStatusCode());
    }

    public function testAdminResetPasswordGeneratesRandomPasswordAndRevokesPriorTokens(): void
    {
        $this->db->exec(
            "INSERT INTO students (name, national_id, grade, field, phone, is_active)
             VALUES ('شاگرد تستی', '1234500001', 12, 'ریاضی', '09888888001', 1)"
        );
        $studentId = (int) $this->db->lastInsertId();
        $userId = $this->createUser('09888888001', 'initialPass', 'student', $studentId, 1);

        $oldToken = Auth::encode([
            'id'         => $userId,
            'role'       => 'student',
            'student_id' => $studentId,
            'iat'        => time() - 10,
            'exp'        => time() + 3600,
        ]);

        $svc = new StudentService();
        $updated = $svc->resetPassword($studentId);

        $this->assertArrayHasKey('temporary_password', $updated);
        $this->assertGreaterThanOrEqual(10, strlen((string) $updated['temporary_password']));
        $this->assertNotSame('1234', $updated['temporary_password']);
        $this->assertTrue(JwtRevocation::isRevoked(Auth::decode($oldToken)));

        // Toggle status deactivates both students.is_active & users.is_active and reactivates both
        $toggledOff = $svc->toggleStatus($studentId);
        $this->assertSame(0, (int) $toggledOff['is_active']);
        $uRowOff = $this->fetchOne('users', ['id' => $userId]);
        $this->assertSame(0, (int) $uRowOff['is_active']);

        $toggledOn = $svc->toggleStatus($studentId);
        $this->assertSame(1, (int) $toggledOn['is_active']);
        $uRowOn = $this->fetchOne('users', ['id' => $userId]);
        $this->assertSame(1, (int) $uRowOn['is_active']);
    }

    public function testSupporterStatusSyncBothDirectionsAndAuthMiddlewareRejectsInactiveUser(): void
    {
        $this->db->exec(
            "INSERT INTO supporters (name, grade, field, phone, is_active)
             VALUES ('پشتیبان تست', 12, 'ریاضی', '09888888002', 1)"
        );
        $supporterId = (int) $this->db->lastInsertId();
        $userId = $this->createUser('09888888002', 'suppPass', 'supporter', $supporterId, 1);

        $svc = new SupporterService();
        $svc->update($supporterId, ['is_active' => 0]);
        $this->assertSame(0, (int) $this->fetchOne('users', ['id' => $userId])['is_active']);

        // Even with a freshly minted token, AuthMiddleware rejects inactive user
        $token = Auth::encode(['id' => $userId, 'role' => 'supporter', 'iat' => time() + 1]);
        $res = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $token,
            ])
        );
        $this->assertJsonResponse($res, 401, null, 'UNAUTHORIZED');

        // Reactivate syncs back to 1
        $svc->update($supporterId, ['is_active' => 1]);
        $this->assertSame(1, (int) $this->fetchOne('users', ['id' => $userId])['is_active']);
    }

    public function testFailModeOpenForStudentsAndClosedForAdminWhenRedisIsDown(): void
    {
        $studentId = $this->createUser('revtest_open_stu', 'secret123', 'student');
        $adminId = $this->createUser('revtest_closed_adm', 'secret123', 'admin');

        $studentToken = Auth::encode(['id' => $studentId, 'role' => 'student']);
        $adminToken = Auth::encode(['id' => $adminId, 'role' => 'admin', 'two_fa_verified' => true]);

        RedisClient::simulateFailure(true, 'Redis connection lost');

        $stuRes = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $studentToken,
            ])
        );
        $this->assertSame(200, $stuRes->getStatusCode());

        $admRes = $this->handleRequest(
            $this->jsonRequest('GET', '/api/v1/auth/me', [], [
                'Origin'        => 'http://localhost',
                'Authorization' => 'Bearer ' . $adminToken,
            ])
        );
        $this->assertJsonResponse($admRes, 401, null, 'UNAUTHORIZED');
        $this->assertStringContainsString('[CRITICAL]', (string) file_get_contents($this->tmpLog));
    }
}
