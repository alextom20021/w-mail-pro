<?php

declare(strict_types=1);

namespace MailAI\Tracking;

use PDO;

/**
 * ClickAllowlist
 *
 * Registers every distinct link a campaign's content actually contains
 * (called once per template/variant at queue time by
 * CampaignQueueingService — NOT per-recipient-send, which would be
 * thousands of redundant inserts for the same handful of URLs) and lets
 * the click redirector check a destination against that set before
 * following it. This is what closes the open-redirect gap that used to
 * be documented in public/track/click.php: previously any URL could ride
 * along with a valid signed token; now the URL also has to have been a
 * real link in that campaign.
 */
final class ClickAllowlist
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Extracts the same set of hrefs LinkRewriter would actually rewrite, and registers them. */
    public function registerFromHtml(int $clientId, int $campaignId, string $html): void
    {
        $urls = self::extractTrackableLinks($html);
        if ($urls === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO campaign_links (client_id, campaign_id, url_hash, original_url)
             VALUES (:client_id, :campaign_id, :url_hash, :original_url)
             ON DUPLICATE KEY UPDATE original_url = VALUES(original_url)'
        );

        foreach ($urls as $url) {
            $stmt->execute([
                'client_id' => $clientId,
                'campaign_id' => $campaignId,
                'url_hash' => hash('sha256', $url),
                'original_url' => $url,
            ]);
        }
    }

    public function isRegistered(int $clientId, int $campaignId, string $url): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM campaign_links WHERE client_id = :client_id AND campaign_id = :campaign_id AND url_hash = :url_hash LIMIT 1'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'campaign_id' => $campaignId,
            'url_hash' => hash('sha256', $url),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return string[] */
    public static function extractTrackableLinks(string $html): array
    {
        $urls = [];
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, '#') || str_contains($url, '/unsubscribe')) {
                    continue;
                }
                $urls[$url] = true; // dedup
            }
        }

        return array_keys($urls);
    }
}
