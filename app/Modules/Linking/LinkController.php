<?php

namespace App\Modules\Linking;

use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Module 2: bot identity & linking endpoints. All are service-key protected
 * (mounted under /api/v1/bot). ApiException from the service is turned into the
 * standard error envelope by the global ExceptionHandler.
 */
class LinkController
{
    public function __construct(private LinkService $service)
    {
    }

    public function lookup(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('phone', 'شماره تلفن', $body['phone'] ?? null)
            ->string('phone', 'شماره تلفن', $body['phone'] ?? null);

        if (isset($body['telegram_user_id']) && $body['telegram_user_id'] !== '') {
            $validator->positiveInt('telegram_user_id', 'شناسه کاربر تلگرام', $body['telegram_user_id']);
        }
        $validator->validateOrThrow();

        $phone = (string) ($body['phone'] ?? '');
        $telegramUserId = isset($body['telegram_user_id']) ? (int) $body['telegram_user_id'] : null;

        return ResponseHelper::json($response, $this->service->lookup($phone, $telegramUserId));
    }

    public function link(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        $validator
            ->required('role', 'نقش حساب', $body['role'] ?? null)
            ->string('role', 'نقش حساب', $body['role'] ?? null)
            ->required('account_id', 'شناسه حساب', $body['account_id'] ?? null)
            ->positiveInt('account_id', 'شناسه حساب', $body['account_id'] ?? null)
            ->required('telegram_user_id', 'شناسه تلگرام', $body['telegram_user_id'] ?? null)
            ->positiveInt('telegram_user_id', 'شناسه تلگرام', $body['telegram_user_id'] ?? null)
            ->required('chat_id', 'شناسه چت', $body['chat_id'] ?? null)
            ->integer('chat_id', 'شناسه چت', $body['chat_id'] ?? null);
        $validator->validateOrThrow();

        return ResponseHelper::json($response, $this->service->link($body));
    }

    public function resolve(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $telegramUserId = isset($query['telegram_user_id']) ? (int) $query['telegram_user_id'] : null;
        $chatId = isset($query['chat_id']) ? (int) $query['chat_id'] : null;
        $role = isset($query['role']) ? (string) $query['role'] : null;

        return ResponseHelper::json($response, $this->service->resolve($telegramUserId, $chatId, $role));
    }

    public function unlink(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        if (isset($body['telegram_user_id']) && $body['telegram_user_id'] !== '') {
            $validator->positiveInt('telegram_user_id', 'شناسه تلگرام', $body['telegram_user_id']);
        }
        if (isset($body['account_id']) && $body['account_id'] !== '') {
            $validator->positiveInt('account_id', 'شناسه حساب', $body['account_id']);
        }
        if (isset($body['role']) && $body['role'] !== null) {
            $validator->string('role', 'نقش حساب', $body['role']);
        }
        $validator->validateOrThrow();

        $telegramUserId = isset($body['telegram_user_id']) ? (int) $body['telegram_user_id'] : null;
        $role = isset($body['role']) ? (string) $body['role'] : null;
        $accountId = isset($body['account_id']) ? (int) $body['account_id'] : null;

        return ResponseHelper::json($response, $this->service->unlink($telegramUserId, $role, $accountId));
    }

    public function block(Request $request, Response $response): Response
    {
        return $this->setBlocked($request, $response, true);
    }

    public function unblock(Request $request, Response $response): Response
    {
        return $this->setBlocked($request, $response, false);
    }

    private function setBlocked(Request $request, Response $response, bool $blocked): Response
    {
        $body = $request->getParsedBody() ?? [];

        $validator = new \App\Core\Validator();
        if (isset($body['telegram_user_id']) && $body['telegram_user_id'] !== '') {
            $validator->positiveInt('telegram_user_id', 'شناسه تلگرام', $body['telegram_user_id']);
        }
        if (isset($body['chat_id']) && $body['chat_id'] !== '') {
            $validator->integer('chat_id', 'شناسه چت', $body['chat_id']);
        }
        if (isset($body['role']) && $body['role'] !== null) {
            $validator->string('role', 'نقش حساب', $body['role']);
        }
        $validator->validateOrThrow();

        $telegramUserId = isset($body['telegram_user_id']) ? (int) $body['telegram_user_id'] : null;
        $chatId = isset($body['chat_id']) ? (int) $body['chat_id'] : null;
        $role = isset($body['role']) ? (string) $body['role'] : null;

        return ResponseHelper::json($response, $this->service->setBlocked($blocked, $telegramUserId, $chatId, $role));
    }
}
