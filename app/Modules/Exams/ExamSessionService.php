<?php

declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\ApiException;
use App\Core\Pagination;
use App\Modules\Curriculum\CurriculumRepository;
use App\Modules\Lernito\ExamTopicCounter;
use App\Modules\Lernito\LernitoRepository;
use App\Modules\Lernito\LernitoSyncService;
use App\Modules\Lernito\QuestionNormalizer;

/**
 * Orchestrates Exam Sessions (Phase 2):
 *   - Assigning Lernito shelves to students
 *   - Creating an exam session with on-demand `ensureQuestions()` (short timeout, never blocks form)
 *   - Computing Mode 1 defaults: 1 row per lesson (`subject_id`) + array of `curriculum_nodes` topic IDs
 *     up to `subtopic` (`subject0`) depth (never descending to `detail`/`subject1`)
 *   - Allowing unrestricted manual admin overrides (`is_manual = 1`) and safe recalculation (`is_manual = 0`)
 */
final class ExamSessionService
{
    private ExamSessionRepository $sessionRepo;
    private LernitoRepository $lernitoRepo;
    private CurriculumRepository $curriculumRepo;
    private LernitoSyncService $syncService;

    public function __construct(
        ?ExamSessionRepository $sessionRepo = null,
        ?LernitoRepository $lernitoRepo = null,
        ?CurriculumRepository $curriculumRepo = null,
        ?LernitoSyncService $syncService = null
    ) {
        $this->sessionRepo = $sessionRepo ?? new ExamSessionRepository();
        $this->lernitoRepo = $lernitoRepo ?? new LernitoRepository();
        $this->curriculumRepo = $curriculumRepo ?? new CurriculumRepository();
        $this->syncService = $syncService ?? new LernitoSyncService(repository: $this->lernitoRepo);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{shelves: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listShelves(array $filters, int $page, int $perPage): array
    {
        $res = $this->sessionRepo->listShelves($filters, $page, $perPage);
        $pagination = Pagination::build($page, $perPage, $res['total']);
        return ['shelves' => $res['items'], 'pagination' => $pagination];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listStudentShelves(int $studentId): array
    {
        if (!ExamResult::studentExists($studentId)) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }
        return $this->sessionRepo->listStudentShelves($studentId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function assignStudentShelf(int $studentId, int $shelfId, ?int $actorUserId, ?string $notes = null): array
    {
        if (!ExamResult::studentExists($studentId)) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }
        $shelf = $this->sessionRepo->findShelfById($shelfId);
        if ($shelf === null) {
            throw new ApiException('شلف لرنیتو یافت نشد', 404, 'NOT_FOUND');
        }

        $this->sessionRepo->assignStudentShelf($studentId, $shelfId, $actorUserId, $notes);
        return $this->sessionRepo->listStudentShelves($studentId);
    }

    public function unassignStudentShelf(int $studentId, int $shelfId): bool
    {
        if (!ExamResult::studentExists($studentId)) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }
        return $this->sessionRepo->unassignStudentShelf($studentId, $shelfId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{sessions: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listSessions(array $filters, int $page, int $perPage): array
    {
        $res = $this->sessionRepo->listSessions($filters, $page, $perPage);
        $pagination = Pagination::build($page, $perPage, $res['total']);
        return ['sessions' => $res['items'], 'pagination' => $pagination];
    }

    /**
     * Create (or return existing) exam session for `(student_id, shelf_id, exam_date)`.
     * Auto-assigns the shelf to the student in `student_shelves` if not already linked,
     * calls `ensureQuestions()` with a short timeout, snapshots shelf questions, and computes
     * Mode 1 default rows for the exam results entry form.
     *
     * @return array<string, mixed>
     */
    public function createOrGetSession(
        int $studentId,
        int $shelfId,
        string $examDate,
        ?int $actorUserId = null
    ): array {
        if ($studentId <= 0 || $shelfId <= 0 || !$this->isValidDate($examDate)) {
            throw new ApiException('پارامترهای ساخت سشن آزمون نامعتبر است', 422, 'VALIDATION_ERROR');
        }
        if (!ExamResult::studentExists($studentId)) {
            throw new ApiException('دانش‌آموز یافت نشد', 404, 'NOT_FOUND');
        }

        $shelf = $this->sessionRepo->findShelfById($shelfId);
        if ($shelf === null) {
            throw new ApiException('شلف لرنیتو یافت نشد', 404, 'NOT_FOUND');
        }

        // Keep panel-owned assignment in sync automatically when selecting a shelf for a student
        $this->sessionRepo->assignStudentShelf($studentId, $shelfId, $actorUserId);

        $existing = $this->sessionRepo->findSessionByUniqueKey($studentId, $shelfId, $examDate);
        if ($existing !== null) {
            return $this->buildSessionResponse($existing, false);
        }

        $loaded = $this->lernitoRepo->loadShelfForCounting($shelfId);
        $records = $loaded['records'];
        $questionIds = array_values(array_unique(array_map(
            static fn (array $r): string => (string) $r['question_lernito_id'],
            $records
        )));

        // Non-blocking on-demand fetch (~15s timeout): NEVER throws to lock the form!
        $ensureRes = $this->syncService->ensureQuestions($questionIds, (string) $shelf['account']);

        // Reload parts map after ensureQuestions populated any newly fetched questions
        $partsMap = $this->lernitoRepo->loadQuestionPartsMap($questionIds);
        $computed = $this->computeMode1Suggestions($records, $partsMap, $ensureRes['error']);

        $sessionId = $this->sessionRepo->createSessionWithSnapshot(
            sessionData: [
                'student_id'              => $studentId,
                'shelf_id'                => $shelfId,
                'exam_date'               => $examDate,
                'shelf_name_snapshot'     => (string) $shelf['name'],
                'shelf_account_snapshot'  => (string) $shelf['account'],
                'total_questions'         => $computed['total_questions'],
                'skipped_descriptive'     => $computed['skipped_descriptive'],
                'missing_question_count'  => $computed['missing_question_count'],
                'is_incomplete'           => $ensureRes['incomplete'] || $computed['missing_question_count'] > 0,
                'has_unclassified_topics' => $computed['has_unclassified_topics'],
                'warnings'                => $computed['warnings'],
                'created_by_user_id'      => $actorUserId,
            ],
            records: $records,
            topicRows: $computed['topic_rows']
        );

        $session = $this->sessionRepo->findSessionById($sessionId);
        if ($session === null) {
            throw new ApiException('خطا در بازیابی سشن آزمون ایجادشده', 500, 'INTERNAL_ERROR');
        }

        return $this->buildSessionResponse($session, true, $ensureRes);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(int $sessionId): array
    {
        $session = $this->sessionRepo->findSessionById($sessionId);
        if ($session === null) {
            throw new ApiException('سشن آزمون یافت نشد', 404, 'NOT_FOUND');
        }
        return $this->buildSessionResponse($session, false);
    }

    /**
     * Save admin's manual edits to suggested form rows (`is_manual = 1`).
     * Admin can freely change `subject_id`, add/remove `topic_ids` (including deeper detail nodes),
     * split or merge rows, or adjust `final_count`.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function updateSessionTopics(int $sessionId, array $rows): array
    {
        $session = $this->sessionRepo->findSessionById($sessionId);
        if ($session === null) {
            throw new ApiException('سشن آزمون یافت نشد', 404, 'NOT_FOUND');
        }

        // Collect all referenced curriculum node IDs for a single batch lookup
        $allNodeIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ApiException('ساختار ردیف‌های مبحث نامعتبر است', 422, 'VALIDATION_ERROR');
            }
            if (!empty($row['subject_id']) && is_numeric($row['subject_id'])) {
                $allNodeIds[] = (int) $row['subject_id'];
            }
            if (!empty($row['topic_ids']) && is_array($row['topic_ids'])) {
                foreach ($row['topic_ids'] as $tid) {
                    if (is_numeric($tid) && (int) $tid > 0) {
                        $allNodeIds[] = (int) $tid;
                    }
                }
            }
        }
        $nodesById = $this->curriculumRepo->findByIds($allNodeIds);

        $normalizedRows = [];
        foreach ($rows as $idx => $row) {
            $subjectId = isset($row['subject_id']) && $row['subject_id'] !== null && $row['subject_id'] !== ''
                ? (int) $row['subject_id']
                : null;
            if ($subjectId !== null && !isset($nodesById[$subjectId])) {
                throw new ApiException("شناسه درس ({$subjectId}) در درخت مباحث یافت نشد", 422, 'VALIDATION_ERROR');
            }

            $topicIds = [];
            $topicLabels = [];
            if (isset($row['topic_ids']) && is_array($row['topic_ids'])) {
                foreach ($row['topic_ids'] as $rawTid) {
                    $tid = (int) $rawTid;
                    if ($tid <= 0) {
                        continue;
                    }
                    if (!isset($nodesById[$tid])) {
                        throw new ApiException("شناسه مبحث ({$tid}) در درخت مباحث یافت نشد", 422, 'VALIDATION_ERROR');
                    }
                    if (!in_array($tid, $topicIds, true)) {
                        $topicIds[] = $tid;
                        $topicLabels[] = self::extractTopicDisplayLabel($nodesById[$tid]);
                    }
                }
            }

            // If subject_id omitted but topic_ids provided, infer subject_id from first topic's lesson_id
            if ($subjectId === null && $topicIds !== []) {
                $inferredLessonId = $nodesById[$topicIds[0]]['lesson_id'] ?? null;
                if ($inferredLessonId !== null) {
                    $subjectId = (int) $inferredLessonId;
                    if (!isset($nodesById[$subjectId])) {
                        $extra = $this->curriculumRepo->findByIds([$subjectId]);
                        if (isset($extra[$subjectId])) {
                            $nodesById[$subjectId] = $extra[$subjectId];
                        }
                    }
                }
            }

            $lesson = isset($row['lesson']) && is_string($row['lesson']) ? trim($row['lesson']) : '';
            if ($lesson === '' && $subjectId !== null && isset($nodesById[$subjectId])) {
                $lesson = CurriculumRepository::formatShortLessonLabel(
                    (string) $nodesById[$subjectId]['label'],
                    $nodesById[$subjectId]['grade_label'] ?? null
                );
            }
            if ($lesson === '') {
                $lesson = ExamTopicCounter::UNCLASSIFIED_LESSON;
            }

            $topicSummary = isset($row['topic']) && is_string($row['topic']) && trim($row['topic']) !== ''
                ? trim($row['topic'])
                : ($topicLabels !== [] ? implode(' | ', $topicLabels) : null);

            $finalCount = isset($row['final_count']) ? (int) $row['final_count'] : (int) ($row['auto_count'] ?? 0);
            if ($finalCount < 0) {
                throw new ApiException('تعداد سوال نمی‌تواند منفی باشد', 422, 'VALIDATION_ERROR');
            }

            $autoCount = isset($row['auto_count']) ? max(0, (int) $row['auto_count']) : $finalCount;
            $hasTopic = ($subjectId !== null || $topicIds !== [] || $lesson !== ExamTopicCounter::UNCLASSIFIED_LESSON);

            $normalizedRows[] = [
                'subject_id'    => $subjectId,
                'lesson'        => $lesson,
                'topic_ids'     => $topicIds,
                'topic_labels'  => $topicLabels,
                'topic'         => $topicSummary,
                'auto_count'    => $autoCount,
                'final_count'   => $finalCount,
                'is_manual'     => array_key_exists('is_manual', $row) ? (bool) $row['is_manual'] : true,
                'has_topic'     => $hasTopic,
                'display_order' => (int) $idx,
            ];
        }

        $this->sessionRepo->replaceSessionTopics($sessionId, $normalizedRows);
        return $this->getSession($sessionId);
    }

    /**
     * Recalculate auto topics (`is_manual = 0`) from the immutable `exam_session_questions` snapshot.
     * Admin-edited rows (`is_manual = 1`) are NEVER overwritten.
     *
     * @return array<string, mixed>
     */
    public function recalculateSessionTopics(int $sessionId): array
    {
        $session = $this->sessionRepo->findSessionById($sessionId);
        if ($session === null) {
            throw new ApiException('سشن آزمون یافت نشد', 404, 'NOT_FOUND');
        }

        $snapshotRecords = $this->sessionRepo->loadSessionQuestions($sessionId);
        $questionIds = array_values(array_unique(array_map(
            static fn (array $r): string => (string) $r['question_lernito_id'],
            $snapshotRecords
        )));

        $ensureRes = $this->syncService->ensureQuestions($questionIds, (string) $session['shelf_account_snapshot']);
        $partsMap = $this->lernitoRepo->loadQuestionPartsMap($questionIds);
        $computed = $this->computeMode1Suggestions($snapshotRecords, $partsMap, $ensureRes['error']);

        $this->sessionRepo->updateStatsAndReplaceAutoTopics(
            sessionId: $sessionId,
            stats: [
                'total_questions'         => $computed['total_questions'],
                'skipped_descriptive'     => $computed['skipped_descriptive'],
                'missing_question_count'  => $computed['missing_question_count'],
                'is_incomplete'           => $ensureRes['incomplete'] || $computed['missing_question_count'] > 0,
                'has_unclassified_topics' => $computed['has_unclassified_topics'],
                'warnings'                => $computed['warnings'],
            ],
            autoTopicRows: $computed['topic_rows']
        );

        $updatedSession = $this->sessionRepo->findSessionById($sessionId) ?? $session;
        return $this->buildSessionResponse($updatedSession, false, $ensureRes);
    }

    /**
     * Core Mode 1 suggestion builder:
     *   - Applies `subParts` on raw parts BEFORE filtering out `type = "description"` (Rules 1–4).
     *   - Resolves each counted test part against `curriculum_nodes` up to `subtopic` (`subject0`) depth (Option B).
     *   - Groups into 1 row per `(subject_id / lesson+grade)` with ordered unique `topic_ids[]` and summed `auto_count`.
     *
     * @param array<int, array{position: int, question_lernito_id: string, doc_type?: string, sub_parts: ?array<int, int>}> $records
     * @param array<string, array{is_multipart: bool, parts: array<int, array<string, mixed>>}> $partsMap
     * @return array{
     *   total_questions: int,
     *   skipped_descriptive: int,
     *   missing_question_count: int,
     *   has_unclassified_topics: bool,
     *   warnings: array<int, array<string, mixed>>,
     *   topic_rows: array<int, array<string, mixed>>
     * }
     */
    public function computeMode1Suggestions(array $records, array $partsMap, ?string $ensureError = null): array
    {
        $totalQuestions = 0;
        $skippedDescriptive = 0;
        $missingIds = [];
        $warnings = [];
        $hasUnclassified = false;
        $buckets = [];

        if ($ensureError !== null && trim($ensureError) !== '') {
            $warnings[] = [
                'code'    => 'ENSURE_QUESTIONS_PARTIAL',
                'message' => 'دریافت بخشی از سوالات از سرویس لرنیتو ناموفق بود؛ فرم با داده‌های موجود آماده شد.',
            ];
        }

        foreach ($records as $index => $record) {
            $qid = trim((string) ($record['question_lernito_id'] ?? ''));
            $position = isset($record['position']) ? (int) $record['position'] : (int) $index;
            $subParts = QuestionNormalizer::normalizeSubParts($record['sub_parts'] ?? null);

            if ($qid === '' || !isset($partsMap[$qid])) {
                if ($qid !== '' && !in_array($qid, $missingIds, true)) {
                    $missingIds[] = $qid;
                }
                continue;
            }

            $qMeta = $partsMap[$qid];
            $isMultipart = (bool) ($qMeta['is_multipart'] ?? false);
            $partsByIndex = [];
            foreach (($qMeta['parts'] ?? []) as $p) {
                if (is_array($p)) {
                    $partsByIndex[(int) ($p['part_index'] ?? 0)] = $p;
                }
            }
            ksort($partsByIndex);

            if ($partsByIndex === []) {
                if (!in_array($qid, $missingIds, true)) {
                    $missingIds[] = $qid;
                }
                continue;
            }

            // STEP 1: Apply subParts on RAW subQuestions first
            $selectedIndices = [];
            if ($isMultipart) {
                if ($subParts === null) {
                    $selectedIndices = array_keys($partsByIndex);
                    $warnings[] = [
                        'code'        => 'MULTIPART_WITHOUT_SUBPARTS',
                        'question_id' => $qid,
                        'position'    => $position,
                        'message'     => "سوال چندبخشی {$qid} بدون subParts ثبت شده است؛ تمام زیرسوال‌ها در نظر گرفته شدند.",
                    ];
                } else {
                    $seen = [];
                    foreach ($subParts as $rawIdx) {
                        if (isset($seen[$rawIdx])) {
                            continue;
                        }
                        $seen[$rawIdx] = true;
                        if (!array_key_exists($rawIdx, $partsByIndex)) {
                            $warnings[] = [
                                'code'        => 'SUBPART_OUT_OF_RANGE',
                                'question_id' => $qid,
                                'position'    => $position,
                                'message'     => "اندیس subParts ({$rawIdx}) برای سوال {$qid} خارج از محدوده است.",
                            ];
                            continue;
                        }
                        $selectedIndices[] = $rawIdx;
                    }
                }
            } else {
                $selectedIndices = array_key_exists(0, $partsByIndex)
                    ? [0]
                    : [array_key_first($partsByIndex)];
            }

            // STEP 2: Filter out descriptive parts, then resolve remaining test parts to curriculum_nodes
            foreach ($selectedIndices as $pIdx) {
                $part = $partsByIndex[$pIdx];
                $isDesc = ((int) ($part['is_descriptive'] ?? 0) === 1)
                    || QuestionNormalizer::isDescriptiveType((string) ($part['part_type'] ?? ''));

                if ($isDesc) {
                    $skippedDescriptive++;
                    continue;
                }

                $totalQuestions++;

                $partHasTopic = ((int) ($part['has_topic'] ?? 0) === 1);
                $rawLesson = isset($part['lesson']) && is_string($part['lesson']) ? trim($part['lesson']) : '';
                $rawChapter = isset($part['chapter']) && is_string($part['chapter']) ? trim($part['chapter']) : '';
                $rawGrade = isset($part['grade']) && is_string($part['grade']) ? trim($part['grade']) : '';

                if (!$partHasTopic || ($rawLesson === '' && $rawChapter === '')) {
                    $hasUnclassified = true;
                    $bucketKey = 'UNCLASSIFIED';
                    if (!isset($buckets[$bucketKey])) {
                        $buckets[$bucketKey] = [
                            'subject_id'   => null,
                            'lesson'       => ExamTopicCounter::UNCLASSIFIED_LESSON,
                            'topic_ids'    => [],
                            'topic_labels' => [],
                            'auto_count'   => 0,
                            'final_count'  => 0,
                            'is_manual'    => false,
                            'has_topic'    => false,
                        ];
                    }
                    $buckets[$bucketKey]['auto_count']++;
                    $buckets[$bucketKey]['final_count']++;
                    continue;
                }

                // Resolve against curriculum_nodes up to subtopic (subject0) depth
                $resolved = $this->curriculumRepo->resolveLernitoPartToNodes($part);
                $subjectId = $resolved['subject_id'];
                $lessonLabel = $resolved['subject_label']
                    ?? CurriculumRepository::formatShortLessonLabel(
                        $rawLesson !== '' ? $rawLesson : ExamTopicCounter::UNCLASSIFIED_LESSON,
                        $rawGrade !== '' ? $rawGrade : null
                    );

                $bucketKey = $subjectId !== null
                    ? ('SID:' . $subjectId)
                    : ('TXT:' . $lessonLabel);

                if (!isset($buckets[$bucketKey])) {
                    $buckets[$bucketKey] = [
                        'subject_id'   => $subjectId,
                        'lesson'       => $lessonLabel,
                        'topic_ids'    => [],
                        'topic_labels' => [],
                        'auto_count'   => 0,
                        'final_count'  => 0,
                        'is_manual'    => false,
                        'has_topic'    => true,
                    ];
                }

                $buckets[$bucketKey]['auto_count']++;
                $buckets[$bucketKey]['final_count']++;

                if ($resolved['suggested_topic_id'] !== null) {
                    $tid = (int) $resolved['suggested_topic_id'];
                    if (!in_array($tid, $buckets[$bucketKey]['topic_ids'], true)) {
                        $buckets[$bucketKey]['topic_ids'][] = $tid;
                        $buckets[$bucketKey]['topic_labels'][] = (string) ($resolved['suggested_topic_label'] ?? '');
                    }
                } elseif ($rawChapter !== '') {
                    // Fallback display label when question chapter text wasn't matched to a curriculum_nodes ID
                    $fallbackTopic = $rawChapter;
                    if (!empty($part['subject']) && is_string($part['subject'])) {
                        $fallbackTopic .= '، ' . trim($part['subject']);
                    }
                    if (!in_array($fallbackTopic, $buckets[$bucketKey]['topic_labels'], true)) {
                        $buckets[$bucketKey]['topic_labels'][] = $fallbackTopic;
                    }
                }
            }
        }

        // Put classified lessons first, unclassified at the end
        $classified = [];
        $unclassified = [];
        foreach ($buckets as $b) {
            $b['topic'] = $b['topic_labels'] !== [] ? implode(' | ', $b['topic_labels']) : null;
            if (!$b['has_topic']) {
                $unclassified[] = $b;
            } else {
                $classified[] = $b;
            }
        }
        $rows = array_merge($classified, $unclassified);
        foreach ($rows as $idx => &$row) {
            $row['display_order'] = $idx;
        }
        unset($row);

        return [
            'total_questions'         => $totalQuestions,
            'skipped_descriptive'     => $skippedDescriptive,
            'missing_question_count'  => count($missingIds),
            'has_unclassified_topics' => $hasUnclassified,
            'warnings'                => $warnings,
            'topic_rows'              => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed>|null $ensureMeta
     * @return array<string, mixed>
     */
    private function buildSessionResponse(array $session, bool $created, ?array $ensureMeta = null): array
    {
        $sessionId = (int) $session['id'];
        $topics = $this->sessionRepo->loadSessionTopics($sessionId);
        $savedResults = $this->sessionRepo->loadSavedResultsForSession($sessionId);

        $payload = [
            'created'       => $created,
            'session'       => $session,
            'topics'        => $topics,
            'saved_results' => $savedResults,
        ];
        if ($ensureMeta !== null) {
            $payload['ensure_questions'] = $ensureMeta;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function extractTopicDisplayLabel(array $node): string
    {
        $full = isset($node['full_path']) && is_string($node['full_path']) ? trim($node['full_path']) : '';
        $label = trim((string) ($node['label'] ?? ''));
        if ($full !== '' && str_contains($full, '، ')) {
            // Strip leading "درس - پایه، " prefix so chapter/subtopic chip stays concise
            $pos = mb_strpos($full, '، ', 0, 'UTF-8');
            if ($pos !== false) {
                return trim(mb_substr($full, $pos + 2, null, 'UTF-8'));
            }
        }
        return $label !== '' ? $label : $full;
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }
}
