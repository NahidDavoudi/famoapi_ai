<?php

namespace App\Modules\Auth;

use App\Core\Auth;
use App\Core\Database;
use App\Core\JwtRevocation;
use App\Core\OtpService;
use App\Core\PhoneNumber;
use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\RedisClient;
use App\Core\SmsService;
use App\Core\TotpService;
use App\Core\TrustedDevice;

class AuthService
{
    private const PASSWORD_RESET_TOKEN_TTL = 600;
    private const TOTP_CHALLENGE_SENTINEL = '__TOTP';

    private SmsService $sms;
    private OtpService $otp;
    private TotpService $totp;

    public function __construct(?SmsService $sms = null, ?OtpService $otp = null, ?TotpService $totp = null)
    {
        $this->sms = $sms ?? new SmsService();
        $this->otp = $otp ?? new OtpService();
        $this->totp = $totp ?? new TotpService();
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

    public function sessionForUser(int $userId, bool $twoFaVerified = false, ?array $amr = null): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }
        if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
            throw new \App\Core\ApiException('حساب کاربری غیرفعال است', 403, 'FORBIDDEN');
        }

        $studentId = $this->studentIdFor($user);
        $instructorId = $this->instructorIdFor($user);

        $claims = [
            'id'              => $user['id'],
            'role'            => $user['role'],
            'student_id'      => $studentId,
            'instructor_id'   => $instructorId,
            'two_fa_verified' => $twoFaVerified,
        ];
        if ($amr !== null) {
            $claims['amr'] = $amr;
        }

        $token = Auth::encode($claims);

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
        $normalizedCode = trim(strtr($code, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));

        // SMS codes are strictly 6 ASCII digits; prevents any non-numeric challenge sentinel from ever matching.
        if (preg_match('/^\d{6}$/', $normalizedCode) !== 1) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
        }

        $now = gmdate('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'SELECT * FROM login_codes
             WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $normalizedCode, $now]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
        }

        $stmt = $db->prepare(
            'UPDATE login_codes SET used = 1
             WHERE id = ? AND used = 0 AND expires_at > ?'
        );
        $stmt->execute([$record['id'], $now]);
        if ($stmt->rowCount() !== 1) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
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
            $createdId = User::create([
                'full_name' => $data['name'],
                'username'  => $data['phone'],
                'phone'     => $data['phone'],
                'password'  => $data['password'],
                'role'      => 'student',
            ]);
            $userId = $createdId > 0
                ? $createdId
                : (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM users')->fetchColumn();

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

    public function login(string $username, string $password, ?string $ip = null, ?string $trustedDeviceToken = null): array
    {
        $candidates = User::findCandidatesForLogin($username);
        if ($candidates === []) {
            $fallback = User::findByUsername($username) ?? User::findByPhone($username);
            if ($fallback !== null) {
                $candidates = [$fallback];
            }
        }

        $user = null;
        foreach ($candidates as $candidate) {
            if (isset($candidate['password_hash']) && password_verify($password, (string) $candidate['password_hash'])) {
                $user = $candidate;
                break;
            }
        }

        if (!$user) {
            throw new \App\Core\ApiException('نام کاربری یا رمز عبور اشتباه است', 401, 'AUTH_ERROR');
        }

        if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
            throw new \App\Core\ApiException('حساب کاربری غیرفعال است', 403, 'FORBIDDEN');
        }

        $role = $user['role'];

        if ($role === 'admin' && TotpService::admin2faMethod() === TotpService::METHOD_TOTP) {
            if (!TotpService::isEnrolled($user)) {
                // Log non-sensitive operator hint without revealing credentials or which factor failed to the caller
                error_log(
                    '[' . date('Y-m-d H:i:s') . '] AuthService: Admin user #' . (int) $user['id']
                    . ' attempted login without TOTP enrollment; run `php nad admin:totp-setup ' . (int) $user['id'] . '`' . PHP_EOL,
                    3,
                    __DIR__ . '/../../../storage/logs/app.log'
                );
                throw new \App\Core\ApiException('نام کاربری یا رمز عبور اشتباه است', 401, 'AUTH_ERROR');
            }

            $challengeToken = $this->createPendingTotpChallenge((int) $user['id']);

            return [
                'requires_2fa'    => true,
                'two_fa_method'   => TotpService::METHOD_TOTP,
                'user_id'         => (int) $user['id'],
                'username'        => $user['username'],
                'role'            => $role,
                'phone_mask'      => null,
                'challenge_token' => $challengeToken,
            ];
        }

        if ($role === 'admin' || $role === 'supporter' || $role === 'teacher') {
            $rawMobile = !empty($user['phone']) ? (string) $user['phone'] : (string) $user['username'];
            $mobile = PhoneNumber::normalize($rawMobile) ?? $rawMobile;
            $this->enforceSmsRateLimits($mobile, $ip);

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
                'requires_2fa'  => true,
                'two_fa_method' => TotpService::METHOD_SMS,
                'user_id'       => (int) $user['id'],
                'username'      => $user['username'],
                'role'          => $role,
                'phone_mask'    => PhoneNumber::mask($mobile),
            ];
        }

        if ($role === 'student') {
            $student2faMode = TrustedDevice::mode();
            $needsStudent2fa = $student2faMode === TrustedDevice::MODE_ALWAYS
                || ($student2faMode === TrustedDevice::MODE_NEW_DEVICE && !TrustedDevice::isTrusted((int) $user['id'], $trustedDeviceToken));

            if ($needsStudent2fa) {
                $rawMobile = !empty($user['phone']) ? (string) $user['phone'] : (string) $user['username'];
                $mobile = PhoneNumber::normalize($rawMobile);
                if ($mobile === null || $mobile === '09000000000') {
                    throw new \App\Core\ApiException(
                        'برای ورود دو مرحله‌ای ثبت شماره موبایل معتبر در حساب کاربری الزامی است',
                        422,
                        '2FA_PHONE_REQUIRED'
                    );
                }

                $this->enforceSmsRateLimits($mobile, $ip);

                $issued = $this->otp->issue(
                    OtpService::PURPOSE_LOGIN_2FA,
                    $mobile,
                    OtpService::DEFAULT_TTL,
                    OtpService::DEFAULT_MAX_ATTEMPTS,
                    ['user_id' => (int) $user['id']]
                );

                $sent = $this->sms->sendVerificationCode($mobile, $issued['code']);
                if (!$sent) {
                    $this->otp->invalidate(OtpService::PURPOSE_LOGIN_2FA, $mobile);
                    error_log('[' . date('Y-m-d H:i:s') . '] AuthService: Failed to send student 2FA code for user ' . $user['id'] . PHP_EOL, 3, __DIR__ . '/../../../storage/logs/app.log');
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

    public function verify2fa(int $userId, string $code, ?string $challengeToken = null): array
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
        }

        if (($user['role'] ?? '') === 'admin' && TotpService::admin2faMethod() === TotpService::METHOD_TOTP) {
            if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
            }

            // Ensure password factor (step 1) actually completed within the challenge window
            $this->assertPendingTotpChallenge($userId, $challengeToken);

            $verified = $this->totp->verifyAdminCode($user, $code);
            $this->consumePendingTotpChallenge($userId);

            $session = $this->sessionForUser($userId, true, ['pwd', $verified['method']]);
            $session['auth_method'] = $verified['method'];
            if ($verified['method'] === 'recovery' && isset($verified['remaining_recovery_codes'])) {
                $session['remaining_recovery_codes'] = $verified['remaining_recovery_codes'];
            }

            return $session;
        }

        if (($user['role'] ?? '') === 'student') {
            if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                throw new \App\Core\ApiException('حساب کاربری غیرفعال است', 403, 'FORBIDDEN');
            }

            $rawMobile = !empty($user['phone']) ? (string) $user['phone'] : (string) $user['username'];
            $mobile = PhoneNumber::normalize($rawMobile);
            if ($mobile === null || $mobile === '09000000000') {
                throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
            }

            $meta = $this->otp->verify(OtpService::PURPOSE_LOGIN_2FA, $mobile, $code);
            if (isset($meta['user_id']) && (int) $meta['user_id'] !== $userId) {
                throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
            }

            return $this->sessionForUser($userId, true, ['pwd', 'sms']);
        }

        $this->consume2faCode($userId, $code);

        return $this->sessionForUser($userId, true, ['pwd', 'sms']);
    }

    /**
     * Record a server-side pending TOTP challenge after password verification succeeds,
     * so `/auth/verify-2fa` cannot be called without first passing step 1.
     */
    private function createPendingTotpChallenge(int $userId): string
    {
        $ttl = max(60, (int) ($_ENV['AUTH_2FA_CHALLENGE_TTL'] ?? 300));
        $challengeToken = bin2hex(random_bytes(32));
        $tokenHash = $this->hashChallengeToken($userId, $challengeToken);

        $db = Database::getConnection();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
        $db->prepare('UPDATE login_codes SET used = 1 WHERE user_id = ? AND used = 0')
           ->execute([$userId]);
        $db->prepare('INSERT INTO login_codes (user_id, code, expires_at) VALUES (?, ?, ?)')
           ->execute([$userId, self::TOTP_CHALLENGE_SENTINEL, $expiresAt]);

        if (RedisClient::isAvailable()) {
            RedisClient::setex(self::totpChallengeRedisKey($userId), $ttl, $tokenHash);
        }

        return $challengeToken;
    }

    private function assertPendingTotpChallenge(int $userId, ?string $challengeToken): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id FROM login_codes
             WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, self::TOTP_CHALLENGE_SENTINEL, $now]);
        $dbChallenge = $stmt->fetch();

        $redisHash = RedisClient::isAvailable()
            ? RedisClient::get(self::totpChallengeRedisKey($userId))
            : null;

        if (!$dbChallenge && $redisHash === null) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
        }

        if ($challengeToken !== null && trim($challengeToken) !== '' && $redisHash !== null) {
            $expected = $this->hashChallengeToken($userId, trim($challengeToken));
            if (!hash_equals($redisHash, $expected)) {
                throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, '2FA_ERROR');
            }
        }
    }

    private function consumePendingTotpChallenge(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare('UPDATE login_codes SET used = 1 WHERE user_id = ? AND code = ? AND used = 0')
               ->execute([$userId, self::TOTP_CHALLENGE_SENTINEL]);
        } catch (\Throwable) {
        }

        if (RedisClient::isAvailable()) {
            RedisClient::del(self::totpChallengeRedisKey($userId));
        }
    }

    private function hashChallengeToken(int $userId, string $token): string
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        return hash_hmac('sha256', $userId . '|' . $token, $secret);
    }

    private static function totpChallengeRedisKey(int $userId): string
    {
        return '2fa:totp_challenge:' . $userId;
    }

    /**
     * Step 1 of SMS-OTP password reset: issue & send a purpose-bound `password_reset` OTP.
     * Returns an identical response regardless of phone existence or SMS outcome (anti-enumeration)
     * and equalizes timing across branches.
     *
     * @return array{message: string, expires_in: int}
     */
    public function forgotPassword(string $rawPhone, ?string $username = null): array
    {
        $startNs = hrtime(true);
        $phone = PhoneNumber::normalizeOrFail($rawPhone, 'شماره تلفن همراه معتبر نیست');

        if (!RedisClient::isAvailable()) {
            throw new \App\Core\ApiException(
                'سرویس کد تأیید موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        $otpIdentifier = $this->passwordResetIdentifier($phone, $username);
        $user = $this->resolveSingleActiveUserForReset($phone, $username);

        if ($user !== null) {
            $issued = $this->otp->issue(
                OtpService::PURPOSE_PASSWORD_RESET,
                $otpIdentifier,
                OtpService::DEFAULT_TTL,
                OtpService::DEFAULT_MAX_ATTEMPTS,
                ['user_id' => (int) $user['id']]
            );

            $sent = $this->sms->sendVerificationCode($phone, $issued['code']);
            if (!$sent) {
                $this->otp->invalidate(OtpService::PURPOSE_PASSWORD_RESET, $otpIdentifier);
                error_log(
                    '[' . date('Y-m-d H:i:s') . '] AuthService: Failed to send password reset OTP for user ' . $user['id'] . PHP_EOL,
                    3,
                    __DIR__ . '/../../../storage/logs/app.log'
                );
            }
        } else {
            $this->otp->simulateIssueWork(OtpService::PURPOSE_PASSWORD_RESET, $otpIdentifier);
        }

        $this->equalizeForgotTiming($startNs);

        return [
            'message'    => 'در صورت وجود حساب کاربری فعال، کد تأیید به شماره موبایل شما ارسال شد.',
            'expires_in' => OtpService::DEFAULT_TTL,
        ];
    }

    /**
     * Step 2 of SMS-OTP password reset: verify the `password_reset` OTP (fail closed if Redis is down)
     * and mint a short-lived (10 min), single-use signed reset token.
     *
     * @return array{reset_token: string, expires_in: int}
     */
    public function verifyPasswordResetOtp(string $rawPhone, string $code, ?string $username = null): array
    {
        $phone = PhoneNumber::normalizeOrFail($rawPhone, 'شماره تلفن همراه معتبر نیست');

        if (!RedisClient::isAvailable()) {
            throw new \App\Core\ApiException(
                'سرویس تأیید کد موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        if ($phone === '09000000000') {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        $otpIdentifier = $this->passwordResetIdentifier($phone, $username);
        $meta = $this->otp->verify(OtpService::PURPOSE_PASSWORD_RESET, $otpIdentifier, $code);

        $user = $this->resolveSingleActiveUserForReset($phone, $username);
        if (!$user) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }
        if (isset($meta['user_id']) && (int) $meta['user_id'] !== (int) $user['id']) {
            throw new \App\Core\ApiException('کد تأیید نامعتبر یا منقضی شده است', 401, 'OTP_INVALID');
        }

        $jti = bin2hex(random_bytes(16));
        $ttl = self::PASSWORD_RESET_TOKEN_TTL;
        $stored = RedisClient::setex(self::passwordResetTokenRedisKey($jti), $ttl, (string) $user['id']);
        if (!$stored) {
            throw new \App\Core\ApiException(
                'سرویس بازنشانی رمز عبور موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        $resetToken = Auth::encodeWithKey([
            'jti' => $jti,
            'typ' => 'password_reset',
            'sub' => (int) $user['id'],
        ], self::passwordResetSigningKey(), $ttl);

        return [
            'reset_token' => $resetToken,
            'expires_in'  => $ttl,
        ];
    }

    /**
     * Step 3 of SMS-OTP password reset: validate & consume single-use reset token, update password,
     * clear `must_change_password` if present in DB schema, and revoke all existing JWT sessions.
     *
     * @return array{message: string}
     */
    public function resetPasswordWithToken(string $token, string $newPassword): array
    {
        if (!RedisClient::isAvailable()) {
            throw new \App\Core\ApiException(
                'سرویس بازنشانی رمز عبور موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }

        if (mb_strlen($newPassword) < 4) {
            throw new \App\Core\ApiException('رمز عبور جدید باید حداقل ۴ کاراکتر باشد', 422, 'VALIDATION_ERROR');
        }

        $trimmedToken = trim($token);
        if ($trimmedToken === '') {
            throw new \App\Core\ApiException('توکن بازنشانی رمز عبور نامعتبر یا منقضی شده است', 401, 'INVALID_RESET_TOKEN');
        }

        try {
            $claims = Auth::decodeWithKey($trimmedToken, self::passwordResetSigningKey());
        } catch (\Throwable) {
            throw new \App\Core\ApiException('توکن بازنشانی رمز عبور نامعتبر یا منقضی شده است', 401, 'INVALID_RESET_TOKEN');
        }

        $typ = isset($claims->typ) && is_string($claims->typ) ? $claims->typ : '';
        $jti = isset($claims->jti) && is_string($claims->jti) ? $claims->jti : '';
        $userId = isset($claims->sub) ? (int) $claims->sub : 0;

        if ($typ !== 'password_reset' || preg_match('/^[0-9a-f]{32}$/', $jti) !== 1 || $userId <= 0) {
            throw new \App\Core\ApiException('توکن بازنشانی رمز عبور نامعتبر یا منقضی شده است', 401, 'INVALID_RESET_TOKEN');
        }

        $redisKey = self::passwordResetTokenRedisKey($jti);
        $storedUserId = RedisClient::get($redisKey);
        if (!RedisClient::isAvailable()) {
            throw new \App\Core\ApiException(
                'سرویس بازنشانی رمز عبور موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }
        if ($storedUserId === null || !hash_equals((string) $userId, $storedUserId)) {
            throw new \App\Core\ApiException('توکن بازنشانی رمز عبور نامعتبر، منقضی یا قبلاً استفاده شده است', 401, 'INVALID_RESET_TOKEN');
        }

        $deleted = RedisClient::del($redisKey);
        if (!RedisClient::isAvailable()) {
            throw new \App\Core\ApiException(
                'سرویس بازنشانی رمز عبور موقتاً در دسترس نیست. لطفاً دقایقی بعد تلاش کنید.',
                503,
                'OTP_SERVICE_UNAVAILABLE'
            );
        }
        if ($deleted !== 1) {
            throw new \App\Core\ApiException('توکن بازنشانی رمز عبور نامعتبر، منقضی یا قبلاً استفاده شده است', 401, 'INVALID_RESET_TOKEN');
        }

        $user = User::findById($userId);
        if (!$user) {
            throw new \App\Core\ApiException('کاربر یافت نشد', 404, 'NOT_FOUND');
        }
        if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
            throw new \App\Core\ApiException('حساب کاربری غیرفعال است', 403, 'FORBIDDEN');
        }

        User::update($userId, ['password' => $newPassword]);
        $this->clearMustChangePasswordIfPresent($userId);
        JwtRevocation::revokeAllForUser($userId);

        return [
            'message' => 'رمز عبور با موفقیت بازنشانی شد',
        ];
    }

    private function enforceSmsRateLimits(string $mobile, ?string $ip): void
    {
        $cfg = RateLimiter::config();

        $fwPhone = RateLimiter::attemptFixedWindow(
            RateLimiter::key('sms:phone_win', $mobile),
            (int) ($cfg['sms_phone_max'] ?? 3),
            (int) ($cfg['sms_phone_window'] ?? 600)
        );
        if (!$fwPhone['allowed']) {
            throw new RateLimitedException($fwPhone['retry_after'] > 0 ? $fwPhone['retry_after'] : (int) ($cfg['sms_phone_window'] ?? 600));
        }

        if ($ip !== null && $ip !== '') {
            $fwIp = RateLimiter::attemptFixedWindow(
                RateLimiter::key('sms:ip_win', $ip),
                (int) ($cfg['sms_ip_max'] ?? 10),
                (int) ($cfg['sms_ip_window'] ?? 3600)
            );
            if (!$fwIp['allowed']) {
                throw new RateLimitedException($fwIp['retry_after'] > 0 ? $fwIp['retry_after'] : (int) ($cfg['sms_ip_window'] ?? 3600));
            }
        }

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
    }

    private function resolveSingleActiveUserForReset(string $canonicalPhone, ?string $username): ?array
    {
        if ($canonicalPhone === '09000000000') {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM users WHERE (phone = :phone OR username = :username) AND is_active = 1 ORDER BY id ASC'
        );
        $stmt->execute([
            'phone'    => $canonicalPhone,
            'username' => $canonicalPhone,
        ]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return null;
        }

        $trimmedUsername = $username !== null ? trim($username) : '';
        if ($trimmedUsername !== '') {
            $normUsername = PhoneNumber::normalize($trimmedUsername);
            $filtered = array_values(array_filter($rows, static function (array $row) use ($trimmedUsername, $normUsername): bool {
                $rowUser = (string) ($row['username'] ?? '');
                if (strcasecmp($rowUser, $trimmedUsername) === 0) {
                    return true;
                }
                if ($normUsername !== null) {
                    $normRowUser = PhoneNumber::normalize($rowUser);
                    return $normRowUser === $normUsername;
                }
                return false;
            }));
            $rows = $filtered;
        }

        // Refuse ambiguous shared phones unless uniquely narrowed to one active user
        if (count($rows) !== 1) {
            return null;
        }

        return $rows[0];
    }

    private function passwordResetIdentifier(string $canonicalPhone, ?string $username): string
    {
        $trimmed = $username !== null ? trim($username) : '';
        if ($trimmed === '') {
            return $canonicalPhone;
        }
        $normUsername = PhoneNumber::normalize($trimmed);
        if ($normUsername === $canonicalPhone) {
            return $canonicalPhone;
        }

        return $canonicalPhone . '|' . strtolower($trimmed);
    }

    private function equalizeForgotTiming(int $startNs): void
    {
        $rawFloor = $_ENV['PASSWORD_FORGOT_TIMING_FLOOR_MS'] ?? getenv('PASSWORD_FORGOT_TIMING_FLOOR_MS');
        $floorMs = is_numeric($rawFloor) ? max(0, min(2000, (int) $rawFloor)) : 120;
        if ($floorMs === 0) {
            return;
        }

        $targetUs = ($floorMs + random_int(0, 25)) * 1000;
        $elapsedUs = (int) ((hrtime(true) - $startNs) / 1000);
        if ($elapsedUs < $targetUs) {
            usleep($targetUs - $elapsedUs);
        }
    }

    private static function passwordResetSigningKey(): string
    {
        $secret = trim((string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: ''));
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured', 500);
        }

        return hash_hmac('sha256', 'famo:password_reset:jwt:v1', $secret);
    }

    private static function passwordResetTokenRedisKey(string $jti): string
    {
        return 'pwd_reset:jti:' . $jti;
    }

    private function clearMustChangePasswordIfPresent(int $userId): void
    {
        try {
            $db = Database::getConnection();
            $driver = strtolower((string) $db->getAttribute(\PDO::ATTR_DRIVER_NAME));
            if ($driver === 'pgsql') {
                $colStmt = $db->prepare(
                    "SELECT data_type FROM information_schema.columns
                     WHERE table_name = 'users' AND column_name = 'must_change_password'
                     LIMIT 1"
                );
                $colStmt->execute();
                $dataType = $colStmt->fetchColumn();
                if ($dataType === false) {
                    return;
                }
                $valSql = strtolower((string) $dataType) === 'boolean' ? 'FALSE' : '0';
                $db->prepare("UPDATE users SET must_change_password = {$valSql} WHERE id = :id")
                   ->execute(['id' => $userId]);
                return;
            }

            if ($driver === 'mysql') {
                $colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
                if ($colStmt === false || $colStmt->fetch() === false) {
                    return;
                }
                $db->prepare('UPDATE users SET must_change_password = 0 WHERE id = :id')
                   ->execute(['id' => $userId]);
            }
        } catch (\Throwable) {
            // Column does not exist or cannot be inspected; safe no-op
        }
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

    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
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

        // Revoke all previously issued tokens, then issue a fresh session preserving verified 2FA state for admins.
        JwtRevocation::revokeAllForUser($userId);

        $preserveAdmin2fa = strtolower((string) ($user['role'] ?? '')) === 'admin';
        return $this->sessionForUser($userId, $preserveAdmin2fa);
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
