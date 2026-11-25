<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Reconciles `sent` and `unknown` sms_log rows that have a provider message id
 * against the active SmsProvider's delivery status endpoint.
 *
 * Rows in `unknown` WITHOUT a provider_msg_id are never auto-retried; they can
 * be inspected via listUnknownWithoutMessageId() for manual operator review.
 */
final class Reconciler
{
    private const MAX_BATCH_SIZE = 50;
    private const VALID_STATUSES = ['sent', 'delivered', 'failed', 'unknown'];

    /**
     * Poll provider statuses for eligible rows and apply transitions to sms_log.
     *
     * @return array{
     *     scanned: int,
     *     updated: int,
     *     delivered: int,
     *     failed: int,
     *     still_sent: int,
     *     unknown: int
     * }
     */
    public static function reconcile(int $limit = 100, ?SmsProvider $provider = null, ?SmsConfig $config = null): array
    {
        $cfg = $config ?? SmsConfig::fromEnv();
        $prov = $provider ?? Sms::provider();

        $rows = SmsLog::rowsForReconcile($cfg->reconcileMinAgeSeconds, $cfg->reconcileMaxAgeDays, max(1, $limit));
        $stats = [
            'scanned'    => count($rows),
            'updated'    => 0,
            'delivered'  => 0,
            'failed'     => 0,
            'still_sent' => 0,
            'unknown'    => 0,
        ];

        if ($rows === []) {
            return $stats;
        }

        foreach (array_chunk($rows, self::MAX_BATCH_SIZE) as $chunk) {
            $byMsgId = [];
            $messageIds = [];
            foreach ($chunk as $row) {
                $msgId = isset($row['provider_msg_id']) ? trim((string) $row['provider_msg_id']) : '';
                if ($msgId === '') {
                    continue;
                }
                $byMsgId[$msgId][] = $row;
                $messageIds[$msgId] = $msgId;
            }

            if ($messageIds === []) {
                continue;
            }

            $statuses = $prov->fetchStatuses(array_values($messageIds));

            foreach ($byMsgId as $msgId => $matchingRows) {
                $reported = $statuses[$msgId] ?? null;
                if (!$reported instanceof SmsMessageStatus) {
                    $stats['unknown'] += count($matchingRows);
                    continue;
                }

                $newStatus = in_array($reported->status, self::VALID_STATUSES, true)
                    ? $reported->status
                    : 'unknown';

                foreach ($matchingRows as $row) {
                    $rowId = (int) $row['id'];
                    $currentStatus = (string) ($row['status'] ?? '');

                    if ($newStatus === 'delivered') {
                        $stats['delivered']++;
                    } elseif ($newStatus === 'failed') {
                        $stats['failed']++;
                    } elseif ($newStatus === 'sent') {
                        $stats['still_sent']++;
                    } else {
                        $stats['unknown']++;
                    }

                    $statusChanged = $newStatus !== $currentStatus;
                    $hasNewMetadata = ($reported->cost !== null && $row['provider_cost'] === null)
                        || ($reported->providerStatusCode !== null && (string) $reported->providerStatusCode !== (string) ($row['provider_status_code'] ?? ''))
                        || ($reported->providerStatusText !== null && $reported->providerStatusText !== ($row['provider_status_text'] ?? null));

                    if ($statusChanged || $hasNewMetadata) {
                        SmsLog::applyReconciledStatus(
                            $rowId,
                            $newStatus,
                            $reported->cost,
                            $reported->segments,
                            $reported->providerStatusCode,
                            $reported->providerStatusText
                        );
                        $stats['updated']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * List `unknown` rows that have no provider_msg_id (manual review only).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listUnknownWithoutMessageId(int $limit = 100): array
    {
        return SmsLog::unknownWithoutMessageId(max(1, $limit));
    }
}
