<?php

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\PhoneNumber;

class User
{
    public const VALID_ROLES = ['admin', 'supporter', 'student', 'teacher'];

    private const ALLOWED_CREATE_COLUMNS = [
        'id',
        'full_name',
        'username',
        'phone',
        'email',
        'password_hash',
        'role',
        'linked_id',
        'is_active',
        'last_login',
        'chat_id',
        'supporter_id',
    ];

    /**
     * Columns permitted in generic User::update() calls.
     * Note: `id` and `role` are intentionally excluded so that no profile/account
     * update can ever mutate a user's role or primary key.
     */
    private const ALLOWED_UPDATE_COLUMNS = [
        'full_name',
        'username',
        'phone',
        'email',
        'password_hash',
        'linked_id',
        'is_active',
        'last_login',
        'chat_id',
        'supporter_id',
    ];

    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Return all user rows matching a username or phone (handles legacy duplicate usernames such as 09000000000).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findCandidatesForLogin(string $identifier): array
    {
        $normalized = PhoneNumber::normalize($identifier) ?? trim($identifier);
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM users WHERE username = :raw_user OR username = :norm_user OR phone = :norm_phone ORDER BY id ASC'
        );
        $stmt->execute([
            'raw_user'   => trim($identifier),
            'norm_user'  => $normalized,
            'norm_phone' => $normalized,
        ]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function findByPhone(string $phone): ?array
    {
        $normalized = PhoneNumber::normalize($phone) ?? trim($phone);
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM users WHERE phone = :phone OR username = :username LIMIT 1'
        );
        $stmt->execute(['phone' => $normalized, 'username' => $normalized]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function findByLinkedId(int $linkedId, string $role): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM users WHERE linked_id = :linked_id AND role = :role LIMIT 1'
        );
        $stmt->execute(['linked_id' => $linkedId, 'role' => $role]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function create(array $data): int
    {
        if (isset($data['phone']) && $data['phone'] !== '') {
            $data['phone'] = PhoneNumber::normalizeOrFail($data['phone']);
        }
        if (isset($data['username']) && PhoneNumber::isValid($data['username'])) {
            $data['username'] = PhoneNumber::normalize($data['username']);
        }

        if (isset($data['password'])) {
            $data['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }

        // Prevent MySQL's implicit first-ENUM ('admin') fallback when role is omitted
        $role = isset($data['role']) ? strtolower(trim((string) $data['role'])) : 'student';
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new \App\Core\ApiException('نقش کاربری نامعتبر است', 422, 'VALIDATION_ERROR');
        }
        $data['role'] = $role;

        $filtered = [];
        foreach (self::ALLOWED_CREATE_COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $filtered[$col] = $data[$col];
            }
        }

        $columns = implode(', ', array_keys($filtered));
        $placeholders = ':' . implode(', :', array_keys($filtered));

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO users ({$columns}) VALUES ({$placeholders})"
        );
        $stmt->execute($filtered);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, array $data): int
    {
        if ($id <= 0) {
            return 0;
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== null && $data['phone'] !== '') {
            $data['phone'] = PhoneNumber::normalizeOrFail($data['phone']);
        }
        if (isset($data['username']) && PhoneNumber::isValid($data['username'])) {
            $data['username'] = PhoneNumber::normalize($data['username']);
        }
        if (isset($data['password'])) {
            $data['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }

        $filtered = [];
        foreach (self::ALLOWED_UPDATE_COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $filtered[$col] = $data[$col];
            }
        }

        if ($filtered === []) {
            return 0;
        }

        $sets = implode(', ', array_map(static fn (string $col): string => "{$col} = :{$col}", array_keys($filtered)));
        $filtered['id'] = $id;

        $stmt = Database::getConnection()->prepare(
            "UPDATE users SET {$sets} WHERE id = :id"
        );
        $stmt->execute($filtered);
        return $stmt->rowCount();
    }

    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = Database::getConnection()->prepare(
            'DELETE FROM users WHERE id = :id'
        );
        return $stmt->execute(['id' => $id]);
    }
}