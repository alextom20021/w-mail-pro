<?php

declare(strict_types=1);

namespace MailAI\Webhooks;

use MailAI\Core\TenantRepository;
use MailAI\Security\EncryptionService;

/**
 * WebhookRepository
 *
 * Client-facing CRUD for webhook subscriptions. The signing secret is
 * generated server-side (never client-supplied — same reasoning as API
 * keys) and encrypted at rest like every other secret in this project.
 */
final class WebhookRepository extends TenantRepository
{
    protected string $table = 'webhooks';

    public function __construct(private readonly EncryptionService $encryption, ?\PDO $db = null)
    {
        parent::__construct($db);
    }

    public const VALID_EVENTS = ['send', 'open', 'click', 'bounce', 'complaint'];

    /** @return array{id:int, secret:string} */
    public function create(string $url, array $events): array
    {
        $events = array_values(array_intersect($events, self::VALID_EVENTS));
        if ($events === []) {
            throw new \RuntimeException('Select at least one valid event type.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url)) {
            throw new \RuntimeException('Webhook URL must be a valid https:// URL.');
        }

        $secret = bin2hex(random_bytes(32));

        $id = $this->insert([
            'url' => $url,
            'secret_encrypted' => $this->encryption->encrypt($secret),
            'events' => json_encode($events),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'secret' => $secret];
    }

    public function setActive(int $id, bool $active): bool
    {
        return $this->update($id, ['is_active' => $active ? 1 : 0]);
    }

    /** Recent delivery attempts, newest first — for the client to debug their endpoint. */
    public function recentDeliveries(int $webhookId, int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = :webhook_id AND client_id = :client_id
             ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue('webhook_id', $webhookId, \PDO::PARAM_INT);
        $stmt->bindValue('client_id', $this->clientId(), \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
