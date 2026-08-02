<?php

declare(strict_types=1);

namespace MailAI\Sending;

use MailAI\Core\ClientContext;
use MailAI\Core\ContactRepository;
use PDO;
use RuntimeException;

/**
 * CampaignQueueingService
 *
 * Expands a campaign (template + list) into individual `outbox` rows,
 * one per subscribed, non-suppressed contact. This is the bridge between
 * "client clicks Send" and the worker's queue — after this runs, the
 * campaign is entirely in the worker's hands (rotation, retries,
 * compliance re-checks all happen there, not here).
 *
 * Batches inserts (500 rows per statement) rather than one INSERT per
 * contact — meaningfully faster for large lists and still simple SQL.
 */
final class CampaignQueueingService
{
    private const BATCH_SIZE = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return int Number of contacts enqueued. */
    public function enqueue(int $campaignId, string $subject, string $htmlBody, int $listId): int
    {
        $clientId = ClientContext::clientId();
        $contactRepo = new ContactRepository();

        $enqueued = 0;
        $offset = 0;

        while (true) {
            $contacts = $contactRepo->findSubscribed($listId, self::BATCH_SIZE, $offset);
            if (empty($contacts)) {
                break;
            }

            $this->insertBatch($clientId, $campaignId, $subject, $htmlBody, $contacts);
            $enqueued += count($contacts);
            $offset += self::BATCH_SIZE;
        }

        if ($enqueued === 0) {
            throw new RuntimeException('No eligible (subscribed, non-suppressed) contacts found in this list.');
        }

        $this->db->prepare("UPDATE campaigns SET status = 'sending' WHERE id = :id AND client_id = :client_id")
            ->execute(['id' => $campaignId, 'client_id' => $clientId]);

        return $enqueued;
    }

    private function insertBatch(int $clientId, int $campaignId, string $subject, string $htmlBody, array $contacts): void
    {
        $placeholders = [];
        $values = [];

        foreach ($contacts as $contact) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, NOW(), \'queued\')';
            array_push($values, $clientId, $campaignId, $contact['id'], $contact['email'], $subject, $htmlBody);
        }

        $sql = 'INSERT INTO outbox (client_id, campaign_id, contact_id, to_email, subject, html_body, scheduled_at, status) VALUES '
            . implode(', ', $placeholders);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }
}
