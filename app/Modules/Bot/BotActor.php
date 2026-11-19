<?php
declare(strict_types=1);

namespace App\Modules\Bot;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Value object representing the authenticated Telegram bot actor resolved by BotAuth.
 */
final class BotActor
{
    public const ROLES = ['student', 'supporter', 'admin'];

    public function __construct(
        public readonly string $role,
        public readonly int $accountId,
        public readonly int $telegramUserId,
        public readonly int $chatId,
        public readonly int $linkId = 0,
        public readonly string $name = ''
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $attr = $request->getAttribute('bot_actor');
        if ($attr instanceof self) {
            return $attr;
        }
        if (is_array($attr) && isset($attr['role'], $attr['account_id'])) {
            return new self(
                (string) $attr['role'],
                (int) $attr['account_id'],
                (int) ($attr['telegram_user_id'] ?? $attr['chat_id'] ?? 0),
                (int) ($attr['chat_id'] ?? 0),
                (int) ($attr['link_id'] ?? 0),
                (string) ($attr['name'] ?? '')
            );
        }

        return null;
    }
}
