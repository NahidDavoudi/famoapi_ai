<?php

namespace App\Modules\Auth;

use App\Core\Auth;
use App\Core\Database;
use App\Core\PhoneNumber;
use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\SmsService;

class AuthService
{
    private SmsService $sms;

    public function __construct(?SmsService $sms = null)
    {
        $this->sms = $sms ?? new SmsService();
    }

    /**
     * Linked student id, only meaningful for the student role.
     */
    private function studentIdFor(array $user): ?int
    {
        if (($user['role'] ?? '') !== 'student') {
            return null;
        }
        $id = (int) ($user['linked_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function instructorIdFor(array $user): ?int
    {
        if (($user['role'] ?? '') !== 'teacher') {
            return null;
        }
        $id = (int) ($user['linked_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    public function sessionForUser(int $userId): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }

        $studentId = $this->studentIdFor($user);
        $instructorId = $this->instructorIdFor($user);

        $token = Auth::encode([
            'id'            => $user['id'],
            'role'          => $user['role'],
            'student_id'    => $studentId,
            'instructor_id' => $instructorId,
        ]);

        return [
            'token' => $token,
            'user'  => [
                'id'            => (int) $user['id'],
                'username'      => $user['username'],
                'role'          => $user['role'],
                'student_id'    => $studentId,
                'instructor_id' => $instructorId,
            ],
        ];
    }

    public function consume2faCode(int $userId, string $code): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT * FROM login_codes
             WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $code]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
        }

        $stmt = $db->prepare(
            'UPDATE login_codes SET used = 1
             WHERE id = ? AND used = 0 AND expires_at > CURRENT_TIMESTAMP'
        );
        $stmt->execute([$record['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا قبلاً استفاده شده است', 401, '2FA_ERROR');
        }

        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }

        return $user;
    }

    public function createStudentAccount(array $data): array
    {
        $data['phone'] = PhoneNumber::normalizeOrFail($data['phone'] ?? null, 'شماره تلفن همراه معتبر نیست');

        if (User::findByPhone($data['phone'])) {
            throw new \App\Core\ApiException('این شماره تلفن قبلاً ثبت‌نام کرده است', 409, 'REGISTRATION_ERROR');
        }

        $db = Database::getConnection();
        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $userId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM users')->fetchColumn();

            $createdId = User::create([
                'id'        => $userId,
                'full_name' => $data['name'],
                'username'  => $data['phone'],
                'phone'     => $data['phone'],
                'password'  => $data['password'],
                'role'      => 'student',
            ]);
            if ($createdId > 0) {
                $userId = $createdId;
            }

            $stmt = $db->prepare(
                'INSERT INTO students (name, national_id, grade, field, phone)
                 VALUES (:name, :national_id, :grade, :field, :phone)'
            );
            $stmt->execute([
                'name'        => $data['name'],
                'national_id' => $data['nationalId'],
                'grade'       => $data['grade'],
                'field'       => $data['field'],
                'phone'       => $data['phone'],
            ]);
            $studentId = (int) $db->lastInsertId();
            $db->prepare('UPDATE users SET linked_id = :linked_id WHERE id = :id')
               ->execute(['linked_id' => $studentId, 'id' => $userId]);

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'userId'    => $userId,
            'studentId' => $studentId,
        ];
    }

    public function login(string $username, string $password, ?string $ip = null): array
    {
        $user = User::findByUsername($username) ?? User::findByPhone($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new \App\Core\ApiException('نام کاربری یا رمز عبور اشتباه است', 401, 'AUTH_ERROR');
        }

        if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
            throw new \App\Core\ApiException('حساب کاربری غیرفعال است', 403, 'FORBIDDEN');
        }

        $role = $user['role'];

        if ($role === 'admin' || $role === 'supporter' || $role === 'teacher') {
            // TODO: برای فعال‌سازی روی سایر نقش‌ها (مثلاً student)، این شرط را گسترش دهید
            $rawMobile = !empty($user['phone']) ? (string) $user['phone'] : (string) $user['username'];
            $mobile = PhoneNumber::normalize($rawMobile) ?? $rawMobile;
            $smsWindow = 3600;
            $phoneMax = max(1, (int) ($_ENV['AUTH_SMS_PHONE_HOURLY_MAX'] ?? 5));
            $phoneKey = RateLimiter::key('sms:phone', $mobile);
            $phoneStatus = RateLimiter::status($phoneKey, $phoneMax);
            if (!$phoneStatus['allowed']) {
                throw new RateLimitedException($phoneStatus['retry_after'] > 0 ? $phoneStatus['retry_after'] : $smsWindow);
            }

            $ipMax = max(1, (int) ($_ENV['AUTH_SMS_IP_HOURLY_MAX'] ?? 20));
            $ipKey = null;
            if ($ip !== null) {
                $ipKey = RateLimiter::key('sms:ip', $ip);
                $ipStatus = RateLimiter::status($ipKey, $ipMax);
                if (!$ipStatus['allowed']) {
                    throw new RateLimitedException($ipStatus['retry_after'] > 0 ? $ipStatus['retry_after'] : $smsWindow);
                }
            }

            RateLimiter::attempt($phoneKey, $phoneMax, $smsWindow, $smsWindow);
            if ($ipKey !== null) {
                RateLimiter::attempt($ipKey, $ipMax, $smsWindow, $smsWindow);
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 300); // 5 minutes

            $db = Database::getConnection();
            $db->prepare('UPDATE login_codes SET used = 1 WHERE user_id = ? AND used = 0')
               ->execute([$user['id']]);
            $stmt = $db->prepare(
                'INSERT INTO login_codes (user_id, code, expires_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$user['id'], $code, $expiresAt]);

            $sent = $this->sms->sendVerificationCode($mobile, $code);

            if (!$sent) {
                error_log('[' . date('Y-m-d H:i:s') . '] AuthService: Failed to send 2FA code to user ' . $user['id'] . PHP_EOL, 3, __DIR__ . '/../../../storage/logs/app.log');
                throw new \App\Core\ApiException('ارسال کد تأیید با مشکل مواجه شد. لطفاً دقایقی بعد تلاش کنید.', 500, 'AUTH_ERROR');
            }

            return [
                'requires_2fa' => true,
                'user_id'      => (int) $user['id'],
                'username'     => $user['username'],
                'role'         => $role,
                'phone_mask'   => PhoneNumber::mask($mobile),
            ];
        }

        $studentId = $this->studentIdFor($user);

        $token = Auth::encode([
            'id'         => $user['id'],
            'role'       => $role,
            'student_id' => $studentId,
        ]);

        return [
            'token' => $token,
            'user'  => [
                'id'         => (int) $user['id'],
                'username'   => $user['username'],
                'phone'      => $user['phone'] ?? $user['username'] ?? null,
                'role'       => $role,
                'student_id' => $studentId,
            ],
        ];
    }

    public function verify2fa(int $userId, string $code): array
    {
        $this->consume2faCode($userId, $code);

        return $this->sessionForUser($userId);
    }

    public function register(array $data): array
    {
        $account = $this->createStudentAccount($data);

        $session = $this->sessionForUser($account['userId']);
        unset($session['user']['instructor_id']);

        return $session;
    }

    public function me(int $userId): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }
        return [
            'id'            => (int) $user['id'],
            'username'      => $user['username'],
            'phone'         => $user['phone'] ?? $user['username'] ?? null,
            'role'          => $user['role'],
            'full_name'     => $user['full_name'] ?? null,
            'student_id'    => $this->studentIdFor($user),
            'instructor_id' => $this->instructorIdFor($user),
        ];
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }

        if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            throw new \App\Core\ApiException('رمز عبور فعلی اشتباه است', 401, 'INVALID_PASSWORD');
        }

        if (mb_strlen($newPassword) < 4) {
            throw new \App\Core\ApiException('رمز عبور جدید باید حداقل ۴ کاراکتر باشد', 422, 'VALIDATION_ERROR');
        }

        User::update($userId, ['password' => $newPassword]);
    }

    public function updateProfile(int $userId, array $data): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }

        $fullName = trim((string) ($data['full_name'] ?? $data['name'] ?? ''));
        if ($fullName === '' || mb_strlen($fullName) < 2) {
            throw new \App\Core\ApiException('نام و نام خانوادگی معتبر نیست', 422, 'VALIDATION_ERROR');
        }

        $normalizedPhone = PhoneNumber::normalizeOrFail($data['phone'] ?? null, 'شماره تلفن همراه معتبر نیست');

        $oldPhoneRaw = !empty($user['phone']) ? (string) $user['phone'] : '';
        $oldPhone = $oldPhoneRaw !== '' ? (PhoneNumber::normalize($oldPhoneRaw) ?? $oldPhoneRaw) : '';
        $currentUsername = (string) ($user['username'] ?? '');
        $normalizedUsername = PhoneNumber::normalize($currentUsername) ?? $currentUsername;

        $effectiveOldPhone = $oldPhone !== '' ? $oldPhone : (PhoneNumber::isValid($currentUsername) ? $normalizedUsername : '');
        $phoneChanged = ($normalizedPhone !== $effectiveOldPhone);

        $role = (string) ($user['role'] ?? '');
        $twoFaRoles = ['admin', 'supporter', 'teacher'];

        if ($phoneChanged && in_array($role, $twoFaRoles, true)) {
            $currentPassword = isset($data['current_password']) && is_string($data['current_password'])
                ? $data['current_password']
                : '';

            if ($currentPassword === '') {
                throw new \App\Core\ApiException(
                    'برای تغییر شماره تلفن، وارد کردن رمز عبور فعلی الزامی است',
                    422,
                    'VALIDATION_ERROR'
                );
            }

            if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
                throw new \App\Core\ApiException('رمز عبور فعلی اشتباه است', 401, 'INVALID_PASSWORD');
            }
        }

        $db = Database::getConnection();

        $dupPhoneStmt = $db->prepare('SELECT id FROM users WHERE phone = :phone AND id != :id LIMIT 1');
        $dupPhoneStmt->execute(['phone' => $normalizedPhone, 'id' => $userId]);
        if ($dupPhoneStmt->fetch()) {
            throw new \App\Core\ApiException('این شماره تلفن قبلاً برای کاربر دیگری ثبت شده است', 409, 'DUPLICATE_PHONE');
        }

        $syncUsername = ($oldPhoneRaw !== '' && $currentUsername === $oldPhoneRaw)
            || ($effectiveOldPhone !== '' && $normalizedUsername === $effectiveOldPhone);

        if ($syncUsername && $normalizedPhone !== $currentUsername) {
            $dupUserStmt = $db->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
            $dupUserStmt->execute(['username' => $normalizedPhone, 'id' => $userId]);
            if ($dupUserStmt->fetch()) {
                throw new \App\Core\ApiException('این شماره تلفن قبلاً برای کاربر دیگری ثبت شده است', 409, 'DUPLICATE_PHONE');
            }
        }

        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $userUpdate = [
                'full_name' => $fullName,
                'phone'     => $normalizedPhone,
            ];
            if ($syncUsername) {
                $userUpdate['username'] = $normalizedPhone;
            }
            User::update($userId, $userUpdate);

            $linkedId = (int) ($user['linked_id'] ?? 0);
            if ($linkedId > 0) {
                if ($role === 'admin') {
                    $stmt = $db->prepare('UPDATE admins SET name = :name, phone = :phone WHERE id = :id');
                    $stmt->execute(['name' => $fullName, 'phone' => $normalizedPhone, 'id' => $linkedId]);
                } elseif ($role === 'supporter') {
                    $stmt = $db->prepare('UPDATE supporters SET name = :name, phone = :phone WHERE id = :id');
                    $stmt->execute(['name' => $fullName, 'phone' => $normalizedPhone, 'id' => $linkedId]);
                } elseif ($role === 'student') {
                    $stmt = $db->prepare('UPDATE students SET name = :name, phone = :phone WHERE id = :id');
                    $stmt->execute(['name' => $fullName, 'phone' => $normalizedPhone, 'id' => $linkedId]);
                } elseif ($role === 'teacher') {
                    $stmt = $db->prepare('UPDATE instructors SET name = :name WHERE id = :id');
                    $stmt->execute(['name' => $fullName, 'id' => $linkedId]);
                }
            }

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $this->me($userId);
    }
}
