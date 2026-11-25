<?php

declare(strict_types=1);

namespace App\Modules\Sms;

use App\Core\Database;

/**
 * Data access for the sms_log table (migration 015).
 *
 * The unique key (client, purpose, receptor, ref) is the dedup/idempotency
 * mechanism: SmsLog::insertPending() returns 0 when a row with the same key
 * already exists, and the caller (Sms::send) then resolves the existing row
 * instead of sending again.
 */
final class SmsLog
{
    /**
     * @param array{client:string, purpose:string, receptor:string, ref:string, text:string, segments:int, provider:string} $data
     * @return int new row id, or 0 when a row with the same dedup key already exists
     */
    public static function insertPending(array $data): int
    {
        $db = Database::getConnection();

        try {
            $stmt = $db->prepare(
                'INSERT INTO sms_log
                    (client, purpose, receptor, ref, text, segments, provider, status, created_at, updated_at)
                 VALUES
                    (:client, :purpose, :receptor, :ref, :text, :segments, :provider, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'client'   => $data['client'],
                'purpose'  => $data['purpose'],
                'receptor' => $data['receptor'],
                'ref'      => $data['ref'],
                'text'     => $data['text'],
                'segments' => $data['segments'],
                'provider' => $data['provider'],
                'status'   => 'pending',
            ]);

            return (int) $db->lastInsertId();
        } catch (\PDOException $e) {
            // MySQL duplicate key (23000) / PostgreSQL unique violation (23505).
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return 0;
            }
            throw $e;
        }
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM sms_log WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByDedupKey(string $client, string $purpose, string $receptor, string $ref): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM sms_log
             WHERE client = :client AND purpose = :purpose AND receptor = :receptor AND ref = :ref
             LIMIT 1'
        );
        $stmt->execute([
            'client'   => $client,
            'purpose'  => $purpose,
            'receptor' => $receptor,
            'ref'      => $ref,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function markSent(int $id, string $messageId, ?float $cost, ?int $statusCode, ?string $statusText): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE sms_log
             SET status = 'sent', provider_msg_id = :msg_id, provider_cost = :cost,
                 provider_status_code = :code, provider_status_text = :status_text,
                 sent_at = CURRENT_TIMESTAMP, error = NULL
             WHERE id = :id AND status IN ('pending', 'failed')"
        );
        $stmt->execute([
            'msg_id'      => $messageId,
            'cost'        => $cost,
            'code'        => $statusCode,
            'status_text' => $statusText,
            'id'          => $id,
        ]);
    }

    public static function markFailed(int $id, ?int $statusCode, ?string $statusText, string $error): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE sms_log
             SET status = 'failed', provider_status_code = :code, provider_status_text = :status_text,
                 error = :error
             WHERE id = :id"
        );
        $stmt->execute([
            'code'        => $statusCode,
            'status_text' => $statusText,
            'error'       => mb_substr($error, 0, 500),
            'id'          => $id,
        ]);
    }

    public static function markUnknown(int $id, ?int $statusCode, ?string $statusText, ?string $error): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE sms_log
             SET status = 'unknown', provider_status_code = :code, provider_status_text = :status_text,
                 error = :error
             WHERE id = :id"
        );
        $stmt->execute([
            'code'        => $statusCode,
            'status_text' => $statusText,
            'error'       => $error !== null ? mb_substr($error, 0, 500) : null,
            'id'          => $id,
        ]);
    }

    /** Flip a failed row back to pending so Sms::send(retry: true) can reuse it. */
    public static function reuseForRetry(int $id): void
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE sms_log SET status = 'pending', error = NULL WHERE id = :id AND status = 'failed'"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Apply a reconciled provider status. Cost/segments are only updated
     * when the provider reported them (COALESCE keeps the existing values).
     */
    public static function applyReconciledStatus(
        int $id,
        string $status,
        ?float $cost,
        ?int $segments,
        ?int $statusCode,
        ?string $statusText
    ): void {
        // delivered_at is only set when the row transitions to delivered.
        $deliveredSql = $status === 'delivered' ? ', delivered_at = CURRENT_TIMESTAMP' : '';

        $stmt = Database::getConnection()->prepare(
            "UPDATE sms_log
             SET status = :status,
                 provider_cost = COALESCE(:cost, provider_cost),
                 segments = COALESCE(:segments, segments),
                 provider_status_code = :code,
                 provider_status_text = :status_text{$deliveredSql}
             WHERE id = :id"
        );
        $stmt->execute([
            'status'      => $status,
            'cost'        => $cost,
            'segments'    => $segments,
            'code'        => $statusCode,
            'status_text' => $statusText,
            'id'          => $id,
        ]);
    }

    /**
     * Rows eligible for provider status reconciliation: sent or unknown, at
     * least $minAgeSeconds old, at most $maxAgeDays old, with a provider
     * message id. Cutoffs are computed with UTC clock, matching the
     * convention used by App\Modules\Outbox.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowsForReconcile(int $minAgeSeconds, int $maxAgeDays, int $limit): array
    {
        $maxCreated = gmdate('Y-m-d H:i:s', time() - max(1, $minAgeSeconds));
        $minCreated = gmdate('Y-m-d H:i:s', time() - max(1, $maxAgeDays) * 86400);

        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM sms_log
             WHERE status IN ('sent', 'unknown')
               AND provider_msg_id IS NOT NULL
               AND created_at <= :max_created
               AND created_at >= :min_created
             ORDER BY created_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':max_created', $maxCreated);
        $stmt->bindValue(':min_created', $minCreated);
        $stmt->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Unknown rows WITHOUT a provider message id: the send outcome could not
     * be determined and the row can never be reconciled automatically.
     * Listed for MANUAL review only - these are never re-sent.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unknownWithoutMessageId(int $limit = 100): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM sms_log
             WHERE status = 'unknown' AND provider_msg_id IS NULL
             ORDER BY created_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
