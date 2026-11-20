<?php

namespace App\Modules\Exams;

use App\Core\Database;

class ExamResult
{
    public static function findDates(?int $studentId, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $where = $studentId ? 'WHERE student_id = ?' : '';
        $params = $studentId ? [$studentId, $perPage, $offset] : [$perPage, $offset];

        $stmt = Database::getConnection()->prepare(
            "SELECT exam_date, COUNT(DISTINCT student_id) as student_count,
                    COUNT(DISTINCT subject) as subject_count,
                    ROUND(AVG(percentage), 1) as avg_percentage
             FROM exam_results
             {$where}
             GROUP BY exam_date
             ORDER BY exam_date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countDates(?int $studentId): int
    {
        $db = Database::getConnection();
        if ($studentId) {
            $stmt = $db->prepare('SELECT COUNT(DISTINCT exam_date) FROM exam_results WHERE student_id = ?');
            $stmt->execute([$studentId]);
        } else {
            $stmt = $db->query('SELECT COUNT(DISTINCT exam_date) FROM exam_results');
        }
        return (int) $stmt->fetchColumn();
    }

    public static function findStudentsByDate(string $examDate, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $stmt = Database::getConnection()->prepare(
            'SELECT s.id as student_id, s.name, s.grade, s.field,
                    COUNT(er.id) as subject_count,
                    ROUND(AVG(er.percentage), 1) as avg_percentage
             FROM students s
             INNER JOIN exam_results er ON s.id = er.student_id
             WHERE er.exam_date = ?
             GROUP BY s.id, s.name, s.grade, s.field
             ORDER BY s.name
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$examDate, $perPage, $offset]);
        return $stmt->fetchAll();
    }

    public static function countStudentsByDate(string $examDate): int
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT COUNT(DISTINCT student_id) FROM exam_results WHERE exam_date = ?'
        );
        $stmt->execute([$examDate]);
        return (int) $stmt->fetchColumn();
    }

    public static function findDetails(string $examDate, int $studentId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, name, grade, field FROM students WHERE id = ?');
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            return ['student' => null, 'subjects' => [], 'avg_percentage' => 0, 'comments' => []];
        }

        $stmt = $db->prepare(
            'SELECT subject, chapter, total_q, correct, wrong, skipped, percentage
             FROM exam_results WHERE student_id = ? AND exam_date = ? ORDER BY subject'
        );
        $stmt->execute([$studentId, $examDate]);
        $subjects = $stmt->fetchAll();

        $stmt = $db->prepare(
            'SELECT ROUND(AVG(percentage), 1) as avg FROM exam_results WHERE student_id = ? AND exam_date = ?'
        );
        $stmt->execute([$studentId, $examDate]);
        $avg = $stmt->fetchColumn();

        $comments = self::findComments($examDate, $studentId);

        return [
            'student'        => $student,
            'subjects'       => $subjects,
            'avg_percentage' => $avg ?: 0,
            'comments'       => $comments,
        ];
    }

    public static function studentExists(int $studentId): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT 1 FROM students WHERE id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function hasExamOnDate(int $studentId, string $examDate): bool
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT 1 FROM exam_results WHERE student_id = ? AND exam_date = ? LIMIT 1'
        );
        $stmt->execute([$studentId, $examDate]);
        return (bool) $stmt->fetchColumn();
    }

    public static function findComments(string $examDate, int $studentId): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT ec.id, ec.student_id, ec.exam_date, ec.teacher_user_id,
                    COALESCE(i.name, u.full_name, u.username) AS author_name,
                    u.role AS author_role,
                    ec.body, ec.created_at, ec.updated_at
             FROM exam_comments ec
             JOIN users u ON ec.teacher_user_id = u.id
             LEFT JOIN instructors i ON u.role = 'teacher' AND u.linked_id = i.id
             WHERE ec.student_id = :student_id AND ec.exam_date = :exam_date
             ORDER BY ec.updated_at DESC, ec.id DESC"
        );
        $stmt->execute([
            'student_id' => $studentId,
            'exam_date'  => $examDate,
        ]);
        return $stmt->fetchAll();
    }

    public static function upsertComment(int $studentId, string $examDate, int $teacherUserId, string $body): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO exam_comments (student_id, exam_date, teacher_user_id, body)
             VALUES (:student_id, :exam_date, :teacher_user_id, :body)
             ON DUPLICATE KEY UPDATE
                 body = :body_upd,
                 updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'student_id'      => $studentId,
            'exam_date'       => $examDate,
            'teacher_user_id' => $teacherUserId,
            'body'            => $body,
            'body_upd'        => $body,
        ]);

        $fetch = $db->prepare(
            "SELECT ec.id, ec.student_id, ec.exam_date, ec.teacher_user_id,
                    COALESCE(i.name, u.full_name, u.username) AS author_name,
                    u.role AS author_role,
                    ec.body, ec.created_at, ec.updated_at
             FROM exam_comments ec
             JOIN users u ON ec.teacher_user_id = u.id
             LEFT JOIN instructors i ON u.role = 'teacher' AND u.linked_id = i.id
             WHERE ec.student_id = :student_id
               AND ec.exam_date = :exam_date
               AND ec.teacher_user_id = :teacher_user_id
             LIMIT 1"
        );
        $fetch->execute([
            'student_id'      => $studentId,
            'exam_date'       => $examDate,
            'teacher_user_id' => $teacherUserId,
        ]);
        return $fetch->fetch() ?: [];
    }

    public static function getStudentBasicInfo(int $studentId): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT id, name, grade, field FROM students WHERE id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function createShareLink(
        int $studentId,
        string $examDate,
        string $tokenHash,
        string $expiresAt,
        ?int $createdBy
    ): int {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO exam_result_links (token_hash, student_id, exam_date, expires_at, created_by)
             VALUES (:token_hash, :student_id, :exam_date, :expires_at, :created_by)'
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'student_id' => $studentId,
            'exam_date'  => $examDate,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function markShareLinkSmsSent(int $linkId, ?string $sentAt = null): string
    {
        $timestamp = $sentAt ?? gmdate('Y-m-d H:i:s');
        $stmt = Database::getConnection()->prepare(
            'UPDATE exam_result_links SET sms_sent_at = :sms_sent_at WHERE id = :id'
        );
        $stmt->execute([
            'sms_sent_at' => $timestamp,
            'id'          => $linkId,
        ]);
        return $timestamp;
    }

    public static function findShareLinkById(int $linkId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT l.id, l.student_id, s.name AS student_name, l.exam_date,
                    l.expires_at, l.revoked_at, l.sms_sent_at, l.created_by,
                    l.last_viewed_at, l.created_at
             FROM exam_result_links l
             JOIN students s ON l.student_id = s.id
             WHERE l.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $linkId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listShareLinks(?int $studentId = null, ?string $examDate = null): array
    {
        $conditions = ['1=1'];
        $params = [];

        if ($studentId !== null && $studentId > 0) {
            $conditions[] = 'l.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        if ($examDate !== null && $examDate !== '') {
            $conditions[] = 'l.exam_date = :exam_date';
            $params['exam_date'] = $examDate;
        }

        $sql = 'SELECT l.id, l.student_id, s.name AS student_name, l.exam_date,
                       l.expires_at, l.revoked_at, l.sms_sent_at, l.created_by,
                       l.last_viewed_at, l.created_at
                FROM exam_result_links l
                JOIN students s ON l.student_id = s.id
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT 100';

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function revokeShareLink(int $linkId): bool
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE exam_result_links
             SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE id = :id'
        );
        $stmt->execute(['id' => $linkId]);
        return $stmt->rowCount() > 0;
    }

    public static function findShareLinkByHash(string $tokenHash): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, token_hash, student_id, exam_date, expires_at, revoked_at, last_viewed_at, created_at,
                    CASE WHEN expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS is_expired
             FROM exam_result_links
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findActiveShareLinkByHash(string $tokenHash): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, token_hash, student_id, exam_date, expires_at, revoked_at, last_viewed_at, created_at
             FROM exam_result_links
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function touchShareLinkViewed(int $linkId): void
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE exam_result_links SET last_viewed_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['id' => $linkId]);
    }

    public static function findPublicResult(int $studentId, string $examDate): ?array
    {
        $db = Database::getConnection();
        // Explicitly select only non-sensitive student fields (no national_id, no phone, no chat_id)
        $stmt = $db->prepare('SELECT name, grade, field FROM students WHERE id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT subject, chapter, total_q, correct, wrong, skipped, percentage
             FROM exam_results
             WHERE student_id = ? AND exam_date = ?
             ORDER BY subject'
        );
        $stmt->execute([$studentId, $examDate]);
        $subjects = $stmt->fetchAll();
        if ($subjects === []) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT ROUND(AVG(percentage), 1) AS avg FROM exam_results WHERE student_id = ? AND exam_date = ?'
        );
        $stmt->execute([$studentId, $examDate]);
        $avg = $stmt->fetchColumn();

        $cStmt = $db->prepare(
            "SELECT COALESCE(i.name, u.full_name, u.username) AS author_name,
                    u.role AS author_role,
                    ec.body, ec.created_at, ec.updated_at
             FROM exam_comments ec
             JOIN users u ON ec.teacher_user_id = u.id
             LEFT JOIN instructors i ON u.role = 'teacher' AND u.linked_id = i.id
             WHERE ec.student_id = :student_id AND ec.exam_date = :exam_date
             ORDER BY ec.updated_at DESC, ec.id DESC"
        );
        $cStmt->execute([
            'student_id' => $studentId,
            'exam_date'  => $examDate,
        ]);
        $comments = $cStmt->fetchAll();

        return [
            'student_name'   => $student['name'],
            'student'        => [
                'name'  => $student['name'],
                'grade' => isset($student['grade']) ? (int) $student['grade'] : null,
                'field' => $student['field'] ?? null,
            ],
            'exam_date'      => $examDate,
            'subjects'       => $subjects,
            'avg_percentage' => $avg !== false && $avg !== null ? (float) $avg : 0.0,
            'comments'       => $comments,
        ];
    }

    public static function findAll(array $filters, int $page, int $perPage): array
    {
        $conditions = ['1=1'];
        $params = [];
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        if (!empty($filters['student_id'])) {
            $conditions[] = 'er.student_id = ?';
            $params[] = (int) $filters['student_id'];
        }
        if (!empty($filters['date_from'])) {
            $conditions[] = 'er.exam_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = 'er.exam_date <= ?';
            $params[] = $filters['date_to'];
        }

        $sql = 'SELECT er.*, s.name as student_name, s.field, s.grade, f.file_path
                FROM exam_results er
                JOIN students s ON er.student_id = s.id
                LEFT JOIN files f ON er.file_id = f.id
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY er.exam_date DESC, s.name
                LIMIT ? OFFSET ?';

        $stmt = Database::getConnection()->prepare($sql);
        array_push($params, $perPage, $offset);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAll(array $filters): int
    {
        $conditions = ['1=1'];
        $params = [];

        if (!empty($filters['student_id'])) {
            $conditions[] = 'er.student_id = ?';
            $params[] = (int) $filters['student_id'];
        }
        if (!empty($filters['date_from'])) {
            $conditions[] = 'er.exam_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = 'er.exam_date <= ?';
            $params[] = $filters['date_to'];
        }

        $sql = 'SELECT COUNT(*) FROM exam_results er WHERE ' . implode(' AND ', $conditions);
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function saveBulk(int $studentId, string $examDate, array $subjects): int
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM exam_results WHERE student_id = ? AND exam_date = ?')
                ->execute([$studentId, $examDate]);
            $stmt = $db->prepare(
                'INSERT INTO exam_results (student_id, exam_date, subject, chapter, total_q, correct, wrong, skipped)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $inserted = 0;
            foreach ($subjects as $subj) {
                $total = (int) $subj['total_q'];
                $correct = (int) ($subj['correct'] ?? 0);
                $wrong = (int) ($subj['wrong'] ?? 0);
                $skipped = (int) ($subj['skipped'] ?? 0);
                $stmt->execute([
                    $studentId, $examDate,
                    $subj['subject'],
                    $subj['chapter'] ?? null,
                    $total, $correct, $wrong, $skipped
                ]);
                $inserted++;
            }
            $db->commit();
            return $inserted;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
