<?php

declare(strict_types=1);

namespace MailAI\Core;

use PDO;

/**
 * AdminAuditLogger
 *
 * Records every super-admin action taken against a client (or the
 * platform generally) into admin_audit_log — spec 1.7's "Platform-wide
 * audit log". Distinct from AIAuditLogger, which records the AI agent's
 * own tool calls, not human super-admin actions.
 */
final class AdminAuditLogger
{
    public function __construct(private PDO $db)
    {
    }

    public function log(string $adminEmail, string $action, ?int $clientId = null, array $details = []): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_audit_log (admin_email, action, client_id, details_json, created_at)
             VALUES (:admin_email, :action, :client_id, :details, NOW())'
        );
        $stmt->execute([
            'admin_email' => $adminEmail,
            'action' => $action,
            'client_id' => $clientId,
            'details' => $details === [] ? null : json_encode($details),
        ]);
    }
}
