<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * PDO persistence layer for Migration 017 Lernito Question Bank tables.
 */
final class LernitoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Upsert a shelf and transactionally replace its lernito_shelf_questions links ONLY
     * when the shelf is new, its `records_hash` changed, or `$forceLinks` is true.
     *
     * @param array<string, mixed> $shelfRow
     * @param array<int, array{position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>}> $records
     * @return array{shelf_id: int, is_new: bool, records_changed: bool}
     */
    public function upsertShelfWithRecords(array $shelfRow, array $records, bool $forceLinks = false): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $existingStmt = $this->db->prepare(
            'SELECT id, records_hash FROM lernito_shelves WHERE lernito_id = :lernito_id LIMIT 1'
        );
        $existingStmt->execute([':lernito_id' => $shelfRow['lernito_id']]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        $isNew = ($existing === false);
        $oldHash = is_array($existing) ? ($existing['records_hash'] ?? null) : null;
        $newHash = $shelfRow['records_hash'] ?? null;
        $recordsChanged = $isNew || $forceLinks || ($oldHash !== $newHash);

        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            if ($isNew) {
                $insertStmt = $this->db->prepare(
                    'INSERT INTO lernito_shelves
                        (lernito_id, account, name, color, creator_id, record_count, records_hash,
                         is_active, missing_since, lernito_created_at, lernito_modified_at, last_synced_at)
                     VALUES
                        (:lernito_id, :account, :name, :color, :creator_id, :record_count, :records_hash,
                         1, NULL, :lernito_created_at, :lernito_modified_at, :last_synced_at)'
                );
                $insertStmt->execute([
                    ':lernito_id'          => $shelfRow['lernito_id'],
                    ':account'             => $shelfRow['account'],
                    ':name'                => $shelfRow['name'],
                    ':color'               => $shelfRow['color'],
                    ':creator_id'          => $shelfRow['creator_id'],
                    ':record_count'        => (int) $shelfRow['record_count'],
                    ':records_hash'        => $newHash,
                    ':lernito_created_at'  => $shelfRow['lernito_created_at'],
                    ':lernito_modified_at' => $shelfRow['lernito_modified_at'],
                    ':last_synced_at'      => $now,
                ]);
                $shelfId = (int) $this->db->lastInsertId();
            } else {
                $shelfId = (int) $existing['id'];
                $updateStmt = $this->db->prepare(
                    'UPDATE lernito_shelves
                     SET account = :account,
                         name = :name,
                         color = :color,
                         creator_id = :creator_id,
                         record_count = :record_count,
                         records_hash = :records_hash,
                         is_active = 1,
                         missing_since = NULL,
                         lernito_created_at = :lernito_created_at,
                         lernito_modified_at = :lernito_modified_at,
                         last_synced_at = :last_synced_at
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':account'             => $shelfRow['account'],
                    ':name'                => $shelfRow['name'],
                    ':color'               => $shelfRow['color'],
                    ':creator_id'          => $shelfRow['creator_id'],
                    ':record_count'        => (int) $shelfRow['record_count'],
                    ':records_hash'        => $newHash,
                    ':lernito_created_at'  => $shelfRow['lernito_created_at'],
                    ':lernito_modified_at' => $shelfRow['lernito_modified_at'],
                    ':last_synced_at'      => $now,
                    ':id'                  => $shelfId,
                ]);
            }

            if ($recordsChanged) {
                $delStmt = $this->db->prepare('DELETE FROM lernito_shelf_questions WHERE shelf_id = :shelf_id');
                $delStmt->execute([':shelf_id' => $shelfId]);

                if ($records !== []) {
                    $linkStmt = $this->db->prepare(
                        'INSERT INTO lernito_shelf_questions
                            (shelf_id, question_lernito_id, position, doc_type, sub_parts_json)
                         VALUES
                            (:shelf_id, :question_lernito_id, :position, :doc_type, :sub_parts_json)'
                    );
                    foreach ($records as $rec) {
                        $subPartsJson = $rec['sub_parts'] !== null
                            ? json_encode($rec['sub_parts'], JSON_UNESCAPED_UNICODE)
                            : null;
                        $linkStmt->execute([
                            ':shelf_id'            => $shelfId,
                            ':question_lernito_id' => $rec['question_lernito_id'],
                            ':position'            => (int) $rec['position'],
                            ':doc_type'            => $rec['doc_type'],
                            ':sub_parts_json'      => $subPartsJson,
                        ]);
                    }
                }
            }

            if ($startedTx) {
                $this->db->commit();
            }

            return [
                'shelf_id'        => $shelfId,
                'is_new'          => $isNew,
                'records_changed' => $recordsChanged,
            ];
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mark active shelves belonging to `$account` that were NOT returned in a successful,
     * non-empty fetch as `is_active = 0` and set `missing_since`.
     *
     * Guard: if `$seenShelfLernitoIds` is empty, deactivates NOTHING.
     *
     * @param array<int, string> $seenShelfLernitoIds
     */
    public function deactivateMissingShelves(string $account, array $seenShelfLernitoIds): int
    {
        $seen = array_values(array_unique(array_filter($seenShelfLernitoIds, static fn (string $v): bool => $v !== '')));
        if ($seen === []) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $seenMap = array_fill_keys($seen, true);

        $stmt = $this->db->prepare(
            'SELECT id, lernito_id FROM lernito_shelves WHERE account = :account AND is_active = 1'
        );
        $stmt->execute([':account' => strtolower(trim($account))]);
        $activeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $toDeactivate = [];
        foreach ($activeRows as $row) {
            $lid = (string) ($row['lernito_id'] ?? '');
            if ($lid !== '' && !isset($seenMap[$lid])) {
                $toDeactivate[] = (int) $row['id'];
            }
        }

        if ($toDeactivate === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($toDeactivate), '?'));
        $updateStmt = $this->db->prepare(
            "UPDATE lernito_shelves
             SET is_active = 0,
                 missing_since = COALESCE(missing_since, ?)
             WHERE id IN ({$placeholders}) AND is_active = 1"
        );
        $updateStmt->execute(array_merge([$now], $toDeactivate));

        return $updateStmt->rowCount();
    }

    /**
     * Find distinct question IDs referenced by shelves of `$account` that are not yet in `lernito_questions`,
     * excluding IDs blocked by missing-question cooldown (24h) or max attempts (5).
     *
     * @return array<int, string>
     */
    public function findMissingQuestionIdsForAccount(
        string $account,
        int $limit,
        int $cooldownHours,
        int $maxAttempts,
        bool $includeExhausted = false
    ): array {
        $acc = strtolower(trim($account));
        $limit = max(1, $limit);
        $cutoff = gmdate('Y-m-d H:i:s', time() - (max(1, $cooldownHours) * 3600));

        if ($includeExhausted) {
            $sql = 'SELECT DISTINCT sq.question_lernito_id
                    FROM lernito_shelf_questions sq
                    INNER JOIN lernito_shelves s ON s.id = sq.shelf_id
                    LEFT JOIN lernito_questions q ON q.lernito_id = sq.question_lernito_id
                    WHERE s.account = :account
                      AND q.id IS NULL
                    LIMIT :lim';
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':account', $acc, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = 'SELECT DISTINCT sq.question_lernito_id
                    FROM lernito_shelf_questions sq
                    INNER JOIN lernito_shelves s ON s.id = sq.shelf_id
                    LEFT JOIN lernito_questions q ON q.lernito_id = sq.question_lernito_id
                    LEFT JOIN lernito_missing_questions m
                           ON m.lernito_id = sq.question_lernito_id
                          AND m.account = :m_account
                          AND m.resolved_at IS NULL
                    WHERE s.account = :s_account
                      AND q.id IS NULL
                      AND (
                          m.id IS NULL
                          OR (
                              m.attempts < :max_attempts
                              AND (m.last_attempt_at IS NULL OR m.last_attempt_at <= :cutoff)
                          )
                      )
                    LIMIT :lim';
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':m_account', $acc, PDO::PARAM_STR);
            $stmt->bindValue(':s_account', $acc, PDO::PARAM_STR);
            $stmt->bindValue(':max_attempts', max(1, $maxAttempts), PDO::PARAM_INT);
            $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows ?: []));
    }

    /**
     * Filter a list of Lernito question IDs down to those NOT yet present in `lernito_questions`.
     *
     * @param array<int, string> $lernitoIds
     * @return array<int, string>
     */
    public function findUncachedQuestionIds(array $lernitoIds): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $lernitoIds),
            static fn (string $v): bool => $v !== ''
        )));
        if ($clean === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $this->db->prepare("SELECT lernito_id FROM lernito_questions WHERE lernito_id IN ({$placeholders})");
        $stmt->execute($clean);
        $cached = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cachedMap = array_fill_keys(array_map('strval', $cached), true);

        $uncached = [];
        foreach ($clean as $id) {
            if (!isset($cachedMap[$id])) {
                $uncached[] = $id;
            }
        }
        return $uncached;
    }

    /**
     * Upsert a normalized question into `lernito_questions` AND transactionally rebuild its
     * `lernito_question_parts` rows (DELETE + INSERT). Also marks any pending `lernito_missing_questions`
     * row for this lernito_id as resolved.
     *
     * @param array<string, mixed> $questionRow
     * @param array<int, array<string, mixed>> $parts
     * @return int question_id
     */
    public function upsertQuestionWithParts(array $questionRow, array $parts): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            $existingStmt = $this->db->prepare(
                'SELECT id FROM lernito_questions WHERE lernito_id = :lernito_id LIMIT 1'
            );
            $existingStmt->execute([':lernito_id' => $questionRow['lernito_id']]);
            $existingId = $existingStmt->fetchColumn();

            if ($existingId === false) {
                $insertStmt = $this->db->prepare(
                    'INSERT INTO lernito_questions
                        (lernito_id, source_account, question_type, is_multipart, is_descriptive, part_count,
                         difficulty, question_html, choices_json, sub_questions_json, topics_json,
                         references_json, flags_json, has_topic, primary_segment, primary_group,
                         primary_grade, primary_lesson, primary_chapter, primary_subject,
                         lernito_version, lernito_created_by, lernito_created_at, raw_json, last_synced_at)
                     VALUES
                        (:lernito_id, :source_account, :question_type, :is_multipart, :is_descriptive, :part_count,
                         :difficulty, :question_html, :choices_json, :sub_questions_json, :topics_json,
                         :references_json, :flags_json, :has_topic, :primary_segment, :primary_group,
                         :primary_grade, :primary_lesson, :primary_chapter, :primary_subject,
                         :lernito_version, :lernito_created_by, :lernito_created_at, :raw_json, :last_synced_at)'
                );
                $insertStmt->execute([
                    ':lernito_id'         => $questionRow['lernito_id'],
                    ':source_account'     => $questionRow['source_account'],
                    ':question_type'      => $questionRow['question_type'],
                    ':is_multipart'       => (int) $questionRow['is_multipart'],
                    ':is_descriptive'     => (int) $questionRow['is_descriptive'],
                    ':part_count'         => (int) $questionRow['part_count'],
                    ':difficulty'         => $questionRow['difficulty'],
                    ':question_html'      => $questionRow['question_html'],
                    ':choices_json'       => $questionRow['choices_json'],
                    ':sub_questions_json' => $questionRow['sub_questions_json'],
                    ':topics_json'        => $questionRow['topics_json'],
                    ':references_json'    => $questionRow['references_json'],
                    ':flags_json'         => $questionRow['flags_json'],
                    ':has_topic'          => (int) $questionRow['has_topic'],
                    ':primary_segment'    => $questionRow['primary_segment'],
                    ':primary_group'      => $questionRow['primary_group'],
                    ':primary_grade'      => $questionRow['primary_grade'],
                    ':primary_lesson'     => $questionRow['primary_lesson'],
                    ':primary_chapter'    => $questionRow['primary_chapter'],
                    ':primary_subject'    => $questionRow['primary_subject'],
                    ':lernito_version'    => $questionRow['lernito_version'],
                    ':lernito_created_by' => $questionRow['lernito_created_by'],
                    ':lernito_created_at' => $questionRow['lernito_created_at'],
                    ':raw_json'           => $questionRow['raw_json'],
                    ':last_synced_at'     => $now,
                ]);
                $questionId = (int) $this->db->lastInsertId();
            } else {
                $questionId = (int) $existingId;
                $updateStmt = $this->db->prepare(
                    'UPDATE lernito_questions
                     SET source_account = COALESCE(:source_account, source_account),
                         question_type = :question_type,
                         is_multipart = :is_multipart,
                         is_descriptive = :is_descriptive,
                         part_count = :part_count,
                         difficulty = :difficulty,
                         question_html = :question_html,
                         choices_json = :choices_json,
                         sub_questions_json = :sub_questions_json,
                         topics_json = :topics_json,
                         references_json = :references_json,
                         flags_json = :flags_json,
                         has_topic = :has_topic,
                         primary_segment = :primary_segment,
                         primary_group = :primary_group,
                         primary_grade = :primary_grade,
                         primary_lesson = :primary_lesson,
                         primary_chapter = :primary_chapter,
                         primary_subject = :primary_subject,
                         lernito_version = :lernito_version,
                         lernito_created_by = :lernito_created_by,
                         lernito_created_at = :lernito_created_at,
                         raw_json = :raw_json,
                         last_synced_at = :last_synced_at
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':source_account'     => $questionRow['source_account'],
                    ':question_type'      => $questionRow['question_type'],
                    ':is_multipart'       => (int) $questionRow['is_multipart'],
                    ':is_descriptive'     => (int) $questionRow['is_descriptive'],
                    ':part_count'         => (int) $questionRow['part_count'],
                    ':difficulty'         => $questionRow['difficulty'],
                    ':question_html'      => $questionRow['question_html'],
                    ':choices_json'       => $questionRow['choices_json'],
                    ':sub_questions_json' => $questionRow['sub_questions_json'],
                    ':topics_json'        => $questionRow['topics_json'],
                    ':references_json'    => $questionRow['references_json'],
                    ':flags_json'         => $questionRow['flags_json'],
                    ':has_topic'          => (int) $questionRow['has_topic'],
                    ':primary_segment'    => $questionRow['primary_segment'],
                    ':primary_group'      => $questionRow['primary_group'],
                    ':primary_grade'      => $questionRow['primary_grade'],
                    ':primary_lesson'     => $questionRow['primary_lesson'],
                    ':primary_chapter'    => $questionRow['primary_chapter'],
                    ':primary_subject'    => $questionRow['primary_subject'],
                    ':lernito_version'    => $questionRow['lernito_version'],
                    ':lernito_created_by' => $questionRow['lernito_created_by'],
                    ':lernito_created_at' => $questionRow['lernito_created_at'],
                    ':raw_json'           => $questionRow['raw_json'],
                    ':last_synced_at'     => $now,
                    ':id'                 => $questionId,
                ]);
            }

            // Rebuild lernito_question_parts transactionally (DELETE + INSERT)
            $delParts = $this->db->prepare('DELETE FROM lernito_question_parts WHERE question_id = :qid');
            $delParts->execute([':qid' => $questionId]);

            if ($parts !== []) {
                $insPart = $this->db->prepare(
                    'INSERT INTO lernito_question_parts
                        (question_id, part_index, part_type, is_descriptive, segment, grade,
                         group_name, lesson, chapter, subject, has_topic, topics_json)
                     VALUES
                        (:question_id, :part_index, :part_type, :is_descriptive, :segment, :grade,
                         :group_name, :lesson, :chapter, :subject, :has_topic, :topics_json)'
                );
                foreach ($parts as $part) {
                    $insPart->execute([
                        ':question_id'    => $questionId,
                        ':part_index'     => (int) $part['part_index'],
                        ':part_type'      => $part['part_type'],
                        ':is_descriptive' => (int) $part['is_descriptive'],
                        ':segment'        => $part['segment'],
                        ':grade'          => $part['grade'],
                        ':group_name'     => $part['group_name'],
                        ':lesson'         => $part['lesson'],
                        ':chapter'        => $part['chapter'],
                        ':subject'        => $part['subject'],
                        ':has_topic'      => (int) $part['has_topic'],
                        ':topics_json'    => $part['topics_json'],
                    ]);
                }
            }

            // Resolve any pending missing-question record for this ID
            $resolveStmt = $this->db->prepare(
                'UPDATE lernito_missing_questions
                 SET resolved_at = :now
                 WHERE lernito_id = :lernito_id AND resolved_at IS NULL'
            );
            $resolveStmt->execute([
                ':now'        => $now,
                ':lernito_id' => $questionRow['lernito_id'],
            ]);

            if ($startedTx) {
                $this->db->commit();
            }

            return $questionId;
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record missing/failed question IDs for an account (increment attempts & update last_attempt_at).
     *
     * @param array<int, string> $lernitoIds
     */
    public function recordMissingQuestions(array $lernitoIds, string $account, ?string $lastError = 'NOT_FOUND_IN_LERNITO'): void
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $lernitoIds),
            static fn (string $v): bool => $v !== ''
        )));
        if ($clean === []) {
            return;
        }

        $acc = strtolower(trim($account));
        $now = gmdate('Y-m-d H:i:s');
        $err = $lastError !== null ? mb_substr(trim($lastError), 0, 255, 'UTF-8') : null;

        $stmt = $this->db->prepare(
            'INSERT INTO lernito_missing_questions (lernito_id, account, attempts, last_attempt_at, last_error, resolved_at)
             VALUES (:lernito_id, :account, 1, :last_attempt_at, :last_error, NULL)
             ON DUPLICATE KEY UPDATE
                attempts = IF(resolved_at IS NOT NULL, 1, attempts + 1),
                last_attempt_at = VALUES(last_attempt_at),
                last_error = VALUES(last_error),
                resolved_at = NULL'
        );

        foreach ($clean as $lid) {
            $stmt->execute([
                ':lernito_id'      => $lid,
                ':account'         => $acc,
                ':last_attempt_at' => $now,
                ':last_error'      => $err,
            ]);
        }
    }

    /**
     * Reset missing-question cooldown/attempts so manual admin retry can re-fetch them.
     *
     * @param array<int, string>|null $lernitoIds Optional explicit IDs; null resets all exhausted/pending rows for account.
     */
    public function resetMissingQuestionsForRetry(string $account, ?array $lernitoIds = null): int
    {
        $acc = strtolower(trim($account));

        if ($lernitoIds !== null && $lernitoIds !== []) {
            $clean = array_values(array_unique(array_filter(
                array_map(static fn ($v): string => trim((string) $v), $lernitoIds),
                static fn (string $v): bool => $v !== ''
            )));
            if ($clean === []) {
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $stmt = $this->db->prepare(
                "UPDATE lernito_missing_questions
                 SET attempts = 0, last_attempt_at = NULL, last_error = NULL, resolved_at = NULL
                 WHERE account = ? AND lernito_id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$acc], $clean));
            return $stmt->rowCount();
        }

        $stmt = $this->db->prepare(
            'UPDATE lernito_missing_questions
             SET attempts = 0, last_attempt_at = NULL, last_error = NULL
             WHERE account = :account AND resolved_at IS NULL'
        );
        $stmt->execute([':account' => $acc]);
        return $stmt->rowCount();
    }

    /**
     * Phase 1 monthly version refresh source:
     * Fetch question IDs and their cached `lernito_version` referenced by active shelves of `$account`
     * synced within the last `$days` days (default 60).
     *
     * TODO (Migration 018): Switch source join to `exam_session_questions` + `exam_sessions`
     * for exams created within the last 60 days once Migration 018 is deployed.
     *
     * @return array<string, ?int> Map of lernito_id => current lernito_version
     */
    public function findQuestionsForVersionRefresh(string $account, int $days = 60, int $limit = 1000): array
    {
        $acc = strtolower(trim($account));
        $cutoff = gmdate('Y-m-d H:i:s', time() - (max(1, $days) * 86400));

        $stmt = $this->db->prepare(
            'SELECT q.lernito_id, q.lernito_version
             FROM lernito_questions q
             INNER JOIN (
                 SELECT DISTINCT sq.question_lernito_id
                 FROM lernito_shelf_questions sq
                 INNER JOIN lernito_shelves s ON s.id = sq.shelf_id
                 WHERE s.account = :account
                   AND s.is_active = 1
                   AND s.last_synced_at >= :cutoff
             ) recent ON recent.question_lernito_id = q.lernito_id
             ORDER BY q.last_synced_at ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':account', $acc, PDO::PARAM_STR);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lid = (string) $row['lernito_id'];
            $map[$lid] = $row['lernito_version'] !== null ? (int) $row['lernito_version'] : null;
        }
        return $map;
    }

    /**
     * Touch `last_synced_at` for unchanged questions during monthly version refresh.
     *
     * @param array<int, string> $lernitoIds
     */
    public function touchQuestionSyncTimestamps(array $lernitoIds): void
    {
        $clean = array_values(array_unique(array_filter($lernitoIds, static fn (string $v): bool => $v !== '')));
        if ($clean === []) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $this->db->prepare(
            "UPDATE lernito_questions SET last_synced_at = ? WHERE lernito_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$now], $clean));
    }

    /**
     * Load normalized shelf records + question parts for a shelf (for ExamTopicCounter).
     *
     * @return array{
     *   shelf: ?array<string, mixed>,
     *   records: array<int, array{position: int, question_lernito_id: string, doc_type: string, sub_parts: ?array<int, int>}>,
     *   questions: array<string, array{is_multipart: bool, parts: array<int, array<string, mixed>>}>
     * }
     */
    public function loadShelfForCounting(int $shelfId): array
    {
        $shelfStmt = $this->db->prepare('SELECT * FROM lernito_shelves WHERE id = :id LIMIT 1');
        $shelfStmt->execute([':id' => $shelfId]);
        $shelf = $shelfStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($shelf)) {
            return ['shelf' => null, 'records' => [], 'questions' => []];
        }

        $recStmt = $this->db->prepare(
            'SELECT position, question_lernito_id, doc_type, sub_parts_json
             FROM lernito_shelf_questions
             WHERE shelf_id = :shelf_id
             ORDER BY position ASC'
        );
        $recStmt->execute([':shelf_id' => $shelfId]);
        $rawRecords = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        $records = [];
        $qids = [];
        foreach ($rawRecords as $r) {
            $qid = (string) $r['question_lernito_id'];
            $qids[] = $qid;
            $records[] = [
                'position'            => (int) $r['position'],
                'question_lernito_id' => $qid,
                'doc_type'            => (string) $r['doc_type'],
                'sub_parts'           => QuestionNormalizer::normalizeSubParts($r['sub_parts_json'] ?? null),
            ];
        }

        $questions = $this->loadQuestionPartsMap($qids);

        return [
            'shelf'     => $shelf,
            'records'   => $records,
            'questions' => $questions,
        ];
    }

    /**
     * Load `is_multipart` + normalized `lernito_question_parts` rows keyed by `lernito_id`.
     *
     * @param array<int, string> $lernitoIds
     * @return array<string, array{is_multipart: bool, parts: array<int, array<string, mixed>>}>
     */
    public function loadQuestionPartsMap(array $lernitoIds): array
    {
        $clean = array_values(array_unique(array_filter($lernitoIds, static fn (string $v): bool => $v !== '')));
        if ($clean === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $this->db->prepare(
            "SELECT q.lernito_id, q.is_multipart,
                    p.part_index, p.part_type, p.is_descriptive,
                    p.segment, p.grade, p.group_name, p.lesson, p.chapter, p.subject, p.has_topic
             FROM lernito_questions q
             LEFT JOIN lernito_question_parts p ON p.question_id = q.id
             WHERE q.lernito_id IN ({$placeholders})
             ORDER BY q.id ASC, p.part_index ASC"
        );
        $stmt->execute($clean);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lid = (string) $row['lernito_id'];
            if (!isset($map[$lid])) {
                $map[$lid] = [
                    'is_multipart' => (int) $row['is_multipart'] === 1,
                    'parts'        => [],
                ];
            }
            if ($row['part_index'] !== null) {
                $map[$lid]['parts'][] = [
                    'part_index'     => (int) $row['part_index'],
                    'part_type'      => (string) $row['part_type'],
                    'is_descriptive' => (int) $row['is_descriptive'],
                    'segment'        => $row['segment'],
                    'grade'          => $row['grade'],
                    'group_name'     => $row['group_name'],
                    'lesson'         => $row['lesson'],
                    'chapter'        => $row['chapter'],
                    'subject'        => $row['subject'],
                    'has_topic'      => (int) $row['has_topic'],
                ];
            }
        }

        return $map;
    }

    /**
     * Lightweight summary counts for GET /api/v1/admin/lernito/sync/status.
     *
     * @return array<string, mixed>
     */
    public function getSyncSummaryStats(int $maxAttempts = 5): array
    {
        $shelfRows = $this->db->query(
            'SELECT account,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_shelves,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_shelves,
                    MAX(last_synced_at) AS last_synced_at
             FROM lernito_shelves
             GROUP BY account'
        )->fetchAll(PDO::FETCH_ASSOC);

        $accounts = [];
        foreach ($shelfRows as $row) {
            $accounts[(string) $row['account']] = [
                'active_shelves'   => (int) ($row['active_shelves'] ?? 0),
                'inactive_shelves' => (int) ($row['inactive_shelves'] ?? 0),
                'last_synced_at'   => $row['last_synced_at'],
            ];
        }

        $totalQuestions = (int) $this->db->query('SELECT COUNT(*) FROM lernito_questions')->fetchColumn();
        $totalParts = (int) $this->db->query('SELECT COUNT(*) FROM lernito_question_parts')->fetchColumn();
        $descriptiveParts = (int) $this->db->query('SELECT COUNT(*) FROM lernito_question_parts WHERE is_descriptive = 1')->fetchColumn();

        $missingStmt = $this->db->prepare(
            'SELECT COUNT(*) AS pending_total,
                    SUM(CASE WHEN attempts >= :max_attempts THEN 1 ELSE 0 END) AS exhausted_total
             FROM lernito_missing_questions
             WHERE resolved_at IS NULL'
        );
        $missingStmt->execute([':max_attempts' => max(1, $maxAttempts)]);
        $missingRow = $missingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'accounts'          => $accounts,
            'total_questions'   => $totalQuestions,
            'total_parts'       => $totalParts,
            'descriptive_parts' => $descriptiveParts,
            'missing_pending'   => (int) ($missingRow['pending_total'] ?? 0),
            'missing_exhausted' => (int) ($missingRow['exhausted_total'] ?? 0),
        ];
    }
}
