<?php

declare(strict_types=1);

namespace MailAI\Sending;

use MailAI\Core\ClientContext;
use MailAI\Core\ContactRepository;
use MailAI\Tracking\ClickAllowlist;
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

        // Register this campaign's links ONCE here (not per-recipient in
        // the worker) so click.php can allowlist against them — see
        // ClickAllowlist's docblock for why per-send registration would be
        // wasteful.
        (new ClickAllowlist($this->db))->registerFromHtml($clientId, $campaignId, $htmlBody);

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

    /**
     * A/B testing variant of enqueue(): splits the list across variants by
     * traffic_percent (deterministic — contact.id % 100 decides which
     * variant's cumulative percentage bucket a contact falls into, so the
     * same contact always lands in the same variant for this campaign,
     * which matters if enqueue is ever re-run/resumed).
     *
     * Winner selection is NOT done here — ABTestingService picks a winner
     * after the whole campaign has enough data (see that class for why:
     * this project sends the full split up front rather than a small test
     * batch + winner rollout to the remainder, which keeps the queueing
     * model simple at the cost of not saving send volume on the loser).
     *
     * @param array<int, array{id:int, template_id:int, subject:string, html_body:string, traffic_percent:int}> $variants
     * @return int Number of contacts enqueued.
     */
    public function enqueueAbTest(int $campaignId, array $variants, int $listId): int
    {
        if ($variants === []) {
            throw new RuntimeException('At least one variant is required for an A/B test.');
        }

        $clientId = ClientContext::clientId();
        $contactRepo = new ContactRepository();

        $allowlist = new ClickAllowlist($this->db);
        foreach ($variants as $variant) {
            $allowlist->registerFromHtml($clientId, $campaignId, $variant['html_body']);
        }

        // Cumulative percentage boundaries, e.g. [50, 100] for two 50/50 variants.
        $cumulative = 0;
        $boundaries = [];
        foreach ($variants as $variant) {
            $cumulative += max(0, (int) $variant['traffic_percent']);
            $boundaries[] = ['variant' => $variant, 'upTo' => min($cumulative, 100)];
        }

        $enqueued = 0;
        $offset = 0;

        while (true) {
            $contacts = $contactRepo->findSubscribed($listId, self::BATCH_SIZE, $offset);
            if (empty($contacts)) {
                break;
            }

            $byVariant = [];
            foreach ($contacts as $contact) {
                $bucket = ((int) $contact['id']) % 100;
                $chosen = end($boundaries)['variant']; // fallback: last variant catches any rounding remainder
                foreach ($boundaries as $b) {
                    if ($bucket < $b['upTo']) {
                        $chosen = $b['variant'];
                        break;
                    }
                }
                $byVariant[$chosen['id']]['variant'] = $chosen;
                $byVariant[$chosen['id']]['contacts'][] = $contact;
            }

            foreach ($byVariant as $group) {
                $this->insertBatch(
                    $clientId,
                    $campaignId,
                    $group['variant']['subject'],
                    $group['variant']['html_body'],
                    $group['contacts'],
                    (int) $group['variant']['id']
                );
            }

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

    private function insertBatch(int $clientId, int $campaignId, string $subject, string $htmlBody, array $contacts, ?int $variantId = null): void
    {
        $placeholders = [];
        $values = [];

        foreach ($contacts as $contact) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, NOW(), \'queued\')';
            array_push($values, $clientId, $campaignId, $variantId, $contact['id'], $contact['email'], $subject, $htmlBody);
        }

        $sql = 'INSERT INTO outbox (client_id, campaign_id, variant_id, contact_id, to_email, subject, html_body, scheduled_at, status) VALUES '
            . implode(', ', $placeholders);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }
}
