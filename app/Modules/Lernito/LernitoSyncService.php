<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use App\Core\ApiException;
use App\Core\RedisClient;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestrates Lernito sync (daily cron + async admin manual trigger),
 * on-demand `ensureQuestions()` with short timeout, and monthly 60-day version refresh.
 */
final class LernitoSyncService
{
    public const LOCK_KEY = 'lock:lernito_sync';
    public const STATUS_KEY = 'lernito:sync:status';
    public const STATUS_TTL_SECONDS = 604800; // 7 days

    private LernitoConfig $config;
    private LernitoClientInterface $client;
    private LernitoRepository $repository;
    private LernitoSyncJobLauncherInterface $launcher;
    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param callable(int): void|null $sleeper Custom sleep(ms) callback for tests
     */
    public function __construct(
        ?LernitoConfig $config = null,
        ?LernitoClientInterface $client = null,
        ?LernitoRepository $repository = null,
        ?LernitoSyncJobLauncherInterface $launcher = null,
        ?callable $sleeper = null
    ) {
        $this->config = $config ?? LernitoConfig::fromEnv();
        $this->client = $client ?? new LernitoClient($this->config);
        $this->repository = $repository ?? new LernitoRepository();
        $this->launcher = $launcher ?? new LernitoSyncJobLauncher($this->config);
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    public function getConfig(): LernitoConfig
    {
        return $this->config;
    }

    public function getRepository(): LernitoRepository
    {
        return $this->repository;
    }

    /**
     * Non-blocking trigger for POST /api/v1/admin/lernito/sync:
     *   1. Validates target account(s) (enforcing LERNITO_SEND_ACCOUNT_PARAM=false safety rule).
     *   2. Acquires Redis lock atomically (409 if already active; 503 if Redis unavailable).
     *   3. Writes `queued` job status to Redis.
     *   4. Spawns background CLI worker (`scripts/lernito_sync.php`) adopting the lock token.
     *
     * @param string|array<int, string>|null $requestedAccount
     * @param array{
     *   mode?: string,
     *   force_links?: bool,
     *   retry_missing?: bool,
     *   max_questions?: ?int,
     *   triggered_by?: string
     * } $options
     * @return array<string, mixed>
     */
    public function enqueueAsyncSync(string|array|null $requestedAccount, array $options = []): array
    {
        try {
            $accounts = $this->config->resolveTargetAccounts($requestedAccount);
        } catch (InvalidArgumentException $e) {
            throw new ApiException($e->getMessage(), 422, 'VALIDATION_ERROR');
        }

        $mode = strtolower(trim((string) ($options['mode'] ?? 'sync')));
        if (!in_array($mode, ['sync', 'refresh_versions'], true)) {
            throw new ApiException('مقدار mode باید sync یا refresh_versions باشد.', 422, 'VALIDATION_ERROR');
        }

        $maxQuestions = null;
        if (array_key_exists('max_questions', $options) && $options['max_questions'] !== null) {
            $mq = (int) $options['max_questions'];
            if ($mq < 1 || $mq > 20000) {
                throw new ApiException('مقدار max_questions باید بین ۱ تا ۲۰۰۰۰ باشد.', 422, 'VALIDATION_ERROR');
            }
            $maxQuestions = $mq;
        }

        if (!RedisClient::isAvailable()) {
            throw new ApiException(
                'سرویس Redis در دسترس نیست؛ برای جلوگیری از همپوشانی همگام‌سازی، قفل Redis الزامی است.',
                503,
                'REDIS_UNAVAILABLE'
            );
        }

        $jobId = 'lsync_' . bin2hex(random_bytes(8));
        $lockToken = $jobId;
        $acquired = RedisClient::acquireLock(self::LOCK_KEY, $this->config->syncLockTtlSeconds, $lockToken);
        if (!$acquired) {
            throw new ApiException(
                'یک فرآیند همگام‌سازی لرنیتو هم‌اکنون در حال اجرا است.',
                409,
                'LERNITO_SYNC_IN_PROGRESS'
            );
        }

        $now = gmdate('c');
        $triggeredBy = trim((string) ($options['triggered_by'] ?? 'admin'));
        $forceLinks = !empty($options['force_links']);
        $retryMissing = !empty($options['retry_missing']);

        $initialStatus = $this->buildInitialStatus(
            jobId: $jobId,
            mode: $mode,
            accounts: $accounts,
            triggeredBy: $triggeredBy,
            status: 'queued',
            startedAt: $now
        );
        $this->saveJobStatus($initialStatus);

        try {
            $this->launcher->launch($accounts, [
                'job_id'        => $jobId,
                'lock_token'    => $lockToken,
                'mode'          => $mode,
                'force_links'   => $forceLinks,
                'retry_missing' => $retryMissing,
                'max_questions' => $maxQuestions,
                'triggered_by'  => $triggeredBy,
            ]);
        } catch (Throwable $e) {
            $initialStatus['status'] = 'failed';
            $initialStatus['finished_at'] = gmdate('c');
            $initialStatus['updated_at'] = gmdate('c');
            $initialStatus['progress']['phase'] = 'failed';
            $initialStatus['last_error'] = $this->redactMessage($e->getMessage());
            $this->saveJobStatus($initialStatus);
            RedisClient::releaseLock(self::LOCK_KEY, $lockToken);

            throw new ApiException(
                'شروع فرآیند پس‌زمینه همگام‌سازی لرنیتو ناموفق بود: ' . $initialStatus['last_error'],
                500,
                'LERNITO_JOB_LAUNCH_FAILED'
            );
        }

        return [
            'job_id'     => $jobId,
            'status'     => 'queued',
            'mode'       => $mode,
            'accounts'   => $accounts,
            'status_url' => '/api/v1/admin/lernito/sync/status',
            'started_at' => $now,
        ];
    }

    /**
     * Read current sync lock state, latest job progress/last_error, and DB summary counts.
     *
     * @return array<string, mixed>
     */
    public function getSyncStatus(): array
    {
        $redisAvailable = RedisClient::isAvailable();
        $isLocked = $redisAvailable ? RedisClient::isLocked(self::LOCK_KEY) : false;
        $lockTtl = $isLocked ? max(0, RedisClient::ttl(self::LOCK_KEY)) : 0;

        $job = null;
        if ($redisAvailable) {
            $raw = RedisClient::get(self::STATUS_KEY);
            if ($raw !== null && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $job = $decoded;
                }
            }
        }

        return [
            'is_running'       => $isLocked,
            'lock_ttl_seconds' => $lockTtl,
            'send_account_param' => $this->config->sendAccountParam,
            'configured_accounts' => $this->config->accounts,
            'job'              => $job,
            'summary'          => $this->repository->getSyncSummaryStats($this->config->missingMaxAttempts),
        ];
    }

    /**
     * Execute full incremental sync for one or more accounts (CLI / background worker).
     *
     * @param string|array<int, string>|null $requestedAccount
     * @param array{
     *   force_links?: bool,
     *   retry_missing?: bool,
     *   max_questions?: int,
     *   job_id?: ?string,
     *   lock_token?: ?string,
     *   triggered_by?: string
     * } $options
     * @return array<string, mixed>
     */
    public function runSync(string|array|null $requestedAccount, array $options = []): array
    {
        $accounts = $this->config->resolveTargetAccounts($requestedAccount);
        $forceLinks = !empty($options['force_links']);
        $retryMissing = !empty($options['retry_missing']);
        $maxQuestions = isset($options['max_questions']) ? max(1, (int) $options['max_questions']) : 500;
        $triggeredBy = trim((string) ($options['triggered_by'] ?? 'cli'));

        [$jobId, $lockToken, $ownsLock] = $this->acquireOrAdoptLock(
            $options['job_id'] ?? null,
            $options['lock_token'] ?? null
        );

        $status = $this->buildInitialStatus(
            jobId: $jobId,
            mode: 'sync',
            accounts: $accounts,
            triggeredBy: $triggeredBy,
            status: 'running',
            startedAt: gmdate('c')
        );
        $status['progress']['phase'] = 'syncing_shelves';
        $this->saveJobStatus($status);

        $accountReports = [];
        $errors = [];

        try {
            foreach ($accounts as $account) {
                $status['progress']['current_account'] = $account;
                $status['progress']['phase'] = 'syncing_shelves';
                $status['updated_at'] = gmdate('c');
                $this->saveJobStatus($status);

                $accReport = [
                    'account'                  => $account,
                    'shelves_fetched'          => 0,
                    'shelves_new'              => 0,
                    'shelves_changed'          => 0,
                    'shelves_deactivated'      => 0,
                    'questions_missing_queued' => 0,
                    'questions_fetched'        => 0,
                    'questions_missing_failed' => 0,
                    'error'                    => null,
                ];

                // Step 1: Fetch shelves for this account
                try {
                    $shelfRes = $this->client->fetchShelves($account, true, $this->config->syncTimeoutSeconds);
                    $shelves = $shelfRes['shelves'];
                    $accReport['shelves_fetched'] = count($shelves);
                    $status['progress']['shelves_fetched'] += count($shelves);

                    $seenShelfIds = [];
                    foreach ($shelves as $rawShelf) {
                        if (!is_array($rawShelf) || empty($rawShelf['_id'])) {
                            continue;
                        }
                        $normalized = QuestionNormalizer::normalizeShelf($rawShelf, $account);
                        $res = $this->repository->upsertShelfWithRecords(
                            $normalized['shelf'],
                            $normalized['records'],
                            $forceLinks
                        );
                        $seenShelfIds[] = $normalized['shelf']['lernito_id'];
                        if ($res['is_new']) {
                            $accReport['shelves_new']++;
                        }
                        if ($res['records_changed']) {
                            $accReport['shelves_changed']++;
                            $status['progress']['shelves_changed']++;
                        }
                    }

                    // Safety guard: ONLY deactivate missing shelves on a successful, non-empty response
                    if ($seenShelfIds !== []) {
                        $deactivated = $this->repository->deactivateMissingShelves($account, $seenShelfIds);
                        $accReport['shelves_deactivated'] = $deactivated;
                        $status['progress']['shelves_deactivated'] += $deactivated;
                    }
                } catch (Throwable $e) {
                    // Never deactivate shelves when fetching fails!
                    $msg = $this->redactMessage($e->getMessage());
                    $accReport['error'] = $msg;
                    $errors[] = "[{$account}] {$msg}";
                    $status['last_error'] = "[{$account}] {$msg}";
                    $accountReports[$account] = $accReport;
                    $status['progress']['accounts_done']++;
                    $this->saveJobStatus($status);
                    continue;
                }

                // Step 2: Fetch missing questions for this account in batches of <= 100
                $status['progress']['phase'] = 'fetching_questions';
                $missingIds = $this->repository->findMissingQuestionIdsForAccount(
                    account: $account,
                    limit: $maxQuestions,
                    cooldownHours: $this->config->missingCooldownHours,
                    maxAttempts: $this->config->missingMaxAttempts,
                    includeExhausted: $retryMissing
                );
                $accReport['questions_missing_queued'] = count($missingIds);
                $status['progress']['questions_total_missing'] += count($missingIds);
                $this->saveJobStatus($status);

                $chunks = array_chunk($missingIds, $this->config->batchSize);
                foreach ($chunks as $chunkIdx => $chunk) {
                    $this->refreshLockIfOwned($lockToken, $ownsLock);

                    try {
                        $qRes = $this->client->fetchQuestions($chunk, $account, $this->config->syncTimeoutSeconds);

                        $returnedIds = [];
                        foreach ($qRes['questions'] as $rawDoc) {
                            if (!is_array($rawDoc) || empty($rawDoc['_id'])) {
                                continue;
                            }
                            $normQ = QuestionNormalizer::normalizeQuestion($rawDoc, $account);
                            $this->repository->upsertQuestionWithParts($normQ['question'], $normQ['parts']);
                            $returnedIds[$normQ['question']['lernito_id']] = true;
                            $accReport['questions_fetched']++;
                            $status['progress']['questions_fetched']++;
                        }

                        // Combine explicit missingIds + any requested ID silently omitted by upstream
                        $notReturned = $qRes['missingIds'];
                        foreach ($chunk as $reqId) {
                            if (!isset($returnedIds[$reqId]) && !in_array($reqId, $notReturned, true)) {
                                $notReturned[] = $reqId;
                            }
                        }

                        if ($notReturned !== []) {
                            $this->repository->recordMissingQuestions($notReturned, $account, 'MISSING_IN_LERNITO');
                            $accReport['questions_missing_failed'] += count($notReturned);
                            $status['progress']['questions_missing_failed'] += count($notReturned);
                        }
                    } catch (Throwable $e) {
                        $msg = $this->redactMessage($e->getMessage());
                        $this->repository->recordMissingQuestions($chunk, $account, $msg);
                        $accReport['questions_missing_failed'] += count($chunk);
                        $status['progress']['questions_missing_failed'] += count($chunk);
                        $accReport['error'] = $msg;
                        $errors[] = "[{$account} batch {$chunkIdx}] {$msg}";
                        $status['last_error'] = "[{$account}] {$msg}";
                    }

                    $status['updated_at'] = gmdate('c');
                    $this->saveJobStatus($status);

                    if ($chunkIdx < count($chunks) - 1 && $this->config->batchSleepMs > 0) {
                        ($this->sleeper)($this->config->batchSleepMs);
                    }
                }

                $accountReports[$account] = $accReport;
                $status['progress']['accounts_done']++;
                $status['updated_at'] = gmdate('c');
                $this->saveJobStatus($status);
            }

            $status['status'] = $errors === [] ? 'succeeded' : 'completed_with_errors';
            $status['progress']['phase'] = 'done';
            $status['progress']['current_account'] = null;
            $status['finished_at'] = gmdate('c');
            $status['updated_at'] = gmdate('c');
            $this->saveJobStatus($status);

            return [
                'job_id'   => $jobId,
                'status'   => $status['status'],
                'accounts' => $accountReports,
                'progress' => $status['progress'],
                'errors'   => $errors,
            ];
        } catch (Throwable $e) {
            $msg = $this->redactMessage($e->getMessage());
            $status['status'] = 'failed';
            $status['progress']['phase'] = 'failed';
            $status['last_error'] = $msg;
            $status['finished_at'] = gmdate('c');
            $status['updated_at'] = gmdate('c');
            $this->saveJobStatus($status);
            throw $e;
        } finally {
            if ($ownsLock) {
                RedisClient::releaseLock(self::LOCK_KEY, $lockToken);
            }
        }
    }

    /**
     * Monthly version refresh job:
     * Re-fetches questions referenced by active shelves from the last 60 days (Phase 1;
     * will switch to exam_session_questions after Migration 018). Whenever `version` changed,
     * updates `lernito_questions` and transactionally rebuilds `lernito_question_parts`.
     *
     * @param string|array<int, string>|null $requestedAccount
     * @param array{
     *   days?: int,
     *   max_questions?: int,
     *   job_id?: ?string,
     *   lock_token?: ?string,
     *   triggered_by?: string
     * } $options
     * @return array<string, mixed>
     */
    public function runMonthlyVersionRefresh(string|array|null $requestedAccount, array $options = []): array
    {
        $accounts = $this->config->resolveTargetAccounts($requestedAccount);
        $days = isset($options['days']) ? max(1, (int) $options['days']) : $this->config->refreshDays;
        $maxQuestions = isset($options['max_questions']) ? max(1, (int) $options['max_questions']) : 2000;
        $triggeredBy = trim((string) ($options['triggered_by'] ?? 'cli'));

        [$jobId, $lockToken, $ownsLock] = $this->acquireOrAdoptLock(
            $options['job_id'] ?? null,
            $options['lock_token'] ?? null
        );

        $status = $this->buildInitialStatus(
            jobId: $jobId,
            mode: 'refresh_versions',
            accounts: $accounts,
            triggeredBy: $triggeredBy,
            status: 'running',
            startedAt: gmdate('c')
        );
        $status['progress']['phase'] = 'refreshing_versions';
        $this->saveJobStatus($status);

        $accountReports = [];
        $errors = [];

        try {
            foreach ($accounts as $account) {
                $status['progress']['current_account'] = $account;
                $this->saveJobStatus($status);

                $candidates = $this->repository->findQuestionsForVersionRefresh($account, $days, $maxQuestions);
                $ids = array_keys($candidates);

                $accReport = [
                    'account'     => $account,
                    'checked'     => count($ids),
                    'updated'     => 0,
                    'unchanged'   => 0,
                    'missing'     => 0,
                    'error'       => null,
                ];

                $chunks = array_chunk($ids, $this->config->batchSize);
                foreach ($chunks as $chunkIdx => $chunk) {
                    $this->refreshLockIfOwned($lockToken, $ownsLock);

                    try {
                        $qRes = $this->client->fetchQuestions($chunk, $account, $this->config->syncTimeoutSeconds);
                        $unchangedIds = [];

                        foreach ($qRes['questions'] as $rawDoc) {
                            if (!is_array($rawDoc) || empty($rawDoc['_id'])) {
                                continue;
                            }
                            $lid = (string) $rawDoc['_id'];
                            $remoteVersion = isset($rawDoc['version']) && is_numeric($rawDoc['version'])
                                ? (int) $rawDoc['version']
                                : null;
                            $cachedVersion = $candidates[$lid] ?? null;

                            if ($remoteVersion !== $cachedVersion) {
                                $normQ = QuestionNormalizer::normalizeQuestion($rawDoc, $account);
                                $this->repository->upsertQuestionWithParts($normQ['question'], $normQ['parts']);
                                $accReport['updated']++;
                                $status['progress']['questions_version_updated']++;
                            } else {
                                $unchangedIds[] = $lid;
                                $accReport['unchanged']++;
                            }
                        }

                        if ($unchangedIds !== []) {
                            $this->repository->touchQuestionSyncTimestamps($unchangedIds);
                        }

                        if ($qRes['missingIds'] !== []) {
                            $accReport['missing'] += count($qRes['missingIds']);
                        }
                    } catch (Throwable $e) {
                        $msg = $this->redactMessage($e->getMessage());
                        $accReport['error'] = $msg;
                        $errors[] = "[{$account} refresh batch {$chunkIdx}] {$msg}";
                        $status['last_error'] = "[{$account}] {$msg}";
                    }

                    $status['updated_at'] = gmdate('c');
                    $this->saveJobStatus($status);

                    if ($chunkIdx < count($chunks) - 1 && $this->config->batchSleepMs > 0) {
                        ($this->sleeper)($this->config->batchSleepMs);
                    }
                }

                $accountReports[$account] = $accReport;
                $status['progress']['accounts_done']++;
                $this->saveJobStatus($status);
            }

            $status['status'] = $errors === [] ? 'succeeded' : 'completed_with_errors';
            $status['progress']['phase'] = 'done';
            $status['progress']['current_account'] = null;
            $status['finished_at'] = gmdate('c');
            $status['updated_at'] = gmdate('c');
            $this->saveJobStatus($status);

            return [
                'job_id'   => $jobId,
                'status'   => $status['status'],
                'accounts' => $accountReports,
                'progress' => $status['progress'],
                'errors'   => $errors,
            ];
        } catch (Throwable $e) {
            $msg = $this->redactMessage($e->getMessage());
            $status['status'] = 'failed';
            $status['progress']['phase'] = 'failed';
            $status['last_error'] = $msg;
            $status['finished_at'] = gmdate('c');
            $status['updated_at'] = gmdate('c');
            $this->saveJobStatus($status);
            throw $e;
        } finally {
            if ($ownsLock) {
                RedisClient::releaseLock(self::LOCK_KEY, $lockToken);
            }
        }
    }

    /**
     * On-demand helper (for exam session creation):
     * Fetches any uncached shelf question IDs from Node with a short timeout (~15s).
     * Never throws on upstream failure — returns `incomplete = true` so the exam form is NEVER blocked.
     *
     * @param array<int, string> $lernitoIds
     * @return array{
     *   incomplete: bool,
     *   requested_uncached: int,
     *   fetched: int,
     *   missing_ids: array<int, string>,
     *   error: ?string
     * }
     */
    public function ensureQuestions(array $lernitoIds, string $account): array
    {
        $validatedAccount = $this->config->assertValidAccount($account);
        $uncached = $this->repository->findUncachedQuestionIds($lernitoIds);

        if ($uncached === []) {
            return [
                'incomplete'         => false,
                'requested_uncached' => 0,
                'fetched'            => 0,
                'missing_ids'        => [],
                'error'              => null,
            ];
        }

        $fetched = 0;
        $stillMissing = [];
        $error = null;

        $chunks = array_chunk($uncached, $this->config->batchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $res = $this->client->fetchQuestions($chunk, $validatedAccount, $this->config->ensureTimeoutSeconds);
                $returned = [];
                foreach ($res['questions'] as $rawDoc) {
                    if (!is_array($rawDoc) || empty($rawDoc['_id'])) {
                        continue;
                    }
                    $norm = QuestionNormalizer::normalizeQuestion($rawDoc, $validatedAccount);
                    $this->repository->upsertQuestionWithParts($norm['question'], $norm['parts']);
                    $returned[$norm['question']['lernito_id']] = true;
                    $fetched++;
                }

                $chunkMissing = $res['missingIds'];
                foreach ($chunk as $reqId) {
                    if (!isset($returned[$reqId]) && !in_array($reqId, $chunkMissing, true)) {
                        $chunkMissing[] = $reqId;
                    }
                }
                if ($chunkMissing !== []) {
                    $this->repository->recordMissingQuestions($chunkMissing, $validatedAccount, 'MISSING_ON_ENSURE');
                    foreach ($chunkMissing as $m) {
                        if (!in_array($m, $stillMissing, true)) {
                            $stillMissing[] = $m;
                        }
                    }
                }
            } catch (Throwable $e) {
                $error = $this->redactMessage($e->getMessage());
                // Mark remaining chunks as missing in this result without locking the form
                for ($i = $chunkIndex; $i < count($chunks); $i++) {
                    foreach ($chunks[$i] as $m) {
                        if (!in_array($m, $stillMissing, true)) {
                            $stillMissing[] = $m;
                        }
                    }
                }
                break;
            }
        }

        return [
            'incomplete'         => ($stillMissing !== [] || $error !== null),
            'requested_uncached' => count($uncached),
            'fetched'            => $fetched,
            'missing_ids'        => $stillMissing,
            'error'              => $error,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: bool} [jobId, lockToken, ownsLock]
     */
    private function acquireOrAdoptLock(?string $providedJobId, ?string $providedLockToken): array
    {
        $jobId = ($providedJobId !== null && trim($providedJobId) !== '')
            ? trim($providedJobId)
            : ('lsync_' . bin2hex(random_bytes(8)));

        if (!RedisClient::isAvailable()) {
            // In CLI cron fallback if Redis is down we refuse silent concurrent overlap if a lockToken was expected
            if ($providedLockToken !== null && trim($providedLockToken) !== '') {
                throw new RuntimeException('سرویس Redis در دسترس نیست؛ اجرای همگام‌سازی متوقف شد.');
            }
            return [$jobId, $jobId, false];
        }

        if ($providedLockToken !== null && trim($providedLockToken) !== '') {
            $token = trim($providedLockToken);
            $currentHolder = RedisClient::get(self::LOCK_KEY);
            if ($currentHolder === $token) {
                RedisClient::refreshLock(self::LOCK_KEY, $this->config->syncLockTtlSeconds, $token);
                return [$jobId, $token, true];
            }
            // Lock expired before worker booted; attempt to re-acquire with same token
            if (RedisClient::acquireLock(self::LOCK_KEY, $this->config->syncLockTtlSeconds, $token)) {
                return [$jobId, $token, true];
            }
            throw new RuntimeException('قفل همگام‌سازی لرنیتو در اختیار فرآیند دیگری است.');
        }

        $token = $jobId;
        if (!RedisClient::acquireLock(self::LOCK_KEY, $this->config->syncLockTtlSeconds, $token)) {
            throw new RuntimeException('یک فرآیند همگام‌سازی لرنیتو هم‌اکنون در حال اجرا است.');
        }

        return [$jobId, $token, true];
    }

    private function refreshLockIfOwned(string $lockToken, bool $ownsLock): void
    {
        if ($ownsLock && RedisClient::isAvailable()) {
            RedisClient::refreshLock(self::LOCK_KEY, $this->config->syncLockTtlSeconds, $lockToken);
        }
    }

    /**
     * @param array<int, string> $accounts
     * @return array<string, mixed>
     */
    private function buildInitialStatus(
        string $jobId,
        string $mode,
        array $accounts,
        string $triggeredBy,
        string $status,
        string $startedAt
    ): array {
        return [
            'job_id'       => $jobId,
            'status'       => $status,
            'mode'         => $mode,
            'accounts'     => $accounts,
            'triggered_by' => $triggeredBy,
            'started_at'   => $startedAt,
            'updated_at'   => $startedAt,
            'finished_at'  => null,
            'progress'     => [
                'phase'                     => $status,
                'current_account'           => null,
                'accounts_total'            => count($accounts),
                'accounts_done'             => 0,
                'shelves_fetched'           => 0,
                'shelves_changed'           => 0,
                'shelves_deactivated'       => 0,
                'questions_total_missing'   => 0,
                'questions_fetched'         => 0,
                'questions_missing_failed'  => 0,
                'questions_version_updated' => 0,
            ],
            'last_error'   => null,
        ];
    }

    /**
     * @param array<string, mixed> $status
     */
    private function saveJobStatus(array $status): void
    {
        if (!RedisClient::isAvailable()) {
            return;
        }
        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            RedisClient::setex(self::STATUS_KEY, self::STATUS_TTL_SECONDS, $encoded);
        }
    }

    private function redactMessage(string $message): string
    {
        $clean = trim($message);
        if ($this->config->internalKey !== '') {
            $clean = str_replace($this->config->internalKey, '[REDACTED]', $clean);
        }
        return mb_substr($clean, 0, 500, 'UTF-8');
    }
}
