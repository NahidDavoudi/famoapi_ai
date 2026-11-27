<?php

declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\Database;
use App\Modules\Lernito\QuestionNormalizer;
use PDO;
use Throwable;

/**
 * PDO repository for Phase 2 tables:
 *   student_shelves, exam_sessions, exam_session_questions, exam_session_topics,
 *   and shelf lookup from lernito_shelves.
 */
final class ExamSessionRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Search/list Lernito shelves for admin selection dropdowns.
     *
     * @param array{account?: ?string, q?: ?string, active_only?: bool, student_id?: ?int} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listShelves(array $filters, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $binds = [];

        if (!empty($filters['account'])) {
            $where[] = 's.account = :account';
            $binds[':account'] = strtolower(trim((string) $filters['account']));
        }
        if (!isset($filters['active_only']) || $filters['active_only']) {
            $where[] = 's.is_active = 1';
        }
        if (!empty($filters['q'])) {
            $where[] = '(s.name LIKE :q_name OR s.lernito_id LIKE :q_id)';
            $like = '%' . trim((string) $filters['q']) . '%';
            $binds[':q_name'] = $like;
            $binds[':q_id'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lernito_shelves s WHERE {$whereSql}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $studentJoin = '';
        $assignedSelect = '0 AS is_assigned';
        if (!empty($filters['student_id'])) {
            $studentJoin = 'LEFT JOIN student_shelves ss ON ss.shelf_id = s.id AND ss.student_id = :st_id';
            $assignedSelect = 'CASE WHEN ss.id IS NOT NULL THEN 1 ELSE 0 END AS is_assigned';
            $binds[':st_id'] = (int) $filters['student_id'];
        }

        $sql = "SELECT s.id, s.lernito_id, s.account, s.name, s.color, s.record_count,
                       s.is_active, s.missing_since, s.last_synced_at, {$assignedSelect}
                FROM lernito_shelves s
                {$studentJoin}
                WHERE {$whereSql}
                ORDER BY is_assigned DESC, s.is_active DESC, s.name ASC, s.id DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->db->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', max(1, min(100, $perPage)), PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(static function (array $r): array {
            return [
                'id'             => (int) $r['id'],
                'lernito_id'     => (string) $r['lernito_id'],
                'account'        => (string) $r['account'],
                'name'           => (string) $r['name'],
                'color'          => $r['color'],
                'record_count'   => (int) $r['record_count'],
                'is_active'      => (int) $r['is_active'] === 1,
                'is_assigned'    => (int) $r['is_assigned'] === 1,
                'missing_since'  => $r['missing_since'],
                'last_synced_at' => $r['last_synced_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findShelfById(int $shelfId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM lernito_shelves WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $shelfId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listStudentShelves(int $studentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ss.id AS assignment_id, ss.student_id, ss.shelf_id, ss.notes, ss.assigned_at,
                    s.lernito_id, s.account, s.name, s.color, s.record_count, s.is_active, s.last_synced_at
             FROM student_shelves ss
             INNER JOIN lernito_shelves s ON s.id = ss.shelf_id
             WHERE ss.student_id = :student_id
             ORDER BY ss.assigned_at DESC, ss.id DESC'
        );
        $stmt->execute([':student_id' => $studentId]);

        return array_map(static function (array $r): array {
            return [
                'assignment_id'  => (int) $r['assignment_id'],
                'student_id'     => (int) $r['student_id'],
                'shelf_id'       => (int) $r['shelf_id'],
                'lernito_id'     => (string) $r['lernito_id'],
                'account'        => (string) $r['account'],
                'name'           => (string) $r['name'],
                'color'          => $r['color'],
                'record_count'   => (int) $r['record_count'],
                'is_active'      => (int) $r['is_active'] === 1,
                'notes'          => $r['notes'],
                'assigned_at'    => $r['assigned_at'],
                'last_synced_at' => $r['last_synced_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function assignStudentShelf(int $studentId, int $shelfId, ?int $assignedByUserId, ?string $notes = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO student_shelves (student_id, shelf_id, assigned_by_user_id, notes)
             VALUES (:student_id, :shelf_id, :assigned_by, :notes)
             ON DUPLICATE KEY UPDATE
                notes = COALESCE(VALUES(notes), notes)'
        );
        $stmt->execute([
            ':student_id'  => $studentId,
            ':shelf_id'    => $shelfId,
            ':assigned_by' => $assignedByUserId,
            ':notes'       => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255, 'UTF-8') : null,
        ]);
    }

    public function unassignStudentShelf(int $studentId, int $shelfId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM student_shelves WHERE student_id = :student_id AND shelf_id = :shelf_id'
        );
        $stmt->execute([
            ':student_id' => $studentId,
            ':shelf_id'   => $shelfId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findSessionById(int $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM exam_sessions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrateSessionRow($row) : null;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findSessionByUniqueKey(int $studentId, int $shelfId, string $examDate): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM exam_sessions
             WHERE student_id = :student_id AND shelf_id = :shelf_id AND exam_date = :exam_date
             LIMIT 1'
        );
        $stmt->execute([
            ':student_id' => $studentId,
            ':shelf_id'   => $shelfId,
            ':exam_date'  => $examDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrateSessionRow($row) : null;
    }

    /**
     * @param array{student_id?: ?int, exam_date?: ?string, shelf_id?: ?int} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listSessions(array $filters, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $binds = [];

        if (!empty($filters['student_id'])) {
            $where[] = 'es.student_id = :student_id';
            $binds[':student_id'] = (int) $filters['student_id'];
        }
        if (!empty($filters['exam_date'])) {
            $where[] = 'es.exam_date = :exam_date';
            $binds[':exam_date'] = trim((string) $filters['exam_date']);
        }
        if (!empty($filters['shelf_id'])) {
            $where[] = 'es.shelf_id = :shelf_id';
            $binds[':shelf_id'] = (int) $filters['shelf_id'];
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM exam_sessions es WHERE {$whereSql}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare(
            "SELECT es.*, st.name AS student_name
             FROM exam_sessions es
             LEFT JOIN students st ON st.id = es.student_id
             WHERE {$whereSql}
             ORDER BY es.exam_date DESC, es.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', max(1, min(100, $perPage)), PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hydrated = $this->hydrateSessionRow($row);
            $hydrated['student_name'] = $row['student_name'] ?? null;
            $items[] = $hydrated;
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Create exam_session + immutable snapshot of exam_session_questions + initial auto topics atomically.
     *
     * @param array<string, mixed> $sessionData
     * @param array<int, array{position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>}> $records
     * @param array<int, array<string, mixed>> $topicRows
     */
    public function createSessionWithSnapshot(array $sessionData, array $records, array $topicRows): int
    {
        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO exam_sessions
                    (student_id, shelf_id, exam_date, shelf_name_snapshot, shelf_account_snapshot,
                     total_questions, skipped_descriptive, missing_question_count, is_incomplete,
                     has_unclassified_topics, warnings_json, created_by_user_id)
                 VALUES
                    (:student_id, :shelf_id, :exam_date, :shelf_name, :shelf_account,
                     :total_questions, :skipped_descriptive, :missing_question_count, :is_incomplete,
                     :has_unclassified_topics, :warnings_json, :created_by)'
            );
            $stmt->execute([
                ':student_id'              => (int) $sessionData['student_id'],
                ':shelf_id'                => (int) $sessionData['shelf_id'],
                ':exam_date'               => (string) $sessionData['exam_date'],
                ':shelf_name'              => (string) $sessionData['shelf_name_snapshot'],
                ':shelf_account'           => (string) $sessionData['shelf_account_snapshot'],
                ':total_questions'         => (int) $sessionData['total_questions'],
                ':skipped_descriptive'     => (int) $sessionData['skipped_descriptive'],
                ':missing_question_count'  => (int) $sessionData['missing_question_count'],
                ':is_incomplete'           => !empty($sessionData['is_incomplete']) ? 1 : 0,
                ':has_unclassified_topics' => !empty($sessionData['has_unclassified_topics']) ? 1 : 0,
                ':warnings_json'           => json_encode($sessionData['warnings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by'              => $sessionData['created_by_user_id'] ?? null,
            ]);
            $sessionId = (int) $this->db->lastInsertId();

            if ($records !== []) {
                $qStmt = $this->db->prepare(
                    'INSERT INTO exam_session_questions
                        (exam_session_id, position, question_lernito_id, doc_type, sub_parts_json, is_important)
                     VALUES
                        (:session_id, :position, :qid, :doc_type, :sub_parts_json, 0)'
                );
                foreach ($records as $rec) {
                    $qStmt->execute([
                        ':session_id'     => $sessionId,
                        ':position'       => (int) $rec['position'],
                        ':qid'            => (string) $rec['question_lernito_id'],
                        ':doc_type'       => (string) ($rec['doc_type'] ?? 'Question'),
                        ':sub_parts_json' => $rec['sub_parts'] !== null
                            ? json_encode($rec['sub_parts'], JSON_UNESCAPED_UNICODE)
                            : null,
                    ]);
                }
            }

            $this->insertTopicRows($sessionId, $topicRows);

            if ($startedTx) {
                $this->db->commit();
            }

            return $sessionId;
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Load the immutable question snapshot for an exam session.
     *
     * @return array<int, array{id: int, position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>, is_important: bool}>
     */
    public function loadSessionQuestions(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, position, question_lernito_id, doc_type, sub_parts_json, is_important
             FROM exam_session_questions
             WHERE exam_session_id = :session_id
             ORDER BY position ASC'
        );
        $stmt->execute([':session_id' => $sessionId]);

        return array_map(static function (array $r): array {
            return [
                'id'                  => (int) $r['id'],
                'position'            => (int) $r['position'],
                'question_lernito_id' => (string) $r['question_lernito_id'],
                'doc_type'            => (string) $r['doc_type'],
                'sub_parts'           => QuestionNormalizer::normalizeSubParts($r['sub_parts_json'] ?? null),
                'is_important'        => (int) $r['is_important'] === 1,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadSessionTopics(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM exam_session_topics
             WHERE exam_session_id = :session_id
             ORDER BY display_order ASC, id ASC'
        );
        $stmt->execute([':session_id' => $sessionId]);

        return array_map(static function (array $r): array {
            $topicIds = [];
            if (is_string($r['topic_ids_json']) && $r['topic_ids_json'] !== '') {
                $dec = json_decode($r['topic_ids_json'], true);
                if (is_array($dec)) {
                    $topicIds = array_values(array_map('intval', $dec));
                }
            }
            $topicLabels = [];
            if (is_string($r['topic_labels_json']) && $r['topic_labels_json'] !== '') {
                $decL = json_decode($r['topic_labels_json'], true);
                if (is_array($decL)) {
                    $topicLabels = array_values(array_map('strval', $decL));
                }
            }
            $hasTopic = (int) $r['has_topic'] === 1;

            return [
                'id'              => (int) $r['id'],
                'exam_session_id' => (int) $r['exam_session_id'],
                'subject_id'      => $r['subject_id'] !== null ? (int) $r['subject_id'] : null,
                'lesson'          => (string) $r['lesson'],
                'topic_ids'       => $topicIds,
                'topic_labels'    => $topicLabels,
                'topic'           => $r['topic'],
                'auto_count'      => (int) $r['auto_count'],
                'final_count'     => (int) $r['final_count'],
                'is_manual'       => (int) $r['is_manual'] === 1,
                'has_topic'       => $hasTopic,
                'is_unclassified' => !$hasTopic,
                'display_order'   => (int) $r['display_order'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Recalculate: update session stats and replace ONLY rows with `is_manual = 0`,
     * leaving admin-edited rows (`is_manual = 1`) completely untouched.
     *
     * @param array<string, mixed> $stats
     * @param array<int, array<string, mixed>> $autoTopicRows
     */
    public function updateStatsAndReplaceAutoTopics(int $sessionId, array $stats, array $autoTopicRows): void
    {
        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            $upd = $this->db->prepare(
                'UPDATE exam_sessions
                 SET total_questions = :total_questions,
                     skipped_descriptive = :skipped_descriptive,
                     missing_question_count = :missing_question_count,
                     is_incomplete = :is_incomplete,
                     has_unclassified_topics = :has_unclassified_topics,
                     warnings_json = :warnings_json
                 WHERE id = :id'
            );
            $upd->execute([
                ':total_questions'         => (int) $stats['total_questions'],
                ':skipped_descriptive'     => (int) $stats['skipped_descriptive'],
                ':missing_question_count'  => (int) $stats['missing_question_count'],
                ':is_incomplete'           => !empty($stats['is_incomplete']) ? 1 : 0,
                ':has_unclassified_topics' => !empty($stats['has_unclassified_topics']) ? 1 : 0,
                ':warnings_json'           => json_encode($stats['warnings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id'                      => $sessionId,
            ]);

            // Preserve all manual rows; delete only auto rows (is_manual = 0)
            $manualStmt = $this->db->prepare(
                'SELECT subject_id, lesson FROM exam_session_topics WHERE exam_session_id = :id AND is_manual = 1'
            );
            $manualStmt->execute([':id' => $sessionId]);
            $manualRows = $manualStmt->fetchAll(PDO::FETCH_ASSOC);
            $manualSubjectIds = [];
            $manualLessons = [];
            foreach ($manualRows as $mr) {
                if ($mr['subject_id'] !== null) {
                    $manualSubjectIds[(int) $mr['subject_id']] = true;
                }
                $manualLessons[(string) $mr['lesson']] = true;
            }

            $this->db->prepare('DELETE FROM exam_session_topics WHERE exam_session_id = :id AND is_manual = 0')
                ->execute([':id' => $sessionId]);

            $filteredAuto = [];
            foreach ($autoTopicRows as $row) {
                $sid = $row['subject_id'] ?? null;
                if ($sid !== null && isset($manualSubjectIds[(int) $sid])) {
                    continue;
                }
                if ($sid === null && isset($manualLessons[(string) $row['lesson']])) {
                    continue;
                }
                $filteredAuto[] = $row;
            }

            $this->insertTopicRows($sessionId, $filteredAuto);

            if ($startedTx) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Save admin-edited topic suggestions for an exam session (marks edited rows `is_manual = 1`).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function replaceSessionTopics(int $sessionId, array $rows): void
    {
        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare('DELETE FROM exam_session_topics WHERE exam_session_id = :id')
                ->execute([':id' => $sessionId]);

            $this->insertTopicRows($sessionId, $rows);

            if ($startedTx) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function loadSavedResultsForSession(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, exam_session_id, exam_session_topic_id, subject_id, topic_ids_json,
                    subject, chapter, total_q, correct, wrong, skipped, percentage
             FROM exam_results
             WHERE exam_session_id = :session_id
             ORDER BY id ASC'
        );
        $stmt->execute([':session_id' => $sessionId]);

        return array_map(static function (array $r): array {
            $topicIds = [];
            if (is_string($r['topic_ids_json']) && $r['topic_ids_json'] !== '') {
                $dec = json_decode($r['topic_ids_json'], true);
                if (is_array($dec)) {
                    $topicIds = array_values(array_map('intval', $dec));
                }
            }
            return [
                'id'                    => (int) $r['id'],
                'exam_session_id'       => (int) $r['exam_session_id'],
                'exam_session_topic_id' => $r['exam_session_topic_id'] !== null ? (int) $r['exam_session_topic_id'] : null,
                'subject_id'            => $r['subject_id'] !== null ? (int) $r['subject_id'] : null,
                'topic_ids'             => $topicIds,
                'subject'               => (string) $r['subject'],
                'chapter'               => $r['chapter'],
                'total_q'               => (int) $r['total_q'],
                'correct'               => (int) $r['correct'],
                'wrong'                 => (int) $r['wrong'],
                'skipped'               => (int) $r['skipped'],
                'percentage'            => (float) $r['percentage'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertTopicRows(int $sessionId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO exam_session_topics
                (exam_session_id, subject_id, lesson, topic_ids_json, topic_labels_json, topic,
                 auto_count, final_count, is_manual, has_topic, display_order)
             VALUES
                (:session_id, :subject_id, :lesson, :topic_ids_json, :topic_labels_json, :topic,
                 :auto_count, :final_count, :is_manual, :has_topic, :display_order)'
        );

        foreach ($rows as $idx => $row) {
            $topicIds = array_values(array_unique(array_filter(
                array_map('intval', (array) ($row['topic_ids'] ?? [])),
                static fn (int $v): bool => $v > 0
            )));
            $topicLabels = array_values(array_map('strval', (array) ($row['topic_labels'] ?? [])));

            $stmt->execute([
                ':session_id'        => $sessionId,
                ':subject_id'        => isset($row['subject_id']) && $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
                ':lesson'            => mb_substr((string) $row['lesson'], 0, 128, 'UTF-8'),
                ':topic_ids_json'    => json_encode($topicIds, JSON_UNESCAPED_UNICODE),
                ':topic_labels_json' => json_encode($topicLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':topic'             => isset($row['topic']) && $row['topic'] !== null && trim((string) $row['topic']) !== ''
                    ? mb_substr(trim((string) $row['topic']), 0, 255, 'UTF-8')
                    : null,
                ':auto_count'        => max(0, (int) ($row['auto_count'] ?? 0)),
                ':final_count'       => max(0, (int) ($row['final_count'] ?? ($row['auto_count'] ?? 0))),
                ':is_manual'         => !empty($row['is_manual']) ? 1 : 0,
                ':has_topic'         => array_key_exists('has_topic', $row) ? ($row['has_topic'] ? 1 : 0) : 1,
                ':display_order'     => isset($row['display_order']) ? (int) $row['display_order'] : (int) $idx,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateSessionRow(array $row): array
    {
        $warnings = [];
        if (is_string($row['warnings_json'] ?? null) && $row['warnings_json'] !== '') {
            $dec = json_decode($row['warnings_json'], true);
            if (is_array($dec)) {
                $warnings = $dec;
            }
        }

        return [
            'id'                      => (int) $row['id'],
            'student_id'              => (int) $row['student_id'],
            'shelf_id'                => (int) $row['shelf_id'],
            'exam_date'               => (string) $row['exam_date'],
            'shelf_name_snapshot'     => (string) $row['shelf_name_snapshot'],
            'shelf_account_snapshot'  => (string) $row['shelf_account_snapshot'],
            'total_questions'         => (int) $row['total_questions'],
            'skipped_descriptive'     => (int) $row['skipped_descriptive'],
            'missing_question_count'  => (int) $row['missing_question_count'],
            'is_incomplete'           => (int) $row['is_incomplete'] === 1,
            'has_unclassified_topics' => (int) ($row['has_unclassified_topics'] ?? 0) === 1,
            'warnings'                => $warnings,
            'created_by_user_id'      => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            'created_at'              => $row['created_at'] ?? null,
            'updated_at'              => $row['updated_at'] ?? null,
        ];
    }
}
