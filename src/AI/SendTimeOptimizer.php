<?php

declare(strict_types=1);

namespace MailAI\AI;

use PDO;

/**
 * SendTimeOptimizer
 *
 * Replaces the old "best_send_hour_local = HOUR(NOW())" placeholder in
 * TrackingEventRecorder with a real rolling histogram: every open/click
 * bumps contact_engagement_hours(contact_id, hour_of_day), and the
 * contact's best_send_hour_local is recomputed as the MODE of that
 * histogram (the hour with the most engagement events), not just
 * whatever hour the most recent event happened to land in.
 *
 * Hour is server/UTC hour, not a true per-contact local time — this
 * project has no per-contact timezone geocoding (IP-based timezone
 * lookup would be needed for that; GeoIpService only resolves country).
 * Good enough for "this contact tends to engage in the evening" without
 * overclaiming precision the data doesn't support.
 */
final class SendTimeOptimizer
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Call on every open/click event, in place of the old naive overwrite. */
    public function recordEngagementHour(int $clientId, int $contactId, ?int $hour = null): void
    {
        $hour = $hour ?? (int) date('G');

        $stmt = $this->db->prepare(
            'INSERT INTO contact_engagement_hours (client_id, contact_id, hour_of_day, event_count)
             VALUES (:client_id, :contact_id, :hour, 1)
             ON DUPLICATE KEY UPDATE event_count = event_count + 1'
        );
        $stmt->execute(['client_id' => $clientId, 'contact_id' => $contactId, 'hour' => $hour]);

        $this->recomputeBestHour($clientId, $contactId);
    }

    private function recomputeBestHour(int $clientId, int $contactId): void
    {
        // MODE: the hour with the highest event_count. Ties broken by the
        // lowest hour number (deterministic, and an early-day tiebreak is
        // a reasonable default for "when in doubt, send earlier").
        $stmt = $this->db->prepare(
            'SELECT hour_of_day FROM contact_engagement_hours
             WHERE client_id = :client_id AND contact_id = :contact_id
             ORDER BY event_count DESC, hour_of_day ASC
             LIMIT 1'
        );
        $stmt->execute(['client_id' => $clientId, 'contact_id' => $contactId]);
        $bestHour = $stmt->fetchColumn();

        if ($bestHour === false) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE contacts SET best_send_hour_local = :hour WHERE id = :id AND client_id = :client_id'
        );
        $stmt->execute(['hour' => (int) $bestHour, 'id' => $contactId, 'client_id' => $clientId]);
    }

    /**
     * For a list of contact IDs, bucket them by best_send_hour_local so a
     * campaign can be split into per-hour sends. Contacts with no
     * histogram yet (best_send_hour_local IS NULL) land in the 'unknown'
     * bucket — callers should send those at the campaign's default time
     * rather than withholding them.
     *
     * @param int[] $contactIds
     * @return array<string, int[]> hour (0-23 as string) or 'unknown' => contact IDs
     */
    public function bucketContactsByBestHour(int $clientId, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, best_send_hour_local FROM contacts
             WHERE client_id = ? AND id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$clientId], $contactIds));

        $buckets = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['best_send_hour_local'] === null ? 'unknown' : (string) $row['best_send_hour_local'];
            $buckets[$key][] = (int) $row['id'];
        }

        return $buckets;
    }
}
