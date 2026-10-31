<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\AuthCookie;
use App\Core\ClientIp;
use App\Core\RateLimitedException;
use App\Core\ResponseHelper;
use App\Core\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TelegramAuthController
{
    public function __construct(private TelegramAuthService $service)
    {
    }

    public function verify(Request $request, Response $response): Response
    {
        $body = $this->body($request);

        $validator = new Validator();
        $validator
            ->required('id', 'شناسه تلگرام', $body['id'] ?? null)
            ->positiveInt('id', 'شناسه تلگرام', $body['id'] ?? null)
            ->required('auth_date', 'زمان احراز هویت', $body['auth_date'] ?? null)
            ->positiveInt('auth_date', 'زمان احراز هویت', $body['auth_date'] ?? null)
            ->required('hash', 'امضای دیجیتال تلگرام', $body['hash'] ?? null)
            ->string('hash', 'امضای دیجیتال تلگرام', $body['hash'] ?? null);

        if (!$validator->passes()) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', $validator->firstError(), 422);
        }

        try {
            $result = $this->service->verify($body, ClientIp::fromRequest($request));
        } catch (RateLimitedException $e) {
            return $this->rateLimited($response, $e);
        }

        return ResponseHelper::json($response, $result);
    }

    public function register(Request $request, Response $response): Response
    {
        $body = $this->body($request);

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
            ->required('ticket', 'توکن تلگرام', $body['ticket'] ?? null)
            ->string('ticket', 'توکن تلگرام', $body['ticket'] ?? null);

        if (isset($body['grade'])) {
            $validator->inArray('grade', 'پایه تحصیلی', is_scalar($body['grade']) ? (int) $body['grade'] : null, [7, 8, 9, 10, 11, 12]);
        }

        if (!$validator->passes()) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', $validator->firstError(), 422);
        }

        try {
            $result = $this->service->register(
                $body,
                (string) ($body['ticket'] ?? ''),
                ClientIp::fromRequest($request)
            );
        } catch (RateLimitedException $e) {
            return $this->rateLimited($response, $e);
        }

        return $this->authResponse($response, $request, $result, 201);
    }

    public function link(Request $request, Response $response): Response
    {
        $body = $this->body($request);

        $validator = new Validator();
        $validator
            ->required('ticket', 'توکن تلگرام', $body['ticket'] ?? null)
            ->string('ticket', 'توکن تلگرام', $body['ticket'] ?? null)
            ->required('username', 'نام کاربری', $body['username'] ?? null)
            ->string('username', 'نام کاربری', $body['username'] ?? null)
            ->required('password', 'رمز عبور', $body['password'] ?? null)
            ->string('password', 'رمز عبور', $body['password'] ?? null);

        if (!$validator->passes()) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', $validator->firstError(), 422);
        }

        try {
            $result = $this->service->beginLink(
                (string) ($body['ticket'] ?? ''),
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? ''),
                ClientIp::fromRequest($request)
            );
        } catch (RateLimitedException $e) {
            return $this->rateLimited($response, $e);
        }

        $status = !empty($result['requires_2fa']) ? 202 : 200;

        return $this->authResponse($response, $request, $result, $status);
    }

    public function verify2fa(Request $request, Response $response): Response
    {
        $body = $this->body($request);

        $validator = new Validator();
        $validator
            ->required('ticket', 'توکن تلگرام', $body['ticket'] ?? null)
            ->string('ticket', 'توکن تلگرام', $body['ticket'] ?? null)
            ->required('challenge_id', 'شناسه چالش', $body['challenge_id'] ?? null)
            ->string('challenge_id', 'شناسه چالش', $body['challenge_id'] ?? null)
            ->required('code', 'کد تأیید', $body['code'] ?? null)
            ->string('code', 'کد تأیید', $body['code'] ?? null);

        if (isset($body['user_id']) && $body['user_id'] !== '') {
            $validator->positiveInt('user_id', 'شناسه کاربر', $body['user_id']);
        }

        if (!$validator->passes()) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', $validator->firstError(), 422);
        }

        $clientUserId = isset($body['user_id']) && $body['user_id'] !== '' ? (int) $body['user_id'] : null;

        try {
            $result = $this->service->completeLink2fa(
                (string) ($body['ticket'] ?? ''),
                (string) ($body['challenge_id'] ?? ''),
                (string) ($body['code'] ?? ''),
                $clientUserId,
                ClientIp::fromRequest($request)
            );
        } catch (RateLimitedException $e) {
            return $this->rateLimited($response, $e);
        }

        return $this->authResponse($response, $request, $result, 200);
    }

    private function authResponse(Response $response, Request $request, array $result, int $status): Response
    {
        $token = $result['token'] ?? null;
        $cookieOnly = $request->getHeaderLine('X-Auth-Mode') === 'cookie';
        $redirectToBot = !empty($result['bot_redirect_url']);

        if ($cookieOnly || $redirectToBot) {
            unset($result['token']);
        }

        $response = ResponseHelper::json($response, $result, null, $status);

        if (!$redirectToBot && is_string($token)) {
            $response = AuthCookie::issue($response, $request, $token);
        }

        return $response;
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function rateLimited(Response $response, RateLimitedException $e): Response
    {
        return ResponseHelper::error($response, 'RATE_LIMITED', $e->getMessage(), 429)
            ->withHeader('Retry-After', (string) $e->getRetryAfter());
    }
}
