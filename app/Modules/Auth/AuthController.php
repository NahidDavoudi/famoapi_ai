<?php

namespace App\Modules\Auth;

use App\Core\Auth;
use App\Core\AuthCookie;
use App\Core\ClientIp;
use App\Core\JwtRevocation;
use App\Core\RateLimitedException;
use App\Core\RateLimiter;
use App\Core\TrustedDevice;
use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(private AuthService $service)
    {
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('username', 'نام کاربری', $body['username'] ?? null)
            ->string('username', 'نام کاربری', $body['username'] ?? null)
            ->required('password', 'رمز عبور', $body['password'] ?? null)
            ->string('password', 'رمز عبور', $body['password'] ?? null);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $username = (string) $body['username'];
        $ip = ClientIp::fromRequest($request);
        $window = $this->envInt('AUTH_LOGIN_WINDOW_SECONDS', 900);
        $lockout = $this->envInt('AUTH_LOGIN_LOCKOUT_SECONDS', 900);
        $userIpKey = $ip !== null ? RateLimiter::key('login:user_ip', $username . '|' . $ip) : null;
        $ipKey = $ip !== null ? RateLimiter::key('login:ip', $ip) : null;
        $userKey = RateLimiter::key('login:user', $username);

        try {
            $this->assertLoginAllowed($userIpKey, $ipKey, $userKey, $window);

            $trustedDeviceToken = TrustedDevice::tokenFromRequest($request);
            $result = $this->service->login($username, (string) $body['password'], $ip, $trustedDeviceToken);

            if ($userIpKey !== null) {
                RateLimiter::clear($userIpKey);
            }
            RateLimiter::clear($userKey);
        } catch (RateLimitedException $e) {
            return $this->rateLimitedResponse($response, $e);
        } catch (\App\Core\ApiException $e) {
            if ($e->getErrorCode() !== 'OTP_SERVICE_UNAVAILABLE') {
                if ($userIpKey !== null) {
                    RateLimiter::attempt($userIpKey, $this->envInt('AUTH_LOGIN_MAX_ATTEMPTS', 5), $window, $lockout);
                }
                if ($ipKey !== null) {
                    RateLimiter::attempt($ipKey, $this->envInt('AUTH_LOGIN_IP_MAX_ATTEMPTS', 30), $window, $window);
                }
                RateLimiter::attempt($userKey, $this->envInt('AUTH_LOGIN_USER_SOFT_MAX', 20), $window, $window);
            }

            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $status = !empty($result['requires_2fa']) ? 202 : 200;
        $token = $result['token'] ?? null;
        if (is_string($token) && isset($result['user']) && is_array($result['user'])) {
            $uRole = strtolower((string) ($result['user']['role'] ?? ''));
            if (in_array($uRole, ['admin', 'supporter', 'teacher'], true)) {
                try {
                    \App\Modules\ActivityLogs\ActivityLog::record([
                        'actor_user_id'   => (int) ($result['user']['id'] ?? 0),
                        'actor_role'      => $uRole,
                        'actor_linked_id' => isset($result['user']['linked_id']) ? (int) $result['user']['linked_id'] : null,
                        'actor_name'      => $result['user']['name'] ?? $result['user']['username'] ?? null,
                        'module'          => 'auth',
                        'action'          => 'login',
                        'target_type'     => 'session',
                        'target_id'       => isset($result['user']['id']) ? (string) $result['user']['id'] : null,
                        'summary'         => 'ورود موفق به پنل مدیریت/کاربری',
                        'http_method'     => 'POST',
                        'endpoint'        => '/api/v1/auth/login',
                        'status_code'     => 200,
                        'ip_address'      => $ip,
                    ]);
                } catch (\Throwable) {
                }
            }
        }
        $cookieOnly = $request->getHeaderLine('X-Auth-Mode') === 'cookie';
        if ($cookieOnly) {
            unset($result['token']);
        }
        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        return is_string($token) ? AuthCookie::issue($response, $request, $token) : $response;
    }

    public function verify2fa(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('user_id', 'شناسه کاربر', $body['user_id'] ?? null)
            ->positiveInt('user_id', 'شناسه کاربر', $body['user_id'] ?? null)
            ->required('code', 'کد تأیید', $body['code'] ?? null)
            ->string('code', 'کد تأیید', $body['code'] ?? null);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $userId = (int) $body['user_id'];
        $ttl = $this->envInt('AUTH_2FA_CHALLENGE_TTL', 300);
        $twoFaKey = RateLimiter::key('2fa:user', (string) $userId);

        try {
            $status = RateLimiter::status($twoFaKey, $this->envInt('AUTH_2FA_MAX_ATTEMPTS', 5));
            if (!$status['allowed']) {
                throw new RateLimitedException($status['retry_after'] > 0 ? $status['retry_after'] : $ttl);
            }

            $result = $this->service->verify2fa($userId, (string) $body['code']);

            RateLimiter::clear($twoFaKey);
        } catch (RateLimitedException $e) {
            return $this->rateLimitedResponse($response, $e);
        } catch (\App\Core\ApiException $e) {
            if ($e->getErrorCode() !== 'OTP_SERVICE_UNAVAILABLE') {
                RateLimiter::attempt($twoFaKey, $this->envInt('AUTH_2FA_MAX_ATTEMPTS', 5), $ttl, $ttl);
            }

            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $token = $result['token'] ?? null;
        if (is_string($token) && isset($result['user']) && is_array($result['user'])) {
            $uRole = strtolower((string) ($result['user']['role'] ?? ''));
            if (in_array($uRole, ['admin', 'supporter', 'teacher'], true)) {
                try {
                    \App\Modules\ActivityLogs\ActivityLog::record([
                        'actor_user_id'   => (int) ($result['user']['id'] ?? $userId),
                        'actor_role'      => $uRole,
                        'actor_linked_id' => isset($result['user']['linked_id']) ? (int) $result['user']['linked_id'] : null,
                        'actor_name'      => $result['user']['name'] ?? $result['user']['username'] ?? null,
                        'module'          => 'auth',
                        'action'          => 'verify_2fa',
                        'target_type'     => 'session',
                        'target_id'       => (string) ($result['user']['id'] ?? $userId),
                        'summary'         => 'تأیید دو مرحله‌ای (2FA) و ورود به پنل',
                        'http_method'     => 'POST',
                        'endpoint'        => '/api/v1/auth/verify-2fa',
                        'status_code'     => 200,
                        'ip_address'      => ClientIp::fromRequest($request),
                    ]);
                } catch (\Throwable) {
                }
            }
        }
        $cookieOnly = $request->getHeaderLine('X-Auth-Mode') === 'cookie';
        if ($cookieOnly) {
            unset($result['token']);
        }
        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        if (is_string($token)) {
            $response = AuthCookie::issue($response, $request, $token);
        }
        $verifiedRole = strtolower((string) ($result['user']['role'] ?? ''));
        $verifiedUserId = (int) ($result['user']['id'] ?? $userId);
        if ($verifiedRole === 'student' && TrustedDevice::mode() === TrustedDevice::MODE_NEW_DEVICE) {
            $response = TrustedDevice::issueForUser($verifiedUserId, $response, $request);
        }
        return $response;
    }

    public function forgotPassword(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $username = isset($body['username']) && is_scalar($body['username']) ? (string) $body['username'] : null;

        $validator = new Validator();
        $validator
            ->required('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->phone('phone', 'شماره تلفن', $body['phone'] ?? null);

        if ($username !== null) {
            $validator->maxLength('username', 'نام کاربری', $username, 100);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->forgotPassword((string) $body['phone'], $username);
        } catch (RateLimitedException $e) {
            return $this->rateLimitedResponse($response, $e);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function verifyPasswordReset(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $username = isset($body['username']) && is_scalar($body['username']) ? (string) $body['username'] : null;

        $validator = new Validator();
        $validator
            ->required('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->phone('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->required('code', 'کد تأیید', $body['code'] ?? null)
            ->string('code', 'کد تأیید', $body['code'] ?? null);

        if ($username !== null) {
            $validator->maxLength('username', 'نام کاربری', $username, 100);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->verifyPasswordResetOtp(
                (string) $body['phone'],
                (string) $body['code'],
                $username
            );
        } catch (RateLimitedException $e) {
            return $this->rateLimitedResponse($response, $e);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function resetPassword(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $token = $body['token'] ?? $body['reset_token'] ?? null;
        $newPassword = $body['new_password'] ?? $body['password'] ?? null;

        $validator = new Validator();
        $validator
            ->required('token', 'توکن بازنشانی', $token)
            ->string('token', 'توکن بازنشانی', $token)
            ->required('new_password', 'رمز عبور جدید', $newPassword)
            ->string('new_password', 'رمز عبور جدید', $newPassword)
            ->minLength('new_password', 'رمز عبور جدید', $newPassword, 4);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->resetPasswordWithToken((string) $token, (string) $newPassword);
        } catch (RateLimitedException $e) {
            return $this->rateLimitedResponse($response, $e);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function register(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->phone('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->required('password', 'رمز عبور', $body['password'] ?? null)
            ->string('password', 'رمز عبور', $body['password'] ?? null)
            ->minLength('password', 'رمز عبور', $body['password'] ?? null, 4)
            ->required('nationalId', 'کد ملی', $body['nationalId'] ?? null)
            ->nationalId('nationalId', 'کد ملی', $body['nationalId'] ?? null)
            ->required('grade', 'پایه تحصیلی', $body['grade'] ?? null)
            ->required('field', 'رشته تحصیلی', $body['field'] ?? null)
            ->inArray('field', 'رشته تحصیلی', $body['field'] ?? null, ['ریاضی', 'تجربی', 'انسانی', 'زبان', 'هنر', 'عمومی', 'راهنمایی'])
            ->required('name', 'نام و نام خانوادگی', $body['name'] ?? null)
            ->string('name', 'نام و نام خانوادگی', $body['name'] ?? null)
            ->minLength('name', 'نام و نام خانوادگی', $body['name'] ?? null, 2)
            ->maxLength('name', 'نام و نام خانوادگی', $body['name'] ?? null, 150);

        if (isset($body['grade'])) {
            $validator->inArray('grade', 'پایه تحصیلی', is_scalar($body['grade']) ? (int) $body['grade'] : null, [7, 8, 9, 10, 11, 12]);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->service->register($body);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $token = $result['token'] ?? null;
        $cookieOnly = $request->getHeaderLine('X-Auth-Mode') === 'cookie';
        if ($cookieOnly) {
            unset($result['token']);
        }
        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $result,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        $response = $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
        return is_string($token) ? AuthCookie::issue($response, $request, $token) : $response;
    }

    public function me(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');

        try {
            $userData = $this->service->me((int) $decoded->sub);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $userData,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function logout(Request $request, Response $response): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $token = str_starts_with($authHeader, 'Bearer ')
            ? trim(substr($authHeader, 7))
            : AuthCookie::tokenFromRequest($request);

        if (is_string($token) && $token !== '') {
            JwtRevocation::blacklistToken($token);
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => null,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return AuthCookie::clear($response->withHeader('Content-Type', 'application/json; charset=utf-8'), $request);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $body = $request->getParsedBody() ?? [];

        $validator = new Validator();
        $validator
            ->required('current_password', 'رمز عبور فعلی', $body['current_password'] ?? null)
            ->string('current_password', 'رمز عبور فعلی', $body['current_password'] ?? null)
            ->required('new_password', 'رمز عبور جدید', $body['new_password'] ?? null)
            ->string('new_password', 'رمز عبور جدید', $body['new_password'] ?? null)
            ->minLength('new_password', 'رمز عبور جدید', $body['new_password'] ?? null, 4);

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $session = $this->service->changePassword(
                (int) $decoded->sub,
                (string) $body['current_password'],
                (string) $body['new_password']
            );
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $token = $session['token'] ?? null;
        $payload = [
            'message' => 'رمز عبور با موفقیت تغییر یافت',
            'user'    => $session['user'] ?? null,
        ];
        $cookieOnly = $request->getHeaderLine('X-Auth-Mode') === 'cookie';
        if (!$cookieOnly && is_string($token)) {
            $payload['token'] = $token;
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $payload,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        return is_string($token) ? AuthCookie::issue($response, $request, $token) : $response;
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $decoded = $request->getAttribute('user');
        $body = $request->getParsedBody() ?? [];
        $fullName = $body['full_name'] ?? $body['name'] ?? null;

        $validator = new Validator();
        $validator
            ->required('full_name', 'نام و نام خانوادگی', $fullName)
            ->string('full_name', 'نام و نام خانوادگی', $fullName)
            ->minLength('full_name', 'نام و نام خانوادگی', $fullName, 2)
            ->maxLength('full_name', 'نام و نام خانوادگی', $fullName, 150)
            ->required('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->phone('phone', 'شماره تلفن', $body['phone'] ?? null);

        if (isset($body['current_password']) && $body['current_password'] !== null) {
            $validator->string('current_password', 'رمز عبور فعلی', $body['current_password']);
        }

        if (!$validator->passes()) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $validator->firstError(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $profile = $this->service->updateProfile((int) $decoded->sub, $body);
        } catch (\App\Core\ApiException $e) {
            $response->getBody()->write(json_encode([
                'success'    => false,
                'data'       => null,
                'pagination' => null,
                'error'      => [
                    'code'    => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus($e->getHttpStatus())->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $response->getBody()->write(json_encode([
            'success'    => true,
            'data'       => $profile,
            'pagination' => null,
            'error'      => null,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function assertLoginAllowed(?string $userIpKey, ?string $ipKey, string $userKey, int $window): void
    {
        $checks = [];
        if ($userIpKey !== null) {
            $checks[] = [$userIpKey, $this->envInt('AUTH_LOGIN_MAX_ATTEMPTS', 5)];
        }
        if ($ipKey !== null) {
            $checks[] = [$ipKey, $this->envInt('AUTH_LOGIN_IP_MAX_ATTEMPTS', 30)];
        }
        $checks[] = [$userKey, $this->envInt('AUTH_LOGIN_USER_SOFT_MAX', 20)];

        foreach ($checks as [$key, $max]) {
            $status = RateLimiter::status($key, $max);
            if (!$status['allowed']) {
                throw new RateLimitedException($status['retry_after'] > 0 ? $status['retry_after'] : $window);
            }
        }
    }

    private function rateLimitedResponse(Response $response, RateLimitedException $e): Response
    {
        $response->getBody()->write(json_encode([
            'success'    => false,
            'data'       => null,
            'pagination' => null,
            'error'      => [
                'code'    => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ],
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($e->getHttpStatus())
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Retry-After', (string) $e->getRetryAfter());
    }

    private function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return max(1, (int) $value);
    }
}
