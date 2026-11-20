<?php

namespace App\Modules\Appointments;

use App\Core\Database;
use App\Core\Sorting;

class Appointment
{
    public const SORT_WHITELIST = [
        'appointment_date' => ['expr' => 'a.appointment_date'],
        'time'             => ['expr' => ['a.appointment_date', 'a.start_time']],
        'student'          => ['expr' => 's.name', 'collate' => 'utf8mb4_persian_ci'],
        'status'           => ['expr' => 'a.status'],
    ];

    public static function resolveSort(array $sortParams = []): array
    {
        return Sorting::resolve($sortParams, self::SORT_WHITELIST, 'appointment_date', 'DESC', 'a.id');
    }

    public static function findAll(int $page, int $perPage, ?string $orderBy = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $orderSql = ($orderBy !== null && $orderBy !== '') ? $orderBy : self::resolveSort()['order_by'];
        $stmt = Database::getConnection()->prepare(
            "SELECT a.*, s.name as student_name, s.grade, s.field, s.phone
             FROM appointments a
             JOIN students s ON a.student_id = s.id
             {$orderSql}
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function countAll(): int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM appointments"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT a.*, s.name as student_name, s.grade, s.field, s.phone
             FROM appointments a
             JOIN students s ON a.student_id = s.id
             WHERE a.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function findByStudentAndDate(int $studentId, string $date): ?array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT a.*, s.name as student_name, s.grade, s.field, s.phone
             FROM appointments a
             JOIN students s ON a.student_id = s.id
             WHERE a.student_id = :student_id AND a.appointment_date = :date
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId, 'date' => $date]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO appointments (student_id, appointment_date, start_time, end_time, status, type, description, created_at, updated_at)
             VALUES (:student_id, :appointment_date, :start_time, :end_time, :status, :type, :description, NOW(), NOW())"
        );
        $endTime = isset($data['end_time']) && trim((string) $data['end_time']) !== ''
            ? trim((string) $data['end_time'])
            : null;
        $stmt->execute([
            'student_id'       => $data['student_id'],
            'appointment_date' => $data['appointment_date'],
            'start_time'       => $data['start_time'],
            'end_time'         => $endTime,
            'status'           => $data['status'] ?? 'pending',
            'type'             => $data['type'] ?? '',
            'description'      => $data['description'] ?? '',
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, array $data): int
    {
        $sets = [];
        $params = ['id' => $id];
        $allowed = ['student_id', 'appointment_date', 'start_time', 'end_time', 'status', 'type', 'description'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if ($field === 'end_time') {
                    $value = ($value !== null && trim((string) $value) !== '') ? trim((string) $value) : null;
                }
                $sets[] = "{$field} = :{$field}";
                $params[$field] = $value;
            }
        }

        if (empty($sets)) return 0;

        $sets[] = 'updated_at = NOW()';

        $stmt = Database::getConnection()->prepare(
            'UPDATE appointments SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function updateStatus(int $id, string $status): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE appointments SET status = :status, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'status' => $status]);
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('DELETE FROM appointments WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public static function getThisWeekForStudent(int $studentId): ?array
    {
        $monday = gmdate('Y-m-d', strtotime('monday this week'));
        $sunday = gmdate('Y-m-d', strtotime('sunday this week'));

        $stmt = Database::getConnection()->prepare(
            "SELECT a.*, s.name as student_name, s.grade, s.field, s.phone
             FROM appointments a
             JOIN students s ON a.student_id = s.id
             WHERE a.student_id = :student_id
               AND a.appointment_date BETWEEN :week_start AND :week_end
             ORDER BY a.appointment_date ASC
             LIMIT 1"
        );
        $stmt->execute([
            'student_id' => $studentId,
            'week_start' => $monday,
            'week_end'   => $sunday,
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch active students (plus any students who have appointments in the given week),
     * optionally restricted to a specific list of student IDs.
     */
    public static function findTargetStudents(string $weekStart, string $weekEnd, ?array $studentIds = null): array
    {
        $db = Database::getConnection();

        if ($studentIds !== null) {
            if ($studentIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $stmt = $db->prepare(
                "SELECT id, name, grade, field, phone, is_active
                 FROM students
                 WHERE id IN ({$placeholders})
                 ORDER BY id ASC"
            );
            $stmt->execute(array_values($studentIds));
            return $stmt->fetchAll();
        }

        $stmt = $db->prepare(
            "SELECT s.id, s.name, s.grade, s.field, s.phone, s.is_active
             FROM students s
             WHERE s.is_active = 1
                OR EXISTS (
                    SELECT 1 FROM appointments a
                    WHERE a.student_id = s.id
                      AND a.appointment_date BETWEEN :week_start AND :week_end
                      AND a.status IN ('pending', 'confirmed', 'scheduled')
                )
             ORDER BY s.id ASC"
        );
        $stmt->execute([
            'week_start' => $weekStart,
            'week_end'   => $weekEnd,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch all active appointments (status IN pending, confirmed, scheduled) within [weekStart, weekEnd],
     * ordered by appointment_date ASC, start_time ASC, id ASC so the first row per student is the nearest.
     */
    public static function findActiveInWeek(string $weekStart, string $weekEnd, ?array $studentIds = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT a.id, a.student_id, a.appointment_date, a.start_time, a.end_time, a.status, a.type, a.description
                FROM appointments a
                WHERE a.appointment_date BETWEEN ? AND ?
                  AND a.status IN ('pending', 'confirmed', 'scheduled')";
        $params = [$weekStart, $weekEnd];

        if ($studentIds !== null) {
            if ($studentIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $sql .= " AND a.student_id IN ({$placeholders})";
            foreach ($studentIds as $id) {
                $params[] = (int) $id;
            }
        }

        $sql .= " ORDER BY a.student_id ASC, a.appointment_date ASC, a.start_time IS NULL ASC, a.start_time ASC, a.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch primary parent contact per student (ordered by is_primary DESC, id ASC).
     *
     * @param int[] $studentIds
     * @return array<int, array>
     */
    public static function findPrimaryParentsForStudents(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = Database::getConnection()->prepare(
            "SELECT id, student_id, parent_name, relationship, phone, is_primary
             FROM parent_contacts
             WHERE student_id IN ({$placeholders})
             ORDER BY student_id ASC, is_primary DESC, id ASC"
        );
        $stmt->execute(array_values($studentIds));

        $byStudent = [];
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int) $row['student_id'];
            if (!isset($byStudent[$sid])) {
                $byStudent[$sid] = $row;
            }
        }

        return $byStudent;
    }

    /**
     * Fetch SMS log entries for a given week_date, keyed by student_id.
     */
    public static function findSmsLogsByWeek(string $weekDate, ?array $studentIds = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT *
                FROM appointment_sms_log
                WHERE week_date = ?";
        $params = [$weekDate];

        if ($studentIds !== null) {
            if ($studentIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $sql .= " AND student_id IN ({$placeholders})";
            foreach ($studentIds as $id) {
                $params[] = (int) $id;
            }
        }

        $sql .= " ORDER BY updated_at DESC, id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $byStudent = [];
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int) $row['student_id'];
            if (!isset($byStudent[$sid])) {
                $byStudent[$sid] = $row;
            }
        }

        return $byStudent;
    }

    /**
     * Upsert a row in appointment_sms_log on (student_id, week_date, mobile).
     */
    public static function upsertSmsLog(array $data): array
    {
        $db = Database::getConnection();
        $mobile = (string) ($data['mobile'] ?? '');
        $studentId = (int) $data['student_id'];
        $weekDate = (string) $data['week_date'];

        if ($mobile !== '') {
            $cleanup = $db->prepare(
                "DELETE FROM appointment_sms_log
                 WHERE student_id = :student_id AND week_date = :week_date AND mobile = ''"
            );
            $cleanup->execute([
                'student_id' => $studentId,
                'week_date'  => $weekDate,
            ]);
        }

        $stmt = $db->prepare(
            "INSERT INTO appointment_sms_log (
                student_id, week_date, mobile, recipient_type, appointment_at,
                status, error, attempts, sent_at, created_by, created_at, updated_at
            ) VALUES (
                :student_id, :week_date, :mobile, :recipient_type, :appointment_at,
                :status, :error, 1, :sent_at, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                recipient_type = :upd_recipient_type,
                appointment_at = :upd_appointment_at,
                status         = :upd_status,
                error          = :upd_error,
                attempts       = attempts + 1,
                sent_at        = COALESCE(:upd_sent_at, sent_at),
                created_by     = :upd_created_by,
                updated_at     = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            'student_id'         => $studentId,
            'week_date'          => $weekDate,
            'mobile'             => $mobile,
            'recipient_type'     => 'parent',
            'appointment_at'     => $data['appointment_at'] ?? null,
            'status'             => (string) $data['status'],
            'error'              => $data['error'] ?? null,
            'sent_at'            => $data['sent_at'] ?? null,
            'created_by'         => $data['created_by'] ?? null,
            'upd_recipient_type' => 'parent',
            'upd_appointment_at' => $data['appointment_at'] ?? null,
            'upd_status'         => (string) $data['status'],
            'upd_error'          => $data['error'] ?? null,
            'upd_sent_at'        => $data['sent_at'] ?? null,
            'upd_created_by'     => $data['created_by'] ?? null,
        ]);

        $fetch = $db->prepare(
            "SELECT * FROM appointment_sms_log
             WHERE student_id = :student_id AND week_date = :week_date AND mobile = :mobile
             LIMIT 1"
        );
        $fetch->execute([
            'student_id' => $studentId,
            'week_date'  => $weekDate,
            'mobile'     => $mobile,
        ]);

        return $fetch->fetch() ?: [];
    }

    public static function countSmsLogs(array $filters): int
    {
        [$whereSql, $params] = self::buildSmsLogWhere($filters);
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM appointment_sms_log l {$whereSql}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function findSmsLogs(array $filters, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = self::buildSmsLogWhere($filters);

        $sql = "SELECT l.*, s.name AS student_name, s.grade, s.field, u.username AS created_by_username
                FROM appointment_sms_log l
                JOIN students s ON s.id = l.student_id
                LEFT JOIN users u ON u.id = l.created_by
                {$whereSql}
                ORDER BY l.updated_at DESC, l.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = Database::getConnection()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private static function buildSmsLogWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['week_date'])) {
            $conditions[] = 'l.week_date = :week_date';
            $params['week_date'] = (string) $filters['week_date'];
        }

        if (!empty($filters['student_id'])) {
            $conditions[] = 'l.student_id = :student_id';
            $params['student_id'] = (int) $filters['student_id'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'l.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        $whereSql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$whereSql, $params];
    }
}
