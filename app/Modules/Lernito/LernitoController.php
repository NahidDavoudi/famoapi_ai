<?php

declare(strict_types=1);

namespace App\Modules\Lernito;

use App\Core\ApiException;
use App\Core\ResponseHelper;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin-only endpoints for triggering asynchronous Lernito sync, inspecting progress,
 * and manually resetting exhausted missing-question entries.
 */
final class LernitoController
{
    private ?LernitoSyncService $service;

    public function __construct(?LernitoSyncService $service = null)
    {
        $this->service = $service;
    }

    /**
     * POST /api/v1/admin/lernito/sync
     * Launches background CLI sync and returns 202 Accepted immediately.
     * Returns 409 Conflict when the Redis sync lock is already active.
     */
    public function triggerSync(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $user = $request->getAttribute('user');
        $actorId = isset($user->sub) ? (string) $user->sub : (isset($user->id) ? (string) $user->id : 'unknown');

        $requestedAccount = $body['account'] ?? $body['accounts'] ?? null;
        if ($requestedAccount !== null && !is_string($requestedAccount) && !is_array($requestedAccount)) {
            return ResponseHelper::error(
                $response,
                'VALIDATION_ERROR',
                'فیلد account باید رشته یا آرایه‌ای از نام اکانت‌های معتبر باشد.',
                422
            );
        }

        $options = [
            'mode'          => isset($body['mode']) && is_string($body['mode']) ? $body['mode'] : 'sync',
            'force_links'   => !empty($body['force_links']),
            'retry_missing' => !empty($body['retry_missing']),
            'triggered_by'  => 'admin:' . $actorId,
        ];

        if (array_key_exists('max_questions', $body) && $body['max_questions'] !== null && $body['max_questions'] !== '') {
            if (!is_numeric($body['max_questions'])) {
                return ResponseHelper::error(
                    $response,
                    'VALIDATION_ERROR',
                    'مقدار max_questions باید عدد صحیح باشد.',
                    422
                );
            }
            $options['max_questions'] = (int) $body['max_questions'];
        }

        try {
            $result = $this->syncService()->enqueueAsyncSync($requestedAccount, $options);
            return ResponseHelper::json($response, $result, null, 202);
        } catch (ApiException $e) {
            return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
        } catch (InvalidArgumentException $e) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    /**
     * GET /api/v1/admin/lernito/sync/status
     * Returns current lock status, active/last job progress, last_error, and DB stats.
     */
    public function syncStatus(Request $request, Response $response): Response
    {
        $status = $this->syncService()->getSyncStatus();
        return ResponseHelper::json($response, $status, null, 200);
    }

    /**
     * POST /api/v1/admin/lernito/missing/retry
     * Resets cooldown/attempts on exhausted lernito_missing_questions rows for an account,
     * and optionally enqueues an asynchronous sync (`trigger_sync: true`).
     */
    public function retryMissing(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $user = $request->getAttribute('user');
        $actorId = isset($user->sub) ? (string) $user->sub : (isset($user->id) ? (string) $user->id : 'unknown');

        $requestedAccount = $body['account'] ?? null;
        if (!is_string($requestedAccount) || trim($requestedAccount) === '') {
            return ResponseHelper::error(
                $response,
                'VALIDATION_ERROR',
                'فیلد account الزامی است.',
                422
            );
        }

        $service = $this->syncService();
        try {
            $accounts = $service->getConfig()->resolveTargetAccounts($requestedAccount);
            if (count($accounts) !== 1) {
                return ResponseHelper::error(
                    $response,
                    'VALIDATION_ERROR',
                    'برای بازنشانی سوالات غایب، دقیقاً یک اکانت مشخص کنید.',
                    422
                );
            }
            $account = $accounts[0];
        } catch (InvalidArgumentException $e) {
            return ResponseHelper::error($response, 'VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $ids = null;
        if (array_key_exists('lernito_ids', $body) && $body['lernito_ids'] !== null) {
            if (!is_array($body['lernito_ids'])) {
                return ResponseHelper::error(
                    $response,
                    'VALIDATION_ERROR',
                    'فیلد lernito_ids باید آرایه باشد.',
                    422
                );
            }
            if (count($body['lernito_ids']) > 100) {
                return ResponseHelper::error(
                    $response,
                    'VALIDATION_ERROR',
                    'حداکثر ۱۰۰ شناسه در هر درخواست مجاز است.',
                    422
                );
            }
            $ids = [];
            foreach ($body['lernito_ids'] as $rawId) {
                $s = trim((string) $rawId);
                if ($s === '' || preg_match(LernitoClient::QUESTION_ID_PATTERN, $s) !== 1) {
                    return ResponseHelper::error(
                        $response,
                        'VALIDATION_ERROR',
                        "شناسه سوال نامعتبر در lernito_ids: {$s}",
                        422
                    );
                }
                $ids[] = $s;
            }
        }

        $resetCount = $service->getRepository()->resetMissingQuestionsForRetry($account, $ids);
        $triggerSync = !empty($body['trigger_sync']);

        if ($triggerSync) {
            try {
                $job = $service->enqueueAsyncSync($account, [
                    'mode'          => 'sync',
                    'retry_missing' => true,
                    'triggered_by'  => 'admin:' . $actorId,
                ]);
                return ResponseHelper::json($response, [
                    'account'     => $account,
                    'reset_count' => $resetCount,
                    'sync_job'    => $job,
                ], null, 202);
            } catch (ApiException $e) {
                return ResponseHelper::error($response, $e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
            }
        }

        return ResponseHelper::json($response, [
            'account'     => $account,
            'reset_count' => $resetCount,
            'sync_job'    => null,
        ], null, 200);
    }

    private function syncService(): LernitoSyncService
    {
        if ($this->service !== null) {
            return $this->service;
        }
        return new LernitoSyncService();
    }
}
