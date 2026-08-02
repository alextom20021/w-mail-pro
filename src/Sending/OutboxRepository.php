<?php

declare(strict_types=1);

namespace MailAI\Sending;

use MailAI\Core\Database;
use PDO;

/**
 * OutboxRepository
 *
 * NOT a TenantRepository — the worker processes jobs across ALL clients
 * in one process, so it needs cross-tenant reads. Every row it touches
 * already carries its own client_id; the worker sets ClientContext
 * per-job right before doing any client-scoped work (template lookups,
 * AI tool calls), then clears it after — see worker.php.
 */
final class OutboxRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Atomically claims up to $batchSize due jobs for this worker instance.
     * Uses SELECT ... FOR UPDATE SKIP LOCKED (requires MySQL 8.0+ / MariaDB
     * 10.6+) so multiple worker processes can run concurrently without
     * blocking on each other's row locks, then a separate UPDATE to flip
     * status before releasing the transaction.
     */
    public function claimBatch(string $workerId, int $batchSize = 20): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM outbox
                 WHERE status = 'queued' AND scheduled_at <= NOW()
                 ORDER BY scheduled_at ASC
                 LIMIT :limit FOR UPDATE SKIP LOCKED"
            );
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_column($stmt->fetchAll(), 'id');

            if (empty($ids)) {
                $this->db->commit();
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->db->prepare(
                "UPDATE outbox SET status = 'locked', locked_by = ?, locked_at = NOW() WHERE id IN ({$placeholders})"
            );
            $update->execute([$workerId, ...$ids]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $select = $this->db->prepare("SELECT * FROM outbox WHERE id IN ({$placeholders})");
        $select->execute($ids);

        return $select->fetchAll();
    }

    public function markSending(int $id, int $connectionId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE outbox SET status = 'sending', connection_id = :connection_id WHERE id = :id"
        );
        $stmt->execute(['connection_id' => $connectionId, 'id' => $id]);
    }

    public function markSent(int $id, ?string $smtpTranscript = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE outbox SET status = 'sent', sent_at = NOW(), smtp_transcript = :transcript WHERE id = :id"
        );
        $stmt->execute(['transcript' => $smtpTranscript, 'id' => $id]);
    }

    public function markFailed(int $id, string $error, bool $isHardFailure): void
    {
        if ($isHardFailure) {
            $stmt = $this->db->prepare(
                "UPDATE outbox SET status = 'hard_bounced', last_error = :error WHERE id = :id"
            );
            $stmt->execute(['error' => $error, 'id' => $id]);
            return;
        }

        // Soft failure: exponential backoff, retry up to max_attempts.
        $stmt = $this->db->prepare(
            "UPDATE outbox
             SET attempts = attempts + 1,
                 status = IF(attempts + 1 >= max_attempts, 'failed', 'queued'),
                 next_retry_at = DATE_ADD(NOW(), INTERVAL POW(2, attempts + 1) MINUTE),
                 scheduled_at = DATE_ADD(NOW(), INTERVAL POW(2, attempts + 1) MINUTE),
                 last_error = :error
             WHERE id = :id"
        );
        $stmt->execute(['error' => $error, 'id' => $id]);
    }

    /** Recovers jobs stuck in 'locked'/'sending' from a worker that crashed mid-batch. */
    public function releaseStaleLocks(int $staleAfterMinutes = 10): int
    {
        $stmt = $this->db->prepare(
            "UPDATE outbox SET status = 'queued', locked_by = NULL, locked_at = NULL
             WHERE status IN ('locked', 'sending') AND locked_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)"
        );
        $stmt->execute(['mins' => $staleAfterMinutes]);

        return $stmt->rowCount();
    }
}
