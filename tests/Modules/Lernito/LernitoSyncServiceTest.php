<?php

declare(strict_types=1);

namespace Tests\Modules\Lernito;

use App\Core\ApiException;
use App\Core\RedisClient;
use App\Modules\Lernito\LernitoClientInterface;
use App\Modules\Lernito\LernitoConfig;
use App\Modules\Lernito\LernitoController;
use App\Modules\Lernito\LernitoRepository;
use App\Modules\Lernito\LernitoSyncJobLauncherInterface;
use App\Modules\Lernito\LernitoSyncService;
use InvalidArgumentException;
use RuntimeException;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Integration tests for Lernito Phase 1:
 *   - LERNITO_SEND_ACCOUNT_PARAM=false single-account safety guard
 *   - records_hash shelf diffing (ignoring unreliable dateModified)
 *   - Safe shelf deactivation (only on non-empty successful fetch)
 *   - Missing question cooldown & max-attempts + manual retry
 *   - Non-blocking ensureQuestions()
 *   - Monthly 60-day version refresh rebuilding lernito_question_parts
 *   - Async POST /api/v1/admin/lernito/sync (202 Accepted, 409 Conflict) & GET /sync/status
 */
final class LernitoSyncServiceTest extends TestCase
{
    private LernitoRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applyMigration017();
        $this->cleanupTestRows();

        RedisClient::reset();
        RedisClient::useInMemoryDriver(true);
        RedisClient::setTestNow(1_764_250_000);

        $this->repo = new LernitoRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRows();
        RedisClient::reset();
        parent::tearDown();
    }

    public function testAccountSafetyGuardWhenSendAccountParamIsFalse(): void
    {
        $cfg = $this->makeConfig(sendAccountParam: false);

        // Omitting account MUST fail
        try {
            $cfg->resolveTargetAccounts(null);
            $this->fail('Expected InvalidArgumentException when account is omitted with sendAccountParam=false');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('LERNITO_SEND_ACCOUNT_PARAM=false', $e->getMessage());
        }

        // Passing "all" MUST fail
        try {
            $cfg->resolveTargetAccounts('all');
            $this->fail('Expected InvalidArgumentException for "all" with sendAccountParam=false');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('LERNITO_SEND_ACCOUNT_PARAM=false', $e->getMessage());
        }

        // Passing two accounts MUST fail
        try {
            $cfg->resolveTargetAccounts('motevasete1,motevasete2');
            $this->fail('Expected InvalidArgumentException for two accounts with sendAccountParam=false');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('بیش از یک اکانت', $e->getMessage());
        }

        // Passing exactly one valid account succeeds
        $this->assertSame(['motevasete1'], $cfg->resolveTargetAccounts('motevasete1'));
        $this->assertSame(['motevasete2'], $cfg->resolveTargetAccounts('motevasete2'));

        // When sendAccountParam=true, omitting account resolves all configured accounts
        $cfgMulti = $this->makeConfig(sendAccountParam: true);
        $this->assertSame(['motevasete1', 'motevasete2'], $cfgMulti->resolveTargetAccounts(null));
    }

    public function testSyncRecordsHashDeactivationGuardAndMissingCooldown(): void
    {
        $fakeClient = new FakeLernitoClient();
        $fakeClient->shelvesByAccount['motevasete1'] = [
            [
                '_id'          => 'ltest_shelf_0001',
                'name'         => 'Synthetic Shelf 1',
                'dateCreated'  => '2026-10-01T10:00:00.000Z',
                'dateModified' => '2026-10-01T10:00:00.000Z',
                'records'      => [
                    ['_id' => 'ltest_q_00000001', 'docType' => 'Question'],
                    ['_id' => 'ltest_q_missing1', 'docType' => 'Question'],
                ],
            ],
            [
                '_id'          => 'ltest_shelf_0002',
                'name'         => 'Synthetic Shelf 2',
                'dateCreated'  => '2026-10-01T10:00:00.000Z',
                'dateModified' => '2026-10-01T10:00:00.000Z',
                'records'      => [
                    ['_id' => 'ltest_q_00000001', 'docType' => 'Question'],
                ],
            ],
        ];
        $fakeClient->questionsById['ltest_q_00000001'] = [
            '_id'      => 'ltest_q_00000001',
            'type'     => 'multipleChoice',
            'count'    => 1,
            'version'  => 1,
            'question' => '<p>Test Q1</p>',
            'choices'  => ['A', 'B', 'C', 'D'],
            'topics'   => [[
                'lesson'      => 'زیست‌شناسی',
                'chapter'     => 'فصل 1',
                'subject0'    => 'گفتار 1',
                'isMainTopic' => true,
            ]],
        ];

        $service = $this->makeService($fakeClient, sendAccountParam: false);

        // Run 1: inserts 2 shelves, 1 question, records 1 missing question
        $res1 = $service->runSync('motevasete1');
        $this->assertSame('succeeded', $res1['status']);
        $this->assertSame(2, $res1['accounts']['motevasete1']['shelves_new']);
        $this->assertSame(1, $res1['accounts']['motevasete1']['questions_fetched']);
        $this->assertSame(1, $res1['accounts']['motevasete1']['questions_missing_failed']);

        // Run 2 immediately: missing question MUST be skipped by 24h cooldown (0 queued!)
        // Even with identical dateModified, changing records MUST trigger records_changed via records_hash
        $fakeClient->shelvesByAccount['motevasete1'] = [
            [
                '_id'          => 'ltest_shelf_0001',
                'name'         => 'Synthetic Shelf 1',
                'dateCreated'  => '2026-10-01T10:00:00.000Z',
                'dateModified' => '2026-10-01T10:00:00.000Z', // unchanged dateModified!
                'records'      => [
                    ['_id' => 'ltest_q_00000001', 'docType' => 'Question'],
                    ['_id' => 'ltest_q_missing1', 'docType' => 'Question'],
                    ['_id' => 'ltest_q_00000002', 'docType' => 'Question'],
                ],
            ],
            // ltest_shelf_0002 removed -> should be deactivated (NOT deleted)
        ];
        $fakeClient->questionsById['ltest_q_00000002'] = [
            '_id'      => 'ltest_q_00000002',
            'type'     => 'multipleChoice',
            'count'    => 1,
            'version'  => 1,
            'question' => '<p>Test Q2</p>',
            'choices'  => ['A', 'B'],
            'topics'   => [],
        ];

        $res2 = $service->runSync('motevasete1');
        $this->assertSame(1, $res2['accounts']['motevasete1']['shelves_changed']);
        $this->assertSame(1, $res2['accounts']['motevasete1']['shelves_deactivated']);
        // Only ltest_q_00000002 queued; ltest_q_missing1 is in 24h cooldown
        $this->assertSame(1, $res2['accounts']['motevasete1']['questions_missing_queued']);
        $this->assertSame(1, $res2['accounts']['motevasete1']['questions_fetched']);

        $shelf2 = $this->fetchOne('lernito_shelves', ['lernito_id' => 'ltest_shelf_0002']);
        $this->assertNotNull($shelf2, 'Removed shelf must NOT be physically deleted');
        $this->assertSame(0, (int) $shelf2['is_active']);
        $this->assertNotNull($shelf2['missing_since']);

        // Run 3: empty shelf list or exception MUST NOT deactivate ltest_shelf_0001!
        $fakeClient->shelvesByAccount['motevasete1'] = [];
        $res3 = $service->runSync('motevasete1');
        $this->assertSame(0, $res3['accounts']['motevasete1']['shelves_deactivated']);
        $shelf1 = $this->fetchOne('lernito_shelves', ['lernito_id' => 'ltest_shelf_0001']);
        $this->assertSame(1, (int) $shelf1['is_active']);

        // Run 4: upstream error MUST NOT deactivate ltest_shelf_0001
        $fakeClient->failShelvesFor['motevasete1'] = 'Upstream 502 timeout';
        $res4 = $service->runSync('motevasete1');
        $this->assertSame('completed_with_errors', $res4['status']);
        $shelf1AfterErr = $this->fetchOne('lernito_shelves', ['lernito_id' => 'ltest_shelf_0001']);
        $this->assertSame(1, (int) $shelf1AfterErr['is_active']);
    }

    public function testEnsureQuestionsNeverThrowsOnUpstreamFailure(): void
    {
        $fakeClient = new FakeLernitoClient();
        $fakeClient->failQuestions = 'Connection timed out after 15000ms';

        $service = $this->makeService($fakeClient, sendAccountParam: false);
        $result = $service->ensureQuestions(['ltest_q_uncached1', 'ltest_q_uncached2'], 'motevasete1');

        $this->assertTrue($result['incomplete']);
        $this->assertSame(2, $result['requested_uncached']);
        $this->assertSame(0, $result['fetched']);
        $this->assertSame(['ltest_q_uncached1', 'ltest_q_uncached2'], $result['missing_ids']);
        $this->assertStringContainsString('timed out', (string) $result['error']);
    }

    public function testMonthlyVersionRefreshRebuildsPartsWhenVersionChanges(): void
    {
        $fakeClient = new FakeLernitoClient();
        $fakeClient->shelvesByAccount['motevasete1'] = [
            [
                '_id'     => 'ltest_shelf_ver1',
                'name'    => 'Version Refresh Shelf',
                'records' => [
                    ['_id' => 'ltest_q_ver_0001', 'docType' => 'Question'],
                ],
            ],
        ];
        $fakeClient->questionsById['ltest_q_ver_0001'] = [
            '_id'      => 'ltest_q_ver_0001',
            'type'     => 'multipleChoice',
            'count'    => 1,
            'version'  => 2,
            'question' => '<p>Old text</p>',
            'choices'  => ['A', 'B'],
            'topics'   => [['lesson' => 'شیمی', 'chapter' => 'فصل قدیم', 'isMainTopic' => true]],
        ];

        $service = $this->makeService($fakeClient, sendAccountParam: false);
        $service->runSync('motevasete1');

        // Upstream increments version 2 -> 3 and changes chapter
        $fakeClient->questionsById['ltest_q_ver_0001']['version'] = 3;
        $fakeClient->questionsById['ltest_q_ver_0001']['topics'] = [
            ['lesson' => 'شیمی', 'chapter' => 'فصل جدید', 'isMainTopic' => true],
        ];

        $refresh = $service->runMonthlyVersionRefresh('motevasete1', ['days' => 60]);
        $this->assertSame('succeeded', $refresh['status']);
        $this->assertSame(1, $refresh['accounts']['motevasete1']['updated']);

        $qRow = $this->fetchOne('lernito_questions', ['lernito_id' => 'ltest_q_ver_0001']);
        $this->assertSame(3, (int) $qRow['lernito_version']);
        $this->assertSame('فصل جدید', $qRow['primary_chapter']);

        $partRow = $this->fetchOne('lernito_question_parts', ['question_id' => (int) $qRow['id'], 'part_index' => 0]);
        $this->assertSame('فصل جدید', $partRow['chapter']);
    }

    public function testAsyncAdminTriggerReturns202AndConflict409WhenLocked(): void
    {
        $fakeClient = new FakeLernitoClient();
        $fakeLauncher = new FakeLernitoLauncher();
        $service = $this->makeService($fakeClient, sendAccountParam: false, launcher: $fakeLauncher);
        $controller = new LernitoController($service);

        // Missing account under sendAccountParam=false -> 422
        $reqInvalid = $this->createRequest('POST', '/api/v1/admin/lernito/sync', []);
        $resInvalid = $controller->triggerSync($reqInvalid, new Response());
        $this->assertSame(422, $resInvalid->getStatusCode());

        // Valid single account -> 202 Accepted and spawns background job
        $reqOk = $this->createRequest('POST', '/api/v1/admin/lernito/sync', ['account' => 'motevasete1']);
        $resOk = $controller->triggerSync($reqOk, new Response());
        $this->assertSame(202, $resOk->getStatusCode());
        $okBody = json_decode((string) $resOk->getBody(), true);
        $this->assertTrue($okBody['success']);
        $this->assertSame('queued', $okBody['data']['status']);
        $this->assertSame(['motevasete1'], $okBody['data']['accounts']);
        $this->assertCount(1, $fakeLauncher->launches);

        // Second concurrent trigger while lock is held -> 409 Conflict
        $resConflict = $controller->triggerSync($reqOk, new Response());
        $this->assertSame(409, $resConflict->getStatusCode());
        $conflictBody = json_decode((string) $resConflict->getBody(), true);
        $this->assertSame('LERNITO_SYNC_IN_PROGRESS', $conflictBody['error']['code']);

        // GET /sync/status shows running=true and queued job details
        $reqStatus = $this->createRequest('GET', '/api/v1/admin/lernito/sync/status');
        $resStatus = $controller->syncStatus($reqStatus, new Response());
        $this->assertSame(200, $resStatus->getStatusCode());
        $statusBody = json_decode((string) $resStatus->getBody(), true);
        $this->assertTrue($statusBody['data']['is_running']);
        $this->assertSame($okBody['data']['job_id'], $statusBody['data']['job']['job_id']);
    }

    private function makeConfig(bool $sendAccountParam): LernitoConfig
    {
        return new LernitoConfig(
            serviceUrl: 'http://127.0.0.1:3001',
            internalKey: 'test-internal-key-secret',
            accounts: ['motevasete1', 'motevasete2'],
            sendAccountParam: $sendAccountParam,
            batchSize: 100,
            syncTimeoutSeconds: 45,
            ensureTimeoutSeconds: 15,
            batchSleepMs: 0,
            missingCooldownHours: 24,
            missingMaxAttempts: 5,
            syncLockTtlSeconds: 1800,
            refreshDays: 60,
            phpBinary: 'php'
        );
    }

    private function makeService(
        LernitoClientInterface $client,
        bool $sendAccountParam,
        ?LernitoSyncJobLauncherInterface $launcher = null
    ): LernitoSyncService {
        return new LernitoSyncService(
            config: $this->makeConfig($sendAccountParam),
            client: $client,
            repository: $this->repo,
            launcher: $launcher ?? new FakeLernitoLauncher(),
            sleeper: static function (int $ms): void {
            }
        );
    }

    private function applyMigration017(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/017_question_bank.sql');
        if (!is_string($sql) || trim($sql) === '') {
            return;
        }
        foreach (explode(';', $sql) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '' || str_starts_with($trimmed, '--') && !str_contains($trimmed, 'CREATE TABLE')) {
                continue;
            }
            $this->db->exec($trimmed);
        }
    }

    private function cleanupTestRows(): void
    {
        try {
            $this->db->exec("DELETE FROM lernito_shelves WHERE lernito_id LIKE 'ltest_%'");
            $this->db->exec("DELETE FROM lernito_questions WHERE lernito_id LIKE 'ltest_%'");
            $this->db->exec("DELETE FROM lernito_missing_questions WHERE lernito_id LIKE 'ltest_%'");
        } catch (\Throwable) {
            // Tables might not exist yet on first call
        }
    }
}

final class FakeLernitoClient implements LernitoClientInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $shelvesByAccount = [];

    /** @var array<string, string> */
    public array $failShelvesFor = [];

    /** @var array<string, array<string, mixed>> */
    public array $questionsById = [];

    public ?string $failQuestions = null;

    public function fetchShelves(string $account, bool $force = true, ?int $timeoutSeconds = null): array
    {
        if (isset($this->failShelvesFor[$account])) {
            throw new RuntimeException($this->failShelvesFor[$account]);
        }
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
        if ($this->failQuestions !== null) {
            throw new RuntimeException($this->failQuestions);
        }
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

final class FakeLernitoLauncher implements LernitoSyncJobLauncherInterface
{
    /** @var array<int, array{accounts: array<int, string>, options: array<string, mixed>}> */
    public array $launches = [];

    public function launch(array $accounts, array $options): void
    {
        $this->launches[] = [
            'accounts' => $accounts,
            'options'  => $options,
        ];
    }
}
