<?php

declare(strict_types=1);

namespace MailAI\Analytics;

use MailAI\Core\ClientContext;
use PDO;

/**
 * AnalyticsService
 *
 * Aggregation queries over `isp_deliverability_stats` for the dashboard
 * and REST API. Every method is scoped to the current ClientContext.
 * Results are cached in-process per request (simple static array) —
 * genuine cross-request caching (Redis) is a Phase 2 item once the
 * dashboard's query volume justifies it; these aggregate queries run
 * against a pre-aggregated daily table, not raw email_logs, so they're
 * cheap even unc­ached.
 */
final class AnalyticsService
{
    private static array $memoCache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function byIsp(int $days = 30): array
    {
        return $this->memoize("isp:{$days}", function () use ($days) {
            $stmt = $this->db->prepare(
                "SELECT isp,
                        SUM(sent) AS sent, SUM(delivered) AS delivered, SUM(opened) AS opened,
                        SUM(clicked) AS clicked, SUM(soft_bounced) AS soft_bounced,
                        SUM(hard_bounced) AS hard_bounced, SUM(complained) AS complained
                 FROM isp_deliverability_stats
                 WHERE client_id = :client_id AND stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                 GROUP BY isp ORDER BY sent DESC"
            );
            $stmt->execute(['client_id' => ClientContext::clientId(), 'days' => $days]);

            return array_map([$this, 'withRates'], $stmt->fetchAll());
        });
    }

    public function byCountry(int $days = 30): array
    {
        return $this->memoize("country:{$days}", function () use ($days) {
            $stmt = $this->db->prepare(
                "SELECT country,
                        SUM(sent) AS sent, SUM(delivered) AS delivered, SUM(opened) AS opened,
                        SUM(clicked) AS clicked, SUM(soft_bounced) AS soft_bounced,
                        SUM(hard_bounced) AS hard_bounced, SUM(complained) AS complained
                 FROM isp_deliverability_stats
                 WHERE client_id = :client_id AND stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY) AND country IS NOT NULL
                 GROUP BY country ORDER BY sent DESC"
            );
            $stmt->execute(['client_id' => ClientContext::clientId(), 'days' => $days]);

            return array_map([$this, 'withRates'], $stmt->fetchAll());
        });
    }

    public function byConnection(int $days = 30): array
    {
        return $this->memoize("connection:{$days}", function () use ($days) {
            $stmt = $this->db->prepare(
                "SELECT s.connection_id, c.label AS connection_label, c.type AS connection_type,
                        SUM(s.sent) AS sent, SUM(s.delivered) AS delivered, SUM(s.opened) AS opened,
                        SUM(s.clicked) AS clicked, SUM(s.soft_bounced) AS soft_bounced,
                        SUM(s.hard_bounced) AS hard_bounced, SUM(s.complained) AS complained
                 FROM isp_deliverability_stats s
                 LEFT JOIN sending_connections c ON c.id = s.connection_id
                 WHERE s.client_id = :client_id AND s.stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY) AND s.connection_id > 0
                 GROUP BY s.connection_id, c.label, c.type ORDER BY sent DESC"
            );
            $stmt->execute(['client_id' => ClientContext::clientId(), 'days' => $days]);

            return array_map([$this, 'withRates'], $stmt->fetchAll());
        });
    }

    public function timeSeries(int $days = 30): array
    {
        return $this->memoize("timeseries:{$days}", function () use ($days) {
            $stmt = $this->db->prepare(
                "SELECT stat_date,
                        SUM(sent) AS sent, SUM(delivered) AS delivered, SUM(opened) AS opened,
                        SUM(clicked) AS clicked, SUM(hard_bounced + soft_bounced) AS bounced,
                        SUM(complained) AS complained
                 FROM isp_deliverability_stats
                 WHERE client_id = :client_id AND stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                 GROUP BY stat_date ORDER BY stat_date ASC"
            );
            $stmt->execute(['client_id' => ClientContext::clientId(), 'days' => $days]);

            return $stmt->fetchAll();
        });
    }

    public function failureReasonBreakdown(int $days = 30): array
    {
        return $this->memoize("failures:{$days}", function () use ($days) {
            $stmt = $this->db->prepare(
                "SELECT bounce_type, smtp_code, COUNT(*) AS count
                 FROM bounces
                 WHERE client_id = :client_id AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 GROUP BY bounce_type, smtp_code ORDER BY count DESC"
            );
            $stmt->execute(['client_id' => ClientContext::clientId(), 'days' => $days]);

            return $stmt->fetchAll();
        });
    }

    private function withRates(array $row): array
    {
        $sent = max(1, (int) $row['sent']);
        $row['inbox_rate_pct'] = round((int) $row['delivered'] / $sent * 100, 2);
        $row['open_rate_pct'] = round((int) $row['opened'] / $sent * 100, 2);
        $row['click_rate_pct'] = round((int) $row['clicked'] / $sent * 100, 2);
        $row['bounce_rate_pct'] = round(((int) $row['soft_bounced'] + (int) $row['hard_bounced']) / $sent * 100, 2);
        $row['complaint_rate_pct'] = round((int) $row['complained'] / $sent * 100, 4);

        return $row;
    }

    private function memoize(string $key, callable $fn): array
    {
        $fullKey = ClientContext::clientId() . ':' . $key;
        if (!isset(self::$memoCache[$fullKey])) {
            self::$memoCache[$fullKey] = $fn();
        }

        return self::$memoCache[$fullKey];
    }
}
