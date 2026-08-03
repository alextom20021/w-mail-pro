<?php

declare(strict_types=1);

namespace MailAI\Webhooks;

use PDO;

/**
 * WebhookDispatcher
 *
 * Queues webhook_deliveries rows for every active subscription a client
 * has for a given event type. Deliberately does NOT make the outbound
 * HTTP call inline — a slow/dead customer endpoint must never block the
 * sending worker or the tracking pixel/click redirector (both are on the
 * hot path for actual email delivery and recipient-facing requests).
 * `worker/webhook_worker.php` is the separate process that drains this
 * queue with retry/backoff — see that file for the delivery side.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function dispatch(int $clientId, string $eventType, array $payload): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM webhooks WHERE client_id = :client_id AND is_active = 1
             AND JSON_CONTAINS(events, :event_json)'
        );
        $stmt->execute(['client_id' => $clientId, 'event_json' => json_encode($eventType)]);
        $webhookIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($webhookIds)) {
            return;
        }

        $fullPayload = array_merge(['event' => $eventType, 'timestamp' => date('c')], $payload);

        $insert = $this->db->prepare(
            'INSERT INTO webhook_deliveries (webhook_id, client_id, event_type, payload, next_attempt_at, created_at)
             VALUES (:webhook_id, :client_id, :event_type, :payload, NOW(), NOW())'
        );
        foreach ($webhookIds as $webhookId) {
            $insert->execute([
                'webhook_id' => $webhookId,
                'client_id' => $clientId,
                'event_type' => $eventType,
                'payload' => json_encode($fullPayload),
            ]);
        }
    }
}
