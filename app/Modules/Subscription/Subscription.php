<?php

namespace App\Modules\Subscription;

use App\Core\Database;
use PDO;

class Subscription
{
    private const BASE_SELECT = '
        SELECT
            sub.id,
            sub.course_id,
            sub.student_id,
            sub.status,
            sub.is_active,
            sub.note,
            sub.started_at,
            sub.end_at,
            sub.created_at,
            sub.updated_at,
            c.name AS course_name,
            c.price AS course_price,
            c.icon AS course_icon,
            c.description AS course_description,
            st.name AS student_name,
            st.phone AS student_phone,
            st.grade AS student_grade,
            st.field AS student_field
        FROM subscriptions sub
        INNER JOIN courses c ON c.id = sub.course_id
        INNER JOIN students st ON st.id = sub.student_id
    ';

    /**
     * Build dynamic WHERE clause and bindings for filtering subscriptions.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['student_id']) && $filters['student_id'] !== '' && $filters['student_id'] !== null) {
            $conditions[] = 'sub.student_id = :student_id';
            $params[':student_id'] = (int) $filters['student_id'];
        }

        if (isset($filters['course_id']) && $filters['course_id'] !== '' && $filters['course_id'] !== null) {
            $conditions[] = 'sub.course_id = :course_id';
            $params[':course_id'] = (int) $filters['course_id'];
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $conditions[] = 'sub.status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $conditions[] = 'sub.is_active = :is_active';
            $params[':is_active'] = (int) $filters['is_active'];
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = '%' . trim((string) $filters['search']) . '%';
            $conditions[] = '(st.name ILIKE :search OR st.phone LIKE :search_exact OR c.name ILIKE :search_course OR COALESCE(sub.note, \'\') ILIKE :search_note)';
            $params[':search'] = $search;
            $params[':search_exact'] = $search;
            $params[':search_course'] = $search;
            $params[':search_note'] = $search;
        }

        $whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return [$whereSql, $params];
    }

    public static function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters);

        $offset = max(0, ($page - 1) * $perPage);
        $sql = self::BASE_SELECT
            . $whereSql
            . ' ORDER BY sub.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function countAll(array $filters = []): int
    {
        $pdo = Database::getConnection();
        [$whereSql, $params] = self::buildWhere($filters);

        $sql = '
            SELECT COUNT(*)
            FROM subscriptions sub
            INNER JOIN courses c ON c.id = sub.course_id
            INNER JOIN students st ON st.id = sub.student_id
        ' . $whereSql;

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $sql = self::BASE_SELECT . ' WHERE sub.id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByStudent(int $studentId, bool $onlyActive = false): array
    {
        $pdo = Database::getConnection();
        $sql = self::BASE_SELECT . ' WHERE sub.student_id = :student_id';
        if ($onlyActive) {
            $sql .= ' AND sub.is_active = 1';
        }
        $sql .= ' ORDER BY sub.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function findActiveOrPendingByStudentAndCourse(
        int $studentId,
        int $courseId,
        ?int $excludeId = null
    ): ?array {
        $pdo = Database::getConnection();
        $sql = self::BASE_SELECT . "
            WHERE sub.student_id = :student_id
              AND sub.course_id = :course_id
              AND sub.is_active = 1
              AND sub.status IN ('pending', 'active')
        ";

        if ($excludeId !== null) {
            $sql .= ' AND sub.id <> :exclude_id';
        }

        $sql .= ' ORDER BY sub.id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':course_id', $courseId, PDO::PARAM_INT);
        if ($excludeId !== null) {
            $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO subscriptions (
                course_id,
                student_id,
                status,
                is_active,
                note,
                started_at,
                end_at,
                created_at,
                updated_at
            ) VALUES (
                :course_id,
                :student_id,
                :status,
                :is_active,
                :note,
                COALESCE(:started_at, CURRENT_TIMESTAMP),
                :end_at,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            RETURNING id'
        );

        $stmt->execute([
            ':course_id'  => (int) $data['course_id'],
            ':student_id' => (int) $data['student_id'],
            ':status'     => (string) ($data['status'] ?? 'pending'),
            ':is_active'  => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            ':note'       => $data['note'] ?? null,
            ':started_at' => $data['started_at'] ?? null,
            ':end_at'     => $data['end_at'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public static function update(int $id, array $data): bool
    {
        $allowedFields = [
            'course_id',
            'student_id',
            'status',
            'is_active',
            'note',
            'started_at',
            'end_at',
        ];

        $setClauses = [];
        $params = [':id' => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE subscriptions SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function updateStatus(int $id, string $status, int $isActive, ?string $note = null): bool
    {
        $pdo = Database::getConnection();
        $setNote = $note !== null ? ', note = :note' : '';
        $sql = "
            UPDATE subscriptions
            SET status = :status,
                is_active = :is_active
                {$setNote},
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
        if ($note !== null) {
            $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public static function delete(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM subscriptions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}