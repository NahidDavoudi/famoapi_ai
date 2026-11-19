<?php

namespace App\Modules\Outbox;

use App\Core\Database;

/**
 * Model for `bot_outbox`: the unified Telegram delivery queue.
 * Claimed rows are locked with a worker id so concurrent workers never take
 * the same item. Claiming is done with a conditional UPDATE (MariaDB 10.4 has
 * no `FOR UPDATE SKIP LOCked`), which is race-safe across workers.
 */
class OutboxItem
{
    /**
     * @param array<string,mixed> $data
     * @return int new id, or 0 when skipped due to the unique dedup key
     */
    public static function insert(array $data): int
    {
        $db = Database::getConnection();
        try {
            $stmt = $db->prepare(
                'INSERT INTO bot_outbox
                    (kind, recipient_role, recipient_account_id, telegram_user_id, chat_id, status,
                     payload_json, ref_type, ref_id, dedup_key, max_attempts, available_at, created_at, updated_at)
                 VALUES (:kind, :recipient_role, :recipient_account_id, :telegram_user_id, :chat_id, :status,
                     :payload_json, :ref_type, :ref_id, :dedup_key, :max_attempts, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'kind'                 => $data['kind'],
                'recipient_role'       => $data['recipient_role'],
                'recipient_account_id' => $data['recipient_account_id'],
                'telegram_user_id'     => $data['telegram_user_id'] ?? null,
                'chat_id'              => $data['chat_id'] ?? null,
                'status'               => $data['status'] ?? 'pending',
                'payload_json'         => $data['payload_json'],
                'ref_type'             => $data['ref_type'] ?? null,
                'ref_id'               => $data['ref_id'] ?? null,
                'dedup_key'            => $data['dedup_key'] ?? null,
                'max_attempts'         => $data['max_attempts'] ?? 3,
            ]);

            return (int) $db->lastInsertId();
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return 0;
            }
            throw $e;
        }
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT * FROM bot_outbox WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return int[]
     */
    public static function pendingIds(int $limit): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id FROM bot_outbox
             WHERE status = :status AND available_at <= CURRENT_TIMESTAMP AND attempts < max_attempts
             ORDER BY id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':status', 'pending');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Atomically flip still-pending rows to processing for this worker.
     *
     * @param int[] $ids
     */
    public static function markProcessing(array $ids, string $workerId): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = Database::getConnection()->prepare(
            "UPDATE bot_outbox
             SET status = 'processing', locked_by = ?, locked_at = CURRENT_TIMESTAMP, attempts = attempts + 1
             WHERE id IN ({$placeholders})
               AND status = 'pending'
               AND available_at <= CURRENT_TIMESTAMP
               AND attempts < max_attempts"
        );
        $stmt->execute(array_merge([$workerId], array_values($ids)));

        return $stmt->rowCount();
    }

    /**
     * @param int[] $ids
     * @return array<int,array>
     */
    public static function findClaimed(array $ids, string $workerId): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM bot_outbox
             WHERE id IN ({$placeholders}) AND status = 'processing' AND locked_by = ?
             ORDER BY id ASC"
        );
        $stmt->execute(array_merge(array_values($ids), [$workerId]));

        return $stmt->fetchAll();
    }

    /** Return stale processing rows (dead workers) to the pending pool. */
    public static function releaseStale(int $ttlSeconds): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $ttlSeconds));
        $stmt = Database::getConnection()->prepare(
            'UPDATE bot_outbox
             SET status = :pending, locked_by = NULL, locked_at = NULL
             WHERE status = :processing
               AND locked_at IS NOT NULL
               AND locked_at < :cutoff'
        );
        $stmt->bindValue(':pending', 'pending');
        $stmt->bindValue(':processing', 'processing');
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public static function markSent(int $id, ?string $telegramMessageId): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE bot_outbox
             SET status = :sent, telegram_message_id = :tg_message_id, error = NULL,
                 locked_by = NULL, locked_at = NULL, sent_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['sent' => 'sent', 'tg_message_id' => $telegramMessageId, 'id' => $id]);

        return $stmt->rowCount();
    }

    public static function markRetry(int $id, string $error, int $backoffSeconds): int
    {
        $availableAt = gmdate('Y-m-d H:i:s', time() + max(0, $backoffSeconds));
        $stmt = Database::getConnection()->prepare(
            'UPDATE bot_outbox
             SET status = :pending, error = :error, locked_by = NULL, locked_at = NULL,
                 available_at = :available_at
             WHERE id = :id'
        );
        $stmt->bindValue(':pending', 'pending');
        $stmt->bindValue(':error', mb_substr($error, 0, 500));
        $stmt->bindValue(':available_at', $availableAt);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public static function markFailed(int $id, string $error): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE bot_outbox
             SET status = :failed, error = :error, locked_by = NULL, locked_at = NULL
             WHERE id = :id'
        );
        $stmt->execute(['failed' => 'failed', 'error' => mb_substr($error, 0, 500), 'id' => $id]);

        return $stmt->rowCount();
    }

    public static function markSkipped(int $id, string $reason): int
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE bot_outbox
             SET status = :skipped, error = :reason, locked_by = NULL, locked_at = NULL
             WHERE id = :id'
        );
        $stmt->execute(['skipped' => 'skipped', 'reason' => mb_substr($reason, 0, 500), 'id' => $id]);

        return $stmt->rowCount();
    }
}
