<?php
declare(strict_types=1);

namespace App\Modules\Admins;

use App\Core\Database;

/**
 * Model for the `admins` table and its linked `users` credential row.
 */
class Admin
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        try {
            $stmt = $db->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $admin = $stmt->fetch();
            if ($admin) {
                return $admin;
            }
        } catch (\Throwable) {
        }

        $stmt = $db->prepare("SELECT *, COALESCE(full_name, username) AS name FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function isAccountActive(int $id): bool
    {
        $admin = self::findById($id);
        if (!$admin) {
            return false;
        }
        if (isset($admin['is_active']) && (int) $admin['is_active'] === 0) {
            return false;
        }

        $db = Database::getConnection();
        try {
            $stmt = $db->prepare("SELECT is_active FROM users WHERE role = 'admin' AND (linked_id = :lid OR id = :uid) LIMIT 1");
            $stmt->execute(['lid' => $id, 'uid' => $id]);
            $flag = $stmt->fetchColumn();
            if ($flag !== false && (int) $flag === 0) {
                return false;
            }
        } catch (\Throwable) {
        }

        return true;
    }
}

