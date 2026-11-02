<?php

namespace App\Modules\Bot;

use App\Core\Database;

class TelegramLink
{
    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM telegram_links WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<int,array>
     */
    public static function findByTelegramUser(int $telegramUserId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM telegram_links WHERE telegram_user_id = :tg ORDER BY id ASC'
        );
        $stmt->execute(['tg' => $telegramUserId]);

        return $stmt->fetchAll();
    }

    public static function findByTelegramUserAndRole(int $telegramUserId, string $role): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM telegram_links WHERE telegram_user_id = :tg AND role = :role LIMIT 1'
        );
        $stmt->execute(['tg' => $telegramUserId, 'role' => $role]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByChatId(int $chatId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM telegram_links WHERE chat_id = :chat ORDER BY id ASC'
        );
        $stmt->execute(['chat' => $chatId]);
        $links = $stmt->fetchAll();
        if ($links !== []) {
            return $links;
        }

        $stmt = $db->prepare(
            "SELECT u.id,
                    u.username,
                    u.role,
                    u.linked_id,
                    u.linked_id AS account_id,
                    u.chat_id,
                    COALESCE(u.full_name, s.name) AS full_name
             FROM users u
             LEFT JOIN students s ON u.role = 'student' AND s.id = u.linked_id
             WHERE u.chat_id = :chat
             ORDER BY u.role ASC"
        );
        $stmt->execute(['chat' => $chatId]);

        return $stmt->fetchAll();
    }

    public static function findByChatIdAndRoles(int $chatId, array $roles): ?array
    {
        if ($roles === []) {
            return null;
        }
        $db = Database::getConnection();
        $ph = implode(',', array_fill(0, count($roles), '?'));

        $stmt = $db->prepare(
            "SELECT role, account_id, chat_id, telegram_user_id, is_blocked
             FROM telegram_links
             WHERE chat_id = ? AND role IN ({$ph})
             LIMIT 1"
        );
        $stmt->execute(array_merge([$chatId], array_values($roles)));
        $link = $stmt->fetch();
        if ($link) {
            return $link;
        }

        $stmt = $db->prepare(
            "SELECT u.role, u.linked_id AS account_id,
                    COALESCE(u.full_name, s.name) AS name
             FROM users u
             LEFT JOIN students s ON u.role = 'student' AND s.id = u.linked_id
             WHERE u.chat_id = ? AND u.role IN ({$ph})
             LIMIT 1"
        );
        $stmt->execute(array_merge([$chatId], array_values($roles)));
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByRoleAndAccount(string $role, int $accountId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT *
             FROM telegram_links
             WHERE role = :role AND account_id = :account_id
             LIMIT 1'
        );
        $stmt->execute(['role' => $role, 'account_id' => $accountId]);

        return $stmt->fetch() ?: null;
    }

    public static function create(int $telegramUserId, int $chatId, string $role, int $accountId): int
    {
        $db = Database::getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO telegram_links (telegram_user_id, chat_id, role, account_id, is_blocked, linked_at, updated_at)
             VALUES (:tg, :chat, :role, :account_id, 0, :linked_at, :updated_at)'
        );
        $stmt->execute([
            'tg'         => $telegramUserId,
            'chat'       => $chatId,
            'role'       => $role,
            'account_id' => $accountId,
            'linked_at'  => $now,
            'updated_at' => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    public static function updateChatId(int $id, int $chatId): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE telegram_links SET chat_id = :chat, is_blocked = 0, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['chat' => $chatId, 'now' => gmdate('Y-m-d H:i:s'), 'id' => $id]);

        return $stmt->rowCount();
    }

    public static function deleteById(int $id): int
    {
        $stmt = Database::getConnection()->prepare('DELETE FROM telegram_links WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount();
    }

    public static function deleteByTelegramUserAndRole(int $telegramUserId, string $role): int
    {
        $stmt = Database::getConnection()->prepare(
            'DELETE FROM telegram_links WHERE telegram_user_id = :tg AND role = :role'
        );
        $stmt->execute(['tg' => $telegramUserId, 'role' => $role]);

        return $stmt->rowCount();
    }

    public static function setBlockedByTelegramUser(int $telegramUserId, bool $blocked, ?string $role = null): int
    {
        $sql = 'UPDATE telegram_links SET is_blocked = :b, updated_at = :now WHERE telegram_user_id = :tg';
        $params = [
            'b'   => $blocked ? 1 : 0,
            'now' => gmdate('Y-m-d H:i:s'),
            'tg'  => $telegramUserId,
        ];
        if ($role !== null) {
            $sql .= ' AND role = :role';
            $params['role'] = $role;
        }
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function setBlockedByChatId(int $chatId, bool $blocked): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE telegram_links SET is_blocked = :b, updated_at = :now WHERE chat_id = :chat'
        );
        $stmt->execute([
            'b'    => $blocked ? 1 : 0,
            'now'  => gmdate('Y-m-d H:i:s'),
            'chat' => $chatId,
        ]);

        return $stmt->rowCount();
    }

    public static function setBlockedByRoleAndAccount(string $role, int $accountId, bool $blocked): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE telegram_links SET is_blocked = :b, updated_at = :now WHERE role = :role AND account_id = :account_id'
        );
        $stmt->execute([
            'b'          => $blocked ? 1 : 0,
            'now'        => gmdate('Y-m-d H:i:s'),
            'role'       => $role,
            'account_id' => $accountId,
        ]);

        return $stmt->rowCount();
    }

    public static function attachChatId(int $userId, int $chatId): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE users SET chat_id = :chat WHERE id = :id'
        );
        $stmt->execute(['chat' => $chatId, 'id' => $userId]);

        return $stmt->rowCount();
    }
}