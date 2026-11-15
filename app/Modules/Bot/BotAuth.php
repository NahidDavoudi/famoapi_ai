<?php
declare(strict_types=1);

namespace App\Modules\Bot;

use App\Core\ResponseHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Resolves the acting account for /bot/* endpoints that operate on behalf of
 * a linked user. Requires BotServiceMiddleware to have run first.
 *
 * Expected headers (set by the bot host):
 *   X-Bot-Role: student|supporter|admin
 *   X-Bot-Account-Id: <int>          (preferred)
 *   X-Telegram-Chat-Id: <int>
 *   X-Bot-Account-Name: <string>     (optional)
 *
 * Fallback: if X-Bot-Account-Id is missing, the link is looked up by chat_id.
 */
final class BotAuth implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role       = strtolower(trim($request->getHeaderLine('X-Bot-Role')));
        $accountId  = (int) $request->getHeaderLine('X-Bot-Account-Id');
        $tgUserId   = (int) $request->getHeaderLine('X-Telegram-User-Id');
        $chatId     = (int) $request->getHeaderLine('X-Telegram-Chat-Id');
        $name       = trim($request->getHeaderLine('X-Bot-Account-Name'));

        if (!in_array($role, BotActor::ROLES, true)) {
            return $this->fail('BOT_ACTOR_MISSING', 'نقش ربات نامعتبر است', 400);
        }

        if ($accountId <= 0 && $tgUserId > 0) {
            $link = TelegramLink::findByTelegramUserAndRole($tgUserId, $role);
            if ($link) {
                $accountId = (int) $link['account_id'];
                if ($chatId <= 0) {
                    $chatId = (int) $link['chat_id'];
                }
            }
        }

        if ($accountId <= 0 && $chatId > 0) {
            $dbRoles = $role === 'supporter' ? ['supporter', 'admin'] : [$role];
            $link = TelegramLink::findByChatIdAndRoles($chatId, $dbRoles);
            if ($link) {
                $accountId = (int) $link['account_id'];
                if ($name === '') {
                    $name = (string) ($link['name'] ?? '');
                }
            }
        }

        if ($accountId <= 0) {
            return $this->fail('BOT_NOT_LINKED', 'این حساب تلگرام به کاربری متصل نیست', 401);
        }

        if ($role === 'student') {
            $student = \App\Modules\Students\Student::findById($accountId);
            if (!$student || (int) ($student['is_active'] ?? 0) !== 1) {
                return $this->fail('BOT_ACCOUNT_INACTIVE', 'حساب دانش‌آموز غیرفعال است', 403);
            }
        } elseif ($role === 'supporter') {
            if (!\App\Modules\Supporters\Supporter::isAccountActive($accountId)) {
                return $this->fail('BOT_ACCOUNT_INACTIVE', 'حساب پشتیبان غیرفعال است', 403);
            }
        } elseif ($role === 'admin') {
            if (!\App\Modules\Admins\Admin::isAccountActive($accountId)) {
                return $this->fail('BOT_ACCOUNT_INACTIVE', 'حساب مدیر غیرفعال است', 403);
            }
        }

        $resolvedLink = $link ?? TelegramLink::findByRoleAndAccount($role, $accountId);

        return $handler->handle($request->withAttribute('bot_actor', [
            'role'             => $role,
            'account_id'       => $accountId,
            'telegram_user_id' => $tgUserId > 0 ? $tgUserId : (int) ($resolvedLink['telegram_user_id'] ?? 0),
            'name'             => $name,
            'chat_id'          => $chatId > 0 ? $chatId : (int) ($resolvedLink['chat_id'] ?? 0),
            'link'             => [
                'telegram_user_id' => $tgUserId > 0 ? $tgUserId : (int) ($resolvedLink['telegram_user_id'] ?? 0),
                'chat_id'          => $chatId > 0 ? $chatId : (int) ($resolvedLink['chat_id'] ?? 0),
                'is_blocked'       => (int) ($resolvedLink['is_blocked'] ?? 0),
                'linked_at'        => $resolvedLink['linked_at'] ?? null,
            ],
        ]));
    }

    private function fail(string $code, string $message, int $status): ResponseInterface
    {
        return ResponseHelper::error(new Response(), $code, $message, $status);
    }
}