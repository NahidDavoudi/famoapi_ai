<?php

namespace App\Modules\Tutoring;

use App\Core\ApiException;
use App\Core\Database;
use App\Modules\Bot\IranDay;
use PDO;

class Tutoring
{
    private const SESSION_COLUMNS = 's.id, s.session_date, s.classroom_id, s.student_id, s.attendance_id,
        s.entered_at, s.ended_at, s.client_entered_at, s.entry_source, s.needs_review,
        s.created_by, s.client_uuid, s.created_at, s.updated_at';

    /**
     * @return array<string, mixed>|null
     */
    public static function classroomForInstructor(int $instructorId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, name, sort_order, is_active
               FROM tutoring_classrooms
              WHERE instructor_id = :instructor_id
              LIMIT 1'
        );
        $stmt->execute(['instructor_id' => $instructorId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'instructor_id' => isset($row['instructor_id']) ? (int) $row['instructor_id'] : $instructorId,
            'sort_order'    => (int) $row['sort_order'],
            'is_active'     => (bool) $row['is_active'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function classroomById(int $classroomId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, name, instructor_id, sort_order, is_active
               FROM tutoring_classrooms
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $classroomId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'instructor_id' => (int) $row['instructor_id'],
            'sort_order'    => (int) $row['sort_order'],
            'is_active'     => (bool) $row['is_active'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listClassrooms(): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT c.id, c.name, c.instructor_id, c.sort_order, c.is_active,
                    COALESCE(i.name, \'\') AS teacher_name,
                    COALESCE(i.title, \'\') AS teacher_title
               FROM tutoring_classrooms c
               LEFT JOIN instructors i ON i.id = c.instructor_id
              WHERE c.is_active = 1
              ORDER BY c.sort_order ASC, c.id ASC'
        );
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = self::shapeClassroom($row);
        }

        return $items;
    }

    /**
     * Create or reactivate a tutoring classroom column.
     * If instructor_id is not provided, resolves or creates an instructor record
     * so uq_classroom_instructor and board joins work without schema changes.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function createClassroom(array $data, ?object $actor = null): array
    {
        $db = Database::getConnection();
        self::ensureAuditLogTable($db);

        $name = trim((string) ($data['name'] ?? $data['class_name'] ?? $data['classroom_name'] ?? ''));
        $teacherName = trim((string) ($data['teacher_name'] ?? ''));
        $field = trim((string) ($data['field'] ?? ''));
        $instructorId = isset($data['instructor_id']) && (int) $data['instructor_id'] > 0
            ? (int) $data['instructor_id']
            : 0;

        if ($name === '' && $teacherName !== '') {
            $name = $teacherName;
        }

        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order'])
            ? (int) $data['sort_order']
            : null;

        $db->beginTransaction();
        try {
            if ($sortOrder === null) {
                $maxSort = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM tutoring_classrooms')->fetchColumn();
                $sortOrder = $maxSort + 1;
            }

            if ($instructorId > 0) {
                $existingInstructor = $db->prepare('SELECT id FROM instructors WHERE id = :id LIMIT 1');
                $existingInstructor->execute(['id' => $instructorId]);
                if ($existingInstructor->fetchColumn() === false) {
                    throw new ApiException('مدرس مورد نظر یافت نشد', 404, 'NOT_FOUND');
                }
            } else {
                $instructorId = self::resolveOrCreateInstructorForClassroom($db, $teacherName, $name, $field);
            }

            $existingStmt = $db->prepare(
                'SELECT id, is_active FROM tutoring_classrooms WHERE instructor_id = :instructor_id LIMIT 1'
            );
            $existingStmt->execute(['instructor_id' => $instructorId]);
            $existingRoom = $existingStmt->fetch();

            if ($existingRoom !== false) {
                if ((int) $existingRoom['is_active'] === 0) {
                    $roomId = (int) $existingRoom['id'];
                    $before = self::classroomDetailById($roomId);
                    $upd = $db->prepare(
                        'UPDATE tutoring_classrooms
                            SET name = :name, sort_order = :sort_order, is_active = 1
                          WHERE id = :id'
                    );
                    $upd->execute([
                        'name'       => $name,
                        'sort_order' => $sortOrder,
                        'id'         => $roomId,
                    ]);
                    if ($teacherName !== '' || $field !== '') {
                        self::updateInstructorMetadata($db, $instructorId, $teacherName, $field);
                    }
                    $after = self::classroomDetailById($roomId);
                    self::recordClassroomAuditLog($db, $roomId, $instructorId, $actor, 'reactivated', $before, $after);
                    $db->commit();
                    return $after;
                }

                // Active classroom already exists for this instructor_id: create a dedicated instructor entry
                // so a new classroom column can always be added without hitting uq_classroom_instructor.
                $instructorId = self::createDedicatedInstructor($db, $teacherName !== '' ? $teacherName : $name, $field);
            }

            $ins = $db->prepare(
                'INSERT INTO tutoring_classrooms (name, instructor_id, sort_order, is_active, created_at)
                 VALUES (:name, :instructor_id, :sort_order, 1, :created_at)'
            );
            $ins->execute([
                'name'          => $name,
                'instructor_id' => $instructorId,
                'sort_order'    => $sortOrder,
                'created_at'    => IranDay::nowUtc(),
            ]);
            $roomId = (int) $db->lastInsertId();
            $after = self::classroomDetailById($roomId);
            self::recordClassroomAuditLog($db, $roomId, $instructorId, $actor, 'created', null, $after);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $after;
    }

    /**
     * Update an existing tutoring classroom column.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function updateClassroom(int $classroomId, array $data, ?object $actor = null): array
    {
        $db = Database::getConnection();
        self::ensureAuditLogTable($db);
        $existing = self::classroomDetailById($classroomId);

        $name = array_key_exists('name', $data) || array_key_exists('class_name', $data) || array_key_exists('classroom_name', $data)
            ? trim((string) ($data['name'] ?? $data['class_name'] ?? $data['classroom_name'] ?? ''))
            : $existing['name'];

        if ($name === '') {
            throw new ApiException('نام کلاس الزامی است', 422, 'VALIDATION_ERROR');
        }

        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order'])
            ? (int) $data['sort_order']
            : (int) $existing['sort_order'];

        $teacherName = array_key_exists('teacher_name', $data) ? trim((string) $data['teacher_name']) : null;
        $field = array_key_exists('field', $data) ? trim((string) $data['field']) : null;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE tutoring_classrooms SET name = :name, sort_order = :sort_order WHERE id = :id'
            );
            $stmt->execute([
                'name'       => $name,
                'sort_order' => $sortOrder,
                'id'         => $classroomId,
            ]);

            if (($teacherName !== null || $field !== null) && (int) $existing['instructor_id'] > 0) {
                self::updateInstructorMetadata($db, (int) $existing['instructor_id'], $teacherName, $field);
            }

            $updated = self::classroomDetailById($classroomId);
            self::recordClassroomAuditLog(
                $db,
                $classroomId,
                (int) $updated['instructor_id'],
                $actor,
                'updated',
                $existing,
                $updated
            );

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $updated;
    }

    /**
     * Delete or deactivate a tutoring classroom column and close any open session in it.
     */
    public static function deleteClassroom(int $classroomId, ?object $actor = null): void
    {
        $db = Database::getConnection();
        self::ensureAuditLogTable($db);
        $existing = self::classroomDetailById($classroomId);

        $now = IranDay::nowUtc();
        $db->beginTransaction();
        try {
            // End any open session in this classroom so students are not left with an open session
            $closeSessions = $db->prepare(
                'UPDATE tutoring_sessions
                    SET ended_at = :ended_at, updated_at = :updated_at
                  WHERE classroom_id = :classroom_id
                    AND ended_at IS NULL'
            );
            $closeSessions->execute([
                'ended_at'     => $now,
                'updated_at'   => $now,
                'classroom_id' => $classroomId,
            ]);

            $sessionCountStmt = $db->prepare('SELECT COUNT(*) FROM tutoring_sessions WHERE classroom_id = :classroom_id');
            $sessionCountStmt->execute(['classroom_id' => $classroomId]);
            $sessionCount = (int) $sessionCountStmt->fetchColumn();

            if ($sessionCount === 0) {
                $del = $db->prepare('DELETE FROM tutoring_classrooms WHERE id = :id');
                $del->execute(['id' => $classroomId]);
                self::recordClassroomAuditLog(
                    $db,
                    $classroomId,
                    (int) $existing['instructor_id'],
                    $actor,
                    'deleted',
                    $existing,
                    null
                );
            } else {
                $deact = $db->prepare('UPDATE tutoring_classrooms SET is_active = 0 WHERE id = :id');
                $deact->execute(['id' => $classroomId]);
                $after = $existing;
                $after['is_active'] = false;
                self::recordClassroomAuditLog(
                    $db,
                    $classroomId,
                    (int) $existing['instructor_id'],
                    $actor,
                    'deactivated',
                    $existing,
                    $after
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Query classroom configuration audit log entries.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function listClassroomAuditLogs(array $filters = []): array
    {
        $db = Database::getConnection();
        self::ensureAuditLogTable($db);

        $sql = 'SELECT id, classroom_id, instructor_id, actor_user_id, actor_role,
                       actor_instructor_id, action, summary, changes_json, created_at
                  FROM tutoring_classroom_audit_logs
                 WHERE 1 = 1';
        $params = [];

        if (!empty($filters['classroom_id']) && (int) $filters['classroom_id'] > 0) {
            $sql .= ' AND classroom_id = :classroom_id';
            $params['classroom_id'] = (int) $filters['classroom_id'];
        }
        if (!empty($filters['instructor_id']) && (int) $filters['instructor_id'] > 0) {
            $sql .= ' AND (instructor_id = :instructor_id OR actor_instructor_id = :actor_instructor_id)';
            $params['instructor_id'] = (int) $filters['instructor_id'];
            $params['actor_instructor_id'] = (int) $filters['instructor_id'];
        }
        if (!empty($filters['actor_user_id']) && (int) $filters['actor_user_id'] > 0) {
            $sql .= ' AND actor_user_id = :actor_user_id';
            $params['actor_user_id'] = (int) $filters['actor_user_id'];
        }
        if (!empty($filters['action']) && is_string($filters['action'])) {
            $sql .= ' AND action = :action';
            $params['action'] = trim($filters['action']);
        }

        $limit = isset($filters['limit']) && (int) $filters['limit'] > 0
            ? min(200, (int) $filters['limit'])
            : 50;

        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $logs = [];
        foreach ($stmt->fetchAll() as $row) {
            $logs[] = self::shapeAuditLog($row);
        }

        return $logs;
    }

    /**
     * @return array<string, mixed>
     */
    public static function classroomDetailById(int $classroomId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT c.id, c.name, c.instructor_id, c.sort_order, c.is_active,
                    COALESCE(i.name, \'\') AS teacher_name,
                    COALESCE(i.title, \'\') AS teacher_title
               FROM tutoring_classrooms c
               LEFT JOIN instructors i ON i.id = c.instructor_id
              WHERE c.id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $classroomId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new ApiException('کلاس درس یافت نشد', 404, 'NOT_FOUND');
        }

        return self::shapeClassroom($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function firstActiveClassroom(): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, name, instructor_id, sort_order, is_active
               FROM tutoring_classrooms
              WHERE is_active = 1
              ORDER BY sort_order ASC, id ASC
              LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'instructor_id' => (int) $row['instructor_id'],
            'sort_order'    => (int) $row['sort_order'],
            'is_active'     => (bool) $row['is_active'],
        ];
    }

    /**
     * Latest open status row for the instructor on a Tehran date.
     *
     * @return array<string, mixed>|null
     */
    public static function currentStatus(int $instructorId, string $date): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, instructor_id, status, status_date, started_at, ended_at
               FROM tutoring_teacher_status_log
              WHERE instructor_id = :instructor_id
                AND status_date = :date
                AND ended_at IS NULL
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute(['instructor_id' => $instructorId, 'date' => $date]);
        $row = $stmt->fetch();

        return $row === false ? null : self::shapeStatus($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function openSession(int $instructorId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT ' . self::SESSION_COLUMNS . '
               FROM tutoring_sessions s
               JOIN tutoring_classrooms c ON c.id = s.classroom_id
              WHERE c.instructor_id = :instructor_id
                AND s.ended_at IS NULL
              ORDER BY s.id DESC
              LIMIT 1'
        );
        $stmt->execute(['instructor_id' => $instructorId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::shapeSession($row);
    }

    /**
     * Close the instructor's open status row for the date and open a new one.
     *
     * @return array<string, mixed>
     */
    public static function setStatus(int $instructorId, string $status, string $date): array
    {
        $db = Database::getConnection();
        $now = IranDay::nowUtc();

        $db->beginTransaction();
        try {
            $close = $db->prepare(
                'UPDATE tutoring_teacher_status_log
                    SET ended_at = :ended_at
                  WHERE instructor_id = :instructor_id
                    AND status_date = :date
                    AND ended_at IS NULL'
            );
            $close->execute([
                'ended_at' => $now,
                'instructor_id' => $instructorId,
                'date' => $date,
            ]);

            $insert = $db->prepare(
                'INSERT INTO tutoring_teacher_status_log (instructor_id, status, status_date, started_at)
                 VALUES (:instructor_id, :status, :date, :started_at)'
            );
            $insert->execute([
                'instructor_id' => $instructorId,
                'status' => $status,
                'date' => $date,
                'started_at' => $now,
            ]);

            $id = (int) $db->lastInsertId();
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return self::findStatusById($id);
    }

    /**
     * Active students matching a name/phone fragment.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function searchStudents(string $query): array
    {
        $sql = 'SELECT id, name, grade, field FROM students WHERE is_active = 1';
        $params = [];
        if ($query !== '') {
            $sql .= ' AND (name LIKE :name_q OR phone LIKE :phone_q)';
            $params['name_q'] = '%' . $query . '%';
            $params['phone_q'] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY name ASC, id ASC';

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        $students = [];
        foreach ($stmt->fetchAll() as $row) {
            $students[] = [
                'id'    => (int) $row['id'],
                'name'  => $row['name'],
                'grade' => $row['grade'] !== null ? (int) $row['grade'] : null,
                'field' => $row['field'],
            ];
        }

        return $students;
    }

    /**
     * Open a tutoring session. Idempotent on client_uuid; links a same-day
     * present attendance row when one exists. The open_student_key unique
     * index rejects a second open session for the same student.
     *
     * @return array<string, mixed>
     */
    public static function startSession(
        int $classroomId,
        int $studentId,
        string $date,
        string $enteredAtUtc,
        int $createdBy,
        ?string $clientUuid
    ): array {
        $db = Database::getConnection();

        if ($clientUuid !== null) {
            $existing = self::findSessionByClientUuid($clientUuid);
            if ($existing !== null) {
                return $existing;
            }
        }

        $attendanceId = self::findPresentAttendanceId($date, $studentId);
        $now = IranDay::nowUtc();

        try {
            $stmt = $db->prepare(
                'INSERT INTO tutoring_sessions
                    (session_date, classroom_id, student_id, attendance_id, entered_at,
                     entry_source, needs_review, created_by, client_uuid, created_at, updated_at)
                 VALUES
                    (:session_date, :classroom_id, :student_id, :attendance_id, :entered_at,
                     :entry_source, 0, :created_by, :client_uuid, :created_at, :updated_at)'
            );
            $stmt->bindValue(':session_date', $date);
            $stmt->bindValue(':classroom_id', $classroomId, PDO::PARAM_INT);
            $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
            $stmt->bindValue(':attendance_id', $attendanceId, $attendanceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':entered_at', $enteredAtUtc);
            $stmt->bindValue(':entry_source', 'auto');
            $stmt->bindValue(':created_by', $createdBy, PDO::PARAM_INT);
            $stmt->bindValue(':client_uuid', $clientUuid, $clientUuid === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $now);
            $stmt->bindValue(':updated_at', $now);
            $stmt->execute();

            return self::findSessionById((int) $db->lastInsertId());
        } catch (\PDOException $e) {
            if (!in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            if ($clientUuid !== null) {
                $existing = self::findSessionByClientUuid($clientUuid);
                if ($existing !== null) {
                    return $existing;
                }
            }

            if (str_contains($e->getMessage(), 'uq_sess_one_open_per_student')) {
                throw new ApiException('این دانش‌آموز در حال حاضر یک جلسه باز دارد', 409, 'CONFLICT');
            }

            throw $e;
        }
    }

    /**
     * Close an open session. When $instructorId is provided, restricts to that
     * instructor's classroom; when null (admin), closes any open session by ID.
     *
     * @return array<string, mixed>
     */
    public static function endSession(?int $instructorId, int $sessionId, string $endedAtUtc): array
    {
        $db = Database::getConnection();

        if ($instructorId === null) {
            $stmt = $db->prepare(
                'UPDATE tutoring_sessions
                    SET ended_at = :ended_at, updated_at = :updated_at
                  WHERE id = :id
                    AND ended_at IS NULL'
            );
            $stmt->execute([
                'ended_at'   => $endedAtUtc,
                'updated_at' => IranDay::nowUtc(),
                'id'         => $sessionId,
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE tutoring_sessions
                    SET ended_at = :ended_at, updated_at = :updated_at
                  WHERE id = :id
                    AND ended_at IS NULL
                    AND classroom_id IN (
                        SELECT id FROM tutoring_classrooms WHERE instructor_id = :instructor_id
                    )'
            );
            $stmt->execute([
                'ended_at'      => $endedAtUtc,
                'updated_at'    => IranDay::nowUtc(),
                'id'            => $sessionId,
                'instructor_id' => $instructorId,
            ]);
        }

        if ($stmt->rowCount() === 0) {
            throw new ApiException('جلسه یافت نشد', 404, 'NOT_FOUND');
        }

        return self::findSessionById($sessionId);
    }

    /**
     * Every active classroom with its teacher, current open status and open session.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function board(?string $since): array
    {
        $db = Database::getConnection();
        $today = IranDay::today();

        $sql = 'SELECT c.id AS classroom_id,
                       c.name AS classroom_name,
                       c.instructor_id AS instructor_id,
                       c.sort_order AS sort_order,
                       COALESCE(i.name, \'\') AS teacher_name,
                       COALESCE(i.title, \'\') AS teacher_title,
                       (SELECT st.status FROM tutoring_teacher_status_log st
                         WHERE st.instructor_id = c.instructor_id
                           AND st.status_date = :today_status
                           AND st.ended_at IS NULL
                         ORDER BY st.id DESC LIMIT 1) AS status,
                       (SELECT st.started_at FROM tutoring_teacher_status_log st
                         WHERE st.instructor_id = c.instructor_id
                           AND st.status_date = :today_since
                           AND st.ended_at IS NULL
                         ORDER BY st.id DESC LIMIT 1) AS status_since,
                       s.id AS session_id,
                       s.entered_at AS entered_at,
                       stu.id AS student_id,
                       stu.name AS student_name,
                       stu.grade AS student_grade,
                       stu.field AS student_field
                  FROM tutoring_classrooms c
                  LEFT JOIN instructors i ON i.id = c.instructor_id
                  LEFT JOIN tutoring_sessions s ON s.id = (
                        SELECT s2.id FROM tutoring_sessions s2
                         WHERE s2.classroom_id = c.id
                           AND s2.ended_at IS NULL
                         ORDER BY s2.id DESC LIMIT 1
                  )
                  LEFT JOIN students stu ON stu.id = s.student_id
                 WHERE c.is_active = 1
                 ORDER BY c.sort_order ASC, c.id ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['today_status' => $today, 'today_since' => $today]);

        $board = [];
        foreach ($stmt->fetchAll() as $row) {
            $activity = self::laterTimestamp($row['status_since'], $row['entered_at']);
            if ($since !== null && ($activity === null || $activity < $since)) {
                continue;
            }

            $board[] = [
                'id'             => (int) $row['classroom_id'],
                'classroom_id'   => (int) $row['classroom_id'],
                'name'           => $row['classroom_name'],
                'class_name'     => $row['classroom_name'],
                'classroom_name' => $row['classroom_name'],
                'instructor_id'  => (int) $row['instructor_id'],
                'teacher_name'   => $row['teacher_name'],
                'field'          => $row['teacher_title'] !== '' ? $row['teacher_title'] : null,
                'sort_order'     => (int) $row['sort_order'],
                'status'         => $row['status'],
                'status_since'   => $row['status_since'],
                'session_id'     => $row['session_id'] !== null ? (int) $row['session_id'] : null,
                'student'        => $row['student_id'] === null ? null : [
                    'id'    => (int) $row['student_id'],
                    'name'  => $row['student_name'],
                    'grade' => $row['student_grade'] !== null ? (int) $row['student_grade'] : null,
                    'field' => $row['student_field'],
                ],
                'entered_at'     => $row['entered_at'],
            ];
        }

        return $board;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSessionByClientUuid(string $clientUuid): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT ' . self::SESSION_COLUMNS . ' FROM tutoring_sessions s WHERE s.client_uuid = :client_uuid LIMIT 1'
        );
        $stmt->execute(['client_uuid' => $clientUuid]);
        $row = $stmt->fetch();

        return $row === false ? null : self::shapeSession($row);
    }

    private static function findSessionById(int $id): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT ' . self::SESSION_COLUMNS . ' FROM tutoring_sessions s WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new ApiException('جلسه یافت نشد', 404, 'NOT_FOUND');
        }

        return self::shapeSession($row);
    }

    private static function findStatusById(int $id): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, instructor_id, status, status_date, started_at, ended_at
               FROM tutoring_teacher_status_log
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new ApiException('وضعیت یافت نشد', 404, 'NOT_FOUND');
        }

        return self::shapeStatus($row);
    }

    private static function findPresentAttendanceId(string $date, int $studentId): ?int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT id FROM daily_attendance
              WHERE attendance_date = :date
                AND student_id = :student_id
                AND status = 'present'
                AND removed_at IS NULL
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute(['date' => $date, 'student_id' => $studentId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeClassroom(array $row): array
    {
        $teacherTitle = trim((string) ($row['teacher_title'] ?? ''));

        return [
            'id'             => (int) $row['id'],
            'classroom_id'   => (int) $row['id'],
            'name'           => $row['name'],
            'class_name'     => $row['name'],
            'classroom_name' => $row['name'],
            'instructor_id'  => (int) $row['instructor_id'],
            'teacher_name'   => (string) ($row['teacher_name'] ?? ''),
            'field'          => $teacherTitle !== '' ? $teacherTitle : null,
            'sort_order'     => (int) $row['sort_order'],
            'is_active'      => (bool) $row['is_active'],
        ];
    }

    private static function resolveOrCreateInstructorForClassroom(
        PDO $db,
        string $teacherName,
        string $classroomName,
        string $field
    ): int {
        if ($teacherName !== '') {
            // Prefer an existing instructor with the same name who does not currently have an active classroom
            $stmt = $db->prepare(
                'SELECT i.id
                   FROM instructors i
                   LEFT JOIN tutoring_classrooms c ON c.instructor_id = i.id
                  WHERE i.name = :name
                    AND (c.id IS NULL OR c.is_active = 0)
                  ORDER BY i.id ASC
                  LIMIT 1'
            );
            $stmt->execute(['name' => $teacherName]);
            $foundId = $stmt->fetchColumn();
            if ($foundId !== false) {
                if ($field !== '') {
                    self::updateInstructorMetadata($db, (int) $foundId, null, $field);
                }
                return (int) $foundId;
            }
        }

        return self::createDedicatedInstructor($db, $teacherName, $field);
    }

    private static function createDedicatedInstructor(PDO $db, string $teacherName, string $field): int
    {
        $title = $field !== '' ? $field : 'مدرس';
        $initial = $teacherName !== '' ? mb_substr($teacherName, 0, 1) : null;

        $stmt = $db->prepare(
            'INSERT INTO instructors (name, title, initial_letter, display_order)
             VALUES (:name, :title, :initial_letter, 0)'
        );
        $stmt->execute([
            'name'           => $teacherName,
            'title'          => $title,
            'initial_letter' => $initial,
        ]);

        return (int) $db->lastInsertId();
    }

    private static function updateInstructorMetadata(
        PDO $db,
        int $instructorId,
        ?string $teacherName,
        ?string $field
    ): void {
        if ($teacherName !== null && $field !== null && $field !== '') {
            $stmt = $db->prepare('UPDATE instructors SET name = :name, title = :title WHERE id = :id');
            $stmt->execute(['name' => $teacherName, 'title' => $field, 'id' => $instructorId]);
            return;
        }

        if ($teacherName !== null) {
            $stmt = $db->prepare('UPDATE instructors SET name = :name WHERE id = :id');
            $stmt->execute(['name' => $teacherName, 'id' => $instructorId]);
            return;
        }

        if ($field !== null && $field !== '') {
            $stmt = $db->prepare('UPDATE instructors SET title = :title WHERE id = :id');
            $stmt->execute(['title' => $field, 'id' => $instructorId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeSession(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'session_date'      => $row['session_date'],
            'classroom_id'      => (int) $row['classroom_id'],
            'student_id'        => (int) $row['student_id'],
            'attendance_id'     => $row['attendance_id'] !== null ? (int) $row['attendance_id'] : null,
            'entered_at'        => $row['entered_at'],
            'ended_at'          => $row['ended_at'],
            'client_entered_at' => $row['client_entered_at'],
            'entry_source'      => $row['entry_source'],
            'needs_review'      => (bool) $row['needs_review'],
            'created_by'        => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'client_uuid'       => $row['client_uuid'],
            'created_at'        => $row['created_at'],
            'updated_at'        => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeStatus(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'instructor_id' => (int) $row['instructor_id'],
            'status'        => $row['status'],
            'status_date'   => $row['status_date'],
            'started_at'    => $row['started_at'],
            'ended_at'      => $row['ended_at'],
        ];
    }

    private static function laterTimestamp(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }

    private static function ensureAuditLogTable(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS tutoring_classroom_audit_logs (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                classroom_id        INT             NOT NULL,
                instructor_id       INT             DEFAULT NULL,
                actor_user_id       INT             DEFAULT NULL,
                actor_role          VARCHAR(30)     DEFAULT NULL,
                actor_instructor_id INT             DEFAULT NULL,
                action              VARCHAR(30)     NOT NULL,
                summary             VARCHAR(500)    NOT NULL DEFAULT \'\',
                changes_json        JSON            NOT NULL,
                created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tutoring_cls_audit_classroom (classroom_id, created_at),
                KEY idx_tutoring_cls_audit_actor (actor_user_id, created_at),
                KEY idx_tutoring_cls_audit_action (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private static function recordClassroomAuditLog(
        PDO $db,
        int $classroomId,
        ?int $instructorId,
        ?object $actor,
        string $action,
        ?array $before,
        ?array $after
    ): void {
        $actorUserId = null;
        $actorRole = null;
        $actorInstructorId = null;

        if ($actor !== null) {
            $rawUserId = (int) ($actor->sub ?? $actor->id ?? 0);
            $actorUserId = $rawUserId > 0 ? $rawUserId : null;
            $actorRole = isset($actor->role) && is_string($actor->role) ? $actor->role : null;
            $rawInstructorId = (int) ($actor->instructor_id ?? 0);
            if ($rawInstructorId > 0) {
                $actorInstructorId = $rawInstructorId;
            }
        }

        $trackedFields = ['name', 'teacher_name', 'field', 'instructor_id', 'sort_order', 'is_active'];
        $diff = [];

        if ($before === null && $after !== null) {
            foreach ($trackedFields as $field) {
                $diff[$field] = [
                    'from' => null,
                    'to'   => $after[$field] ?? null,
                ];
            }
        } elseif ($before !== null && $after === null) {
            foreach ($trackedFields as $field) {
                $diff[$field] = [
                    'from' => $before[$field] ?? null,
                    'to'   => null,
                ];
            }
        } elseif ($before !== null && $after !== null) {
            foreach ($trackedFields as $field) {
                $fromVal = $before[$field] ?? null;
                $toVal = $after[$field] ?? null;
                if ($fromVal !== $toVal) {
                    $diff[$field] = [
                        'from' => $fromVal,
                        'to'   => $toVal,
                    ];
                }
            }
        }

        $targetName = (string) ($after['name'] ?? $before['name'] ?? "#{$classroomId}");
        $changedKeys = array_keys($diff);
        $summary = match ($action) {
            'created'     => "کلاس «{$targetName}» ایجاد شد",
            'reactivated' => "کلاس «{$targetName}» مجدداً فعال شد",
            'updated'     => $changedKeys !== []
                ? "پیکربندی کلاس «{$targetName}» ویرایش شد (" . implode(', ', $changedKeys) . ')'
                : "پیکربندی کلاس «{$targetName}» بررسی/به‌روزرسانی شد",
            'deleted'     => "کلاس «{$targetName}» حذف شد",
            'deactivated' => "کلاس «{$targetName}» غیرفعال شد",
            default       => "تغییر وضعیت کلاس «{$targetName}» ({$action})",
        };

        $payload = json_encode([
            'before' => $before,
            'after'  => $after,
            'diff'   => $diff,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare(
            'INSERT INTO tutoring_classroom_audit_logs
                (classroom_id, instructor_id, actor_user_id, actor_role, actor_instructor_id, action, summary, changes_json, created_at)
             VALUES
                (:classroom_id, :instructor_id, :actor_user_id, :actor_role, :actor_instructor_id, :action, :summary, :changes_json, :created_at)'
        );
        $stmt->bindValue(':classroom_id', $classroomId, PDO::PARAM_INT);
        $stmt->bindValue(':instructor_id', $instructorId, $instructorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':actor_user_id', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':actor_role', $actorRole, $actorRole === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':actor_instructor_id', $actorInstructorId, $actorInstructorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':summary', $summary);
        $stmt->bindValue(':changes_json', $payload ?: '{}');
        $stmt->bindValue(':created_at', IranDay::nowUtc());
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeAuditLog(array $row): array
    {
        $decoded = json_decode((string) ($row['changes_json'] ?? '{}'), true);
        if (!is_array($decoded)) {
            $decoded = ['before' => null, 'after' => null, 'diff' => []];
        }

        $actorUserId = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        $actorInstructorId = $row['actor_instructor_id'] !== null ? (int) $row['actor_instructor_id'] : null;

        return [
            'id'                     => (int) $row['id'],
            'classroom_id'           => (int) $row['classroom_id'],
            'instructor_id'          => $row['instructor_id'] !== null ? (int) $row['instructor_id'] : null,
            'actor_user_id'          => $actorUserId,
            'actor_role'             => $row['actor_role'],
            'actor_instructor_id'    => $actorInstructorId,
            'admin_or_instructor_id' => $actorInstructorId ?? $actorUserId,
            'action'                 => $row['action'],
            'summary'                => $row['summary'],
            'changes'                => [
                'before' => $decoded['before'] ?? null,
                'after'  => $decoded['after'] ?? null,
                'diff'   => $decoded['diff'] ?? [],
            ],
            'timestamp'              => $row['created_at'],
            'created_at'             => $row['created_at'],
        ];
    }
}
