<?php

declare(strict_types=1);

namespace Tests\Modules\Exams;

use App\Modules\Curriculum\CurriculumRepository;
use App\Modules\Curriculum\PersianTextNormalizer;
use App\Modules\Exams\ExamResult;
use App\Modules\Exams\ExamService;
use App\Modules\Exams\ExamSessionRepository;
use App\Modules\Exams\ExamSessionService;
use App\Modules\Lernito\LernitoClientInterface;
use App\Modules\Lernito\LernitoConfig;
use App\Modules\Lernito\LernitoRepository;
use App\Modules\Lernito\LernitoSyncJobLauncherInterface;
use App\Modules\Lernito\LernitoSyncService;
use Tests\TestCase;

/**
 * Integration tests for Phase 2:
 *   - curriculum_nodes enrichment (متوسطه 1 vs متوسطه 2 depth semantics + NBSP normalization)
 *   - Option B subtopic cutoff (stops at subject0 / گفتار, never descends to subject1 / detail)
 *   - Smart autocomplete with student grade/field priority boost
 *   - ExamSessionService::createOrGetSession Mode 1 grouping (1 row per lesson + topic_ids array)
 *   - Admin manual edits preserved during recalculateSessionTopics()
 *   - Multiple exams for the same student on the same date scoped by exam_session_id
 *   - exam_results.subject_id, topic_ids_json, and exam_result_topics synchronization
 */
final class ExamSessionAndCurriculumTest extends TestCase
{
    private CurriculumRepository $curriculumRepo;
    private ExamSessionRepository $sessionRepo;
    private LernitoRepository $lernitoRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applySqlMigration('017_question_bank.sql');
        $this->applySqlMigration('018_exam_sessions.sql');
        $this->cleanupTestRows();

        $this->curriculumRepo = new CurriculumRepository($this->db);
        $this->sessionRepo = new ExamSessionRepository($this->db);
        $this->lernitoRepo = new LernitoRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRows();
        parent::tearDown();
    }

    public function testCurriculumEnrichmentDistinguishesMotevasete1And2AndStopsAtSubtopic(): void
    {
        $this->seedSampleCurriculumTree();

        $summary = $this->curriculumRepo->enrichAllNodes();
        $this->assertGreaterThanOrEqual(13, $summary['updated']);

        $nodes = $this->curriculumRepo->findByIds([900001, 900002, 900005, 900012, 901598, 901601, 909183, 909200, 909201, 909202, 909203]);

        // متوسطه 1 (no group level): level 2 = grade, level 3 = lesson, level 4 = chapter
        $this->assertSame('segment', $nodes[900001]['node_type']);
        $this->assertSame('grade', $nodes[900002]['node_type']);
        $this->assertSame('lesson', $nodes[900005]['node_type']);
        $this->assertSame('هفتم', $nodes[900005]['grade_label']);
        $this->assertSame('chapter', $nodes[900012]['node_type']);
        $this->assertSame(900005, $nodes[900012]['lesson_id']);

        // متوسطه 2 (has group level): level 2 = group, level 3 = grade, level 4 = lesson, level 5 = chapter, level 6 = subtopic, level 7 = detail
        $this->assertSame('segment', $nodes[901598]['node_type']);
        $this->assertSame('group', $nodes[901601]['node_type']);
        $this->assertSame('grade', $nodes[909183]['node_type']);
        $this->assertSame('lesson', $nodes[909200]['node_type']);
        $this->assertSame('دهم', $nodes[909200]['grade_label']);
        $this->assertSame('علوم تجربی', $nodes[909200]['group_label']);
        $this->assertSame('chapter', $nodes[909201]['node_type']);
        $this->assertSame('subtopic', $nodes[909202]['node_type']);
        $this->assertSame('detail', $nodes[909203]['node_type']);

        // Option B cutoff: Lernito part with subject0 (گفتار 1) and subject1 (detail) MUST stop at subtopic (909202), NOT detail (909203)
        $resolved = $this->curriculumRepo->resolveLernitoPartToNodes([
            'segment'    => 'متوسطه 2',
            'group_name' => 'علوم تجربی',
            'grade'      => 'دهم',
            'lesson'     => 'زیست‌شناسی',
            'chapter'    => "فصل\u{00A0}1: دنیای زنده", // NBSP handled transparently
            'subject'    => 'گفتار 1: زیست شناسی چیست؟',
            'subject1'   => 'گستره حیات',
        ]);

        $this->assertSame(909200, $resolved['subject_id']);
        $this->assertSame(909201, $resolved['chapter_node_id']);
        $this->assertSame(909202, $resolved['subtopic_node_id']);
        $this->assertSame(909202, $resolved['suggested_topic_id']);
        $this->assertNotSame(909203, $resolved['suggested_topic_id']);

        // Autocomplete finds detail node when explicitly typed by admin
        $detailHits = $this->curriculumRepo->autocomplete([
            'q'          => 'گستره حیات',
            'subject_id' => 909200,
            'limit'      => 10,
        ]);
        $this->assertNotEmpty($detailHits);
        $this->assertSame(909203, $detailHits[0]['id']);
        $this->assertSame('detail', $detailHits[0]['node_type']);
    }

    public function testExamSessionMode1GroupingManualEditPreservationAndMultiExamSameDay(): void
    {
        $this->seedSampleCurriculumTree();
        $this->curriculumRepo->enrichAllNodes();

        $studentId = $this->createTestStudent('دانش‌آموز تست فاز ۲', 10, 'تجربی');

        // Seed 2 shelves for same student on same exam_date
        $fakeClient = new Phase2FakeLernitoClient();
        $fakeClient->shelvesByAccount['motevasete2'] = [
            [
                '_id'     => 'p2test_shelf_01',
                'name'    => 'آزمون هفتگی زیست دهم',
                'records' => [
                    ['_id' => 'p2test_q_01', 'docType' => 'Question'],
                    ['_id' => 'p2test_q_02', 'docType' => 'Question'],
                    ['_id' => 'p2test_q_desc', 'docType' => 'Question'],
                ],
            ],
            [
                '_id'     => 'p2test_shelf_02',
                'name'    => 'آزمون دوم همان روز',
                'records' => [
                    ['_id' => 'p2test_q_01', 'docType' => 'Question'],
                ],
            ],
        ];
        $fakeClient->questionsById['p2test_q_01'] = [
            '_id'      => 'p2test_q_01',
            'type'     => 'multipleChoice',
            'count'    => 1,
            'version'  => 1,
            'question' => '<p>Q1</p>',
            'choices'  => ['A', 'B', 'C', 'D'],
            'topics'   => [[
                'segment'     => 'متوسطه 2',
                'group'       => 'علوم تجربی',
                'grade'       => 'دهم',
                'lesson'      => 'زیست شناسی',
                'chapter'     => 'فصل 1: دنیای زنده',
                'subject0'    => 'گفتار 1: زیست شناسی چیست؟',
                'isMainTopic' => true,
            ]],
        ];
        $fakeClient->questionsById['p2test_q_02'] = [
            '_id'      => 'p2test_q_02',
            'type'     => 'multipleChoice',
            'count'    => 1,
            'version'  => 1,
            'question' => '<p>Q2</p>',
            'choices'  => ['A', 'B', 'C', 'D'],
            'topics'   => [[
                'segment'     => 'متوسطه 2',
                'group'       => 'علوم تجربی',
                'grade'       => 'دهم',
                'lesson'      => 'زیست شناسی',
                'chapter'     => 'فصل 2: گوارش و جذب مواد',
                'isMainTopic' => true,
            ]],
        ];
        $fakeClient->questionsById['p2test_q_desc'] = [
            '_id'      => 'p2test_q_desc',
            'type'     => 'descriptive',
            'count'    => 1,
            'version'  => 1,
            'question' => '<p>Descriptive</p>',
            'topics'   => [],
        ];

        $syncService = new LernitoSyncService(
            config: new LernitoConfig(
                serviceUrl: 'http://127.0.0.1:3001',
                internalKey: 'test-key',
                accounts: ['motevasete2'],
                sendAccountParam: false,
                batchSize: 100,
                syncTimeoutSeconds: 45,
                ensureTimeoutSeconds: 15,
                batchSleepMs: 0,
                missingCooldownHours: 24,
                missingMaxAttempts: 5,
                syncLockTtlSeconds: 1800,
                refreshDays: 60,
                phpBinary: 'php'
            ),
            client: $fakeClient,
            repository: $this->lernitoRepo,
            launcher: new Phase2FakeLauncher(),
            sleeper: static function (int $ms): void {
            }
        );

        $syncService->runSync('motevasete2');

        $shelf1 = $this->fetchOne('lernito_shelves', ['lernito_id' => 'p2test_shelf_01']);
        $shelf2 = $this->fetchOne('lernito_shelves', ['lernito_id' => 'p2test_shelf_02']);
        $this->assertNotNull($shelf1);
        $this->assertNotNull($shelf2);

        $sessionService = new ExamSessionService(
            $this->sessionRepo,
            $this->lernitoRepo,
            $this->curriculumRepo,
            $syncService
        );

        // Create session 1 on 2026-11-20
        $created1 = $sessionService->createOrGetSession($studentId, (int) $shelf1['id'], '2026-11-20', null);
        $this->assertTrue($created1['created']);
        $session1Id = (int) $created1['session']['id'];

        // Mode 1: 1 row for زیست شناسی - دهم combining both topics (909202 subtopic + 909204 chapter), count = 2 (descriptive skipped!)
        $this->assertSame(2, $created1['session']['total_questions']);
        $this->assertSame(1, $created1['session']['skipped_descriptive']);
        $this->assertCount(1, $created1['topics']);
        $this->assertSame(909200, $created1['topics'][0]['subject_id']);
        $this->assertSame(2, $created1['topics'][0]['auto_count']);
        $this->assertSame([909202, 909204], $created1['topics'][0]['topic_ids']);

        // Admin manually edits final_count to 5 (is_manual = true)
        $edited = $sessionService->updateSessionTopics($session1Id, [[
            'subject_id'  => 909200,
            'topic_ids'   => [909202],
            'auto_count'  => 2,
            'final_count' => 5,
            'is_manual'   => true,
        ]]);
        $this->assertCount(1, $edited['topics']);
        $this->assertTrue($edited['topics'][0]['is_manual']);
        $this->assertSame(5, $edited['topics'][0]['final_count']);

        // Recalculate MUST preserve manual row (final_count stays 5, not overwritten back to 2)
        $recalc = $sessionService->recalculateSessionTopics($session1Id);
        $this->assertCount(1, $recalc['topics']);
        $this->assertTrue($recalc['topics'][0]['is_manual']);
        $this->assertSame(5, $recalc['topics'][0]['final_count']);

        // Create session 2 on the SAME date for the SAME student
        $created2 = $sessionService->createOrGetSession($studentId, (int) $shelf2['id'], '2026-11-20', null);
        $this->assertTrue($created2['created']);
        $session2Id = (int) $created2['session']['id'];
        $this->assertNotSame($session1Id, $session2Id);

        // Save results for BOTH sessions on the same date via ExamService::save()
        $examService = new ExamService(null, null, $this->curriculumRepo, $this->sessionRepo);

        $save1 = $examService->save($studentId, '2026-11-20', [[
            'subject_id' => 909200,
            'topic_ids'  => [909202, 909204],
            'total_q'    => 10,
            'correct'    => 8,
            'wrong'      => 1,
            'skipped'    => 1,
        ]], $session1Id);
        $this->assertSame(1, $save1['inserted']);

        $save2 = $examService->save($studentId, '2026-11-20', [[
            'subject_id' => 909200,
            'topic_ids'  => [909202],
            'total_q'    => 5,
            'correct'    => 4,
            'wrong'      => 1,
            'skipped'    => 0,
        ]], $session2Id);
        $this->assertSame(1, $save2['inserted']);

        // Both sessions coexist without clobbering each other
        $allDetails = $examService->getDetails('2026-11-20', $studentId);
        $this->assertCount(2, $allDetails['subjects']);
        $this->assertCount(2, $allDetails['exam_sessions']);

        $onlySession1 = $examService->getDetails('2026-11-20', $studentId, $session1Id);
        $this->assertCount(1, $onlySession1['subjects']);
        $this->assertSame(909200, $onlySession1['subjects'][0]['subject_id']);
        $this->assertSame([909202, 909204], $onlySession1['subjects'][0]['topic_ids']);
        $this->assertStringContainsString('زیست شناسی', $onlySession1['subjects'][0]['subject']);

        // Verify normalized exam_result_topics join table is in sync
        $result1Id = (int) $onlySession1['subjects'][0]['id'];
        $joinStmt = $this->db->prepare('SELECT topic_id FROM exam_result_topics WHERE exam_result_id = ? ORDER BY position ASC');
        $joinStmt->execute([$result1Id]);
        $joinedTopicIds = array_map('intval', $joinStmt->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame([909202, 909204], $joinedTopicIds);

        // Verify PersianTextNormalizer handles NBSP/ZWNJ/Arabic chars
        $this->assertSame('زیست شناسی', PersianTextNormalizer::normalize("زيست\u{200C}شناسي"));
    }

    private function seedSampleCurriculumTree(): void
    {
        $nodes = [
            // متوسطه 1 (no group level)
            [900001, null, 'متوسطه 1', 1, 0],
            [900002, 900001, 'هفتم', 2, 0],
            [900005, 900002, 'ریاضی', 3, 0],
            [900012, 900005, 'فصل 1: راهبردهای حل مسئله', 4, 1],
            // متوسطه 2 (has group level)
            [901598, null, 'متوسطه 2', 1, 1],
            [901601, 901598, 'علوم تجربی', 2, 0],
            [909183, 901601, 'دهم', 3, 0],
            [909200, 909183, 'زیست شناسی', 4, 0],
            [909201, 909200, 'فصل 1: دنیای زنده', 5, 0],
            [909202, 909201, 'گفتار 1: زیست شناسی چیست؟', 6, 0],
            [909203, 909202, 'گستره حیات', 7, 0],
            [909204, 909200, 'فصل 2: گوارش و جذب مواد', 5, 1],
            [909205, 909204, 'گفتار 1: یاخته و بافت جانوری', 6, 0],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO curriculum_nodes (id, parent_id, label, level, sort_order)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), level = VALUES(level), sort_order = VALUES(sort_order)'
        );
        foreach ($nodes as $n) {
            $stmt->execute($n);
        }
    }

    private function createTestStudent(string $name, int $grade, string $field): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO students (name, grade, field) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $grade, $field]);
        return (int) $this->db->lastInsertId();
    }

    private function applySqlMigration(string $filename): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/' . $filename);
        if (!is_string($sql) || trim($sql) === '') {
            return;
        }
        foreach (explode(';', $sql) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }
            $lines = array_filter(
                explode("\n", $trimmed),
                static fn (string $l): bool => !str_starts_with(trim($l), '--')
            );
            $clean = trim(implode("\n", $lines));
            if ($clean === '') {
                continue;
            }
            try {
                $this->db->exec($clean);
            } catch (\PDOException $e) {
                // Ignore duplicate column/key/constraint errors when re-running migration in test DB
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (!in_array($code, [1060, 1061, 1022, 1826], true)) {
                    throw $e;
                }
            }
        }
    }

    private function cleanupTestRows(): void
    {
        try {
            $this->db->exec("DELETE FROM exam_results WHERE student_id IN (SELECT id FROM students WHERE name = 'دانش‌آموز تست فاز ۲')");
            $this->db->exec("DELETE FROM exam_sessions WHERE student_id IN (SELECT id FROM students WHERE name = 'دانش‌آموز تست فاز ۲')");
            $this->db->exec("DELETE FROM student_shelves WHERE student_id IN (SELECT id FROM students WHERE name = 'دانش‌آموز تست فاز ۲')");
            $this->db->exec("DELETE FROM students WHERE name = 'دانش‌آموز تست فاز ۲'");
            $this->db->exec("DELETE FROM lernito_shelves WHERE lernito_id LIKE 'p2test_%'");
            $this->db->exec("DELETE FROM lernito_questions WHERE lernito_id LIKE 'p2test_%'");
            $this->db->exec('DELETE FROM curriculum_nodes WHERE id >= 900000');
        } catch (\Throwable) {
            // Tables might not exist yet on first run
        }
    }
}

final class Phase2FakeLernitoClient implements LernitoClientInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $shelvesByAccount = [];

    /** @var array<string, array<string, mixed>> */
    public array $questionsById = [];

    public function fetchShelves(string $account, bool $force = true, ?int $timeoutSeconds = null): array
    {
        $shelves = $this->shelvesByAccount[$account] ?? [];
        return [
            'shelves'   => $shelves,
            'count'     => count($shelves),
            'cachedAt'  => gmdate('c'),
            'fromCache' => false,
        ];
    }

    public function fetchQuestions(array $ids, string $account, ?int $timeoutSeconds = null): array
    {
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            if (isset($this->questionsById[$id])) {
                $found[] = $this->questionsById[$id];
            } else {
                $missing[] = $id;
            }
        }
        return [
            'questions'      => $found,
            'requestedCount' => count($ids),
            'fetchedCount'   => count($found),
            'missingIds'     => $missing,
        ];
    }
}

final class Phase2FakeLauncher implements LernitoSyncJobLauncherInterface
{
    public function launch(array $accounts, array $options): void
    {
    }
}
