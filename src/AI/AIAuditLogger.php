<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Core\Database;
use PDO;

/**
 * AIAuditLogger
 *
 * Every single AI tool invocation is written to `ai_audit_log` — input,
 * output, which provider/model proposed it, and whether it was
 * auto-executed or required human approval. This is what makes an
 * "AI agent that manages your infrastructure" trustworthy rather than a
 * black box: a client (or you, debugging a support ticket) can always
 * answer "why did the IP rotation change last Tuesday?"
 */
final class AIAuditLogger
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function logProposed(
        ?int $clientId,
        string $provider,
        string $toolName,
        array $input,
        string $autonomyLevel,
        ?string $decisionSummary = null
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO ai_audit_log
                (client_id, actor, provider, tool_name, input_json, decision_summary, autonomy_level, status)
             VALUES (:client_id, 'agent', :provider, :tool_name, :input_json, :decision_summary, :autonomy_level, 'proposed')"
        );
        $stmt->execute([
            'client_id' => $clientId,
            'provider' => $provider,
            'tool_name' => $toolName,
            'input_json' => json_encode($input, JSON_THROW_ON_ERROR),
            'decision_summary' => $decisionSummary,
            'autonomy_level' => $autonomyLevel,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markExecuted(int $auditId, array $output): void
    {
        $this->update($auditId, 'executed', $output);
    }

    public function markFailed(int $auditId, string $errorMessage): void
    {
        $this->update($auditId, 'failed', ['error' => $errorMessage]);
    }

    public function markApproved(int $auditId, int $approvedByUser): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ai_audit_log SET status = 'approved', approved_by_user = :user WHERE id = :id"
        );
        $stmt->execute(['user' => $approvedByUser, 'id' => $auditId]);
    }

    public function markRejected(int $auditId): void
    {
        $stmt = $this->db->prepare("UPDATE ai_audit_log SET status = 'rejected' WHERE id = :id");
        $stmt->execute(['id' => $auditId]);
    }

    private function update(int $auditId, string $status, array $output): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ai_audit_log SET status = :status, output_json = :output WHERE id = :id"
        );
        $stmt->execute([
            'status' => $status,
            'output' => json_encode($output, JSON_THROW_ON_ERROR),
            'id' => $auditId,
        ]);
    }
}
