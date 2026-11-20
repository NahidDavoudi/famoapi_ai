<?php

namespace App\Modules\Subscription;

use App\Core\Database;
use PDO;

class Subscription
{
    private static bool $tuitionColumnsEnsured = false;

    /**
     * Ensures tuition tracking columns exist on `subscriptions` table.
     */
    public static function ensureTuitionColumns(): void
    {
        if (self::$tuitionColumnsEnsured) {
            return;
        }

        try {
            $pdo = Database::getConnection();
            if ($pdo->inTransaction()) {
                return;
            }

            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions'
                       AND COLUMN_NAME IN ('monthly_fee', 'tuition_status', 'tuition_due_date', 'last_payment_date', 'tuition_notes')"
                );
                $stmt->execute();
                $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!in_array('monthly_fee', $cols, true)) {
                    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN monthly_fee INT NOT NULL DEFAULT 0 AFTER status");
                }
                if (!in_array('tuition_status', $cols, true)) {
                    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN tuition_status VARCHAR(30) NOT NULL DEFAULT 'unpaid' AFTER monthly_fee");
                }
                if (!in_array('tuition_due_date', $cols, true)) {
                    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN tuition_due_date DATE DEFAULT NULL AFTER tuition_status");
                }
                if (!in_array('last_payment_date', $cols, true)) {
                    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN last_payment_date DATE DEFAULT NULL AFTER tuition_due_date");
                }
                if (!in_array('tuition_notes', $cols, true)) {
                    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN tuition_notes TEXT DEFAULT NULL AFTER last_payment_date");
                }
            } elseif ($driver === 'pgsql') {
                $pdo->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS monthly_fee INT NOT NULL DEFAULT 0");
                $pdo->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS tuition_status VARCHAR(30) NOT NULL DEFAULT 'unpaid'");
                $pdo->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS tuition_due_date DATE DEFAULT NULL");
                $pdo->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS last_payment_date DATE DEFAULT NULL");
                $pdo->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS tuition_notes TEXT DEFAULT NULL");
            }

            self::$tuitionColumnsEnsured = true;
        } catch (\Throwable) {
            self::$tuitionColumnsEnsured = true;
        }
    }

    private const BASE_SELECT = '
        SELECT
            sub.id,
            sub.course_id,
            sub.student_id,
            sub.status,
            sub.is_active,
            sub.monthly_fee,
            sub.tuition_status,
            sub.tuition_due_date,
            sub.last_payment_date,
            sub.tuition_notes,
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

        if (isset($filters['tuition_status']) && $filters['tuition_status'] !== '' && $filters['tuition_status'] !== null) {
            $conditions[] = 'sub.tuition_status = :tuition_status';
            $params[':tuition_status'] = (string) $filters['tuition_status'];
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = '%' . trim((string) $filters['search']) . '%';
            $conditions[] = '(st.name LIKE :search OR st.phone LIKE :search_exact OR c.name LIKE :search_course OR COALESCE(sub.note, \'\') LIKE :search_note OR COALESCE(sub.tuition_notes, \'\') LIKE :search_tuition)';
            $params[':search'] = $search;
            $params[':search_exact'] = $search;
            $params[':search_course'] = $search;
            $params[':search_note'] = $search;
            $params[':search_tuition'] = $search;
        }

        $whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return [$whereSql, $params];
    }

    public static function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        self::ensureTuitionColumns();
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
        self::ensureTuitionColumns();
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
        self::ensureTuitionColumns();
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
        self::ensureTuitionColumns();
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
        self::ensureTuitionColumns();
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
        self::ensureTuitionColumns();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO subscriptions (
                course_id,
                student_id,
                status,
                is_active,
                monthly_fee,
                tuition_status,
                tuition_due_date,
                last_payment_date,
                tuition_notes,
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
                :monthly_fee,
                :tuition_status,
                :tuition_due_date,
                :last_payment_date,
                :tuition_notes,
                :note,
                COALESCE(:started_at, CURRENT_TIMESTAMP),
                :end_at,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )'
        );

        $stmt->execute([
            ':course_id'          => (int) $data['course_id'],
            ':student_id'         => (int) $data['student_id'],
            ':status'             => (string) ($data['status'] ?? 'pending'),
            ':is_active'          => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            ':monthly_fee'        => isset($data['monthly_fee']) ? (int) $data['monthly_fee'] : 0,
            ':tuition_status'     => (string) ($data['tuition_status'] ?? 'unpaid'),
            ':tuition_due_date'   => !empty($data['tuition_due_date']) ? (string) $data['tuition_due_date'] : null,
            ':last_payment_date'  => !empty($data['last_payment_date']) ? (string) $data['last_payment_date'] : null,
            ':tuition_notes'      => $data['tuition_notes'] ?? null,
            ':note'               => $data['note'] ?? null,
            ':started_at'         => $data['started_at'] ?? null,
            ':end_at'             => $data['end_at'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        self::ensureTuitionColumns();
        $allowedFields = [
            'course_id',
            'student_id',
            'status',
            'is_active',
            'monthly_fee',
            'tuition_status',
            'tuition_due_date',
            'last_payment_date',
            'tuition_notes',
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

    public static function updateTuition(int $id, array $tuitionData): bool
    {
        self::ensureTuitionColumns();
        $allowedFields = [
            'monthly_fee',
            'tuition_status',
            'tuition_due_date',
            'last_payment_date',
            'tuition_notes',
        ];

        $setClauses = [];
        $params = [':id' => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $tuitionData)) {
                $setClauses[] = "{$field} = :{$field}";
                $params[":{$field}"] = $tuitionData[$field];
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
        self::ensureTuitionColumns();
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