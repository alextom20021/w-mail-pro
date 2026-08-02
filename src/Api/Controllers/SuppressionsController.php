<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\Api\JsonResponse;
use MailAI\Core\ClientContext;
use MailAI\Core\Database;

final class SuppressionsController
{
    public function index(): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT email, reason, source_detail, created_at FROM suppressions WHERE client_id = :client_id ORDER BY created_at DESC LIMIT 1000'
        );
        $stmt->execute(['client_id' => ClientContext::clientId()]);

        JsonResponse::ok(['suppressions' => $stmt->fetchAll()]);
    }

    public function create(array $body): void
    {
        if (empty($body['email'])) {
            JsonResponse::error('Missing required field: email', 422);
            return;
        }

        $db = Database::connection();
        $db->prepare(
            "INSERT IGNORE INTO suppressions (client_id, email, reason, source_detail)
             VALUES (:client_id, :email, 'manual', :detail)"
        )->execute([
            'client_id' => ClientContext::clientId(),
            'email' => strtolower(trim($body['email'])),
            'detail' => $body['reason'] ?? 'Added via API',
        ]);

        $db->prepare(
            "UPDATE contacts SET status = 'unsubscribed' WHERE client_id = :client_id AND email = :email"
        )->execute(['client_id' => ClientContext::clientId(), 'email' => strtolower(trim($body['email']))]);

        JsonResponse::ok(['suppressed' => true], 201);
    }
}
