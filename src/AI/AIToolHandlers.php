<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Sending\SendingConnectionRepository;

/**
 * AIToolHandlers
 *
 * Registers the concrete tool set the agent can call. Each handler is a
 * thin wrapper around an existing repository/service method — the agent
 * never gets raw DB or filesystem access, only these named actions. Add
 * new tools here as the platform grows (e.g. apply_dns_record,
 * schedule_send_time) rather than widening what the LLM can touch directly.
 */
final class AIToolHandlers
{
    public static function registerAll(AIToolRegistry $registry, SendingConnectionRepository $connections): void
    {
        $registry->register(
            'adjust_warmup',
            'Adjust a sending connection\'s daily send limit as part of its warm-up ramp. Use small, ' .
            'conservative increases (typically 20-50%) and never jump a brand-new connection straight to a high volume.',
            [
                'type' => 'object',
                'properties' => [
                    'connection_id' => ['type' => 'integer', 'description' => 'sending_connections.id'],
                    'new_daily_limit' => ['type' => 'integer', 'description' => 'New daily_limit value'],
                    'reason' => ['type' => 'string', 'description' => 'Why this adjustment is being made'],
                ],
                'required' => ['connection_id', 'new_daily_limit', 'reason'],
            ],
            function (array $args) use ($connections): array {
                $connections->update((int) $args['connection_id'], ['daily_limit' => (int) $args['new_daily_limit']]);
                self::recordWarmupHistory((int) $args['connection_id'], (int) $args['new_daily_limit'], $args['reason']);

                return ['status' => 'ok', 'connection_id' => $args['connection_id'], 'new_daily_limit' => $args['new_daily_limit']];
            }
        );

        $registry->register(
            'quarantine_connection',
            'Immediately stop sending through a connection (IP or API) that shows a complaint/bounce spike, ' .
            'and mark it quarantined so the rotator excludes it. Use this for self-healing when reputation drops sharply.',
            [
                'type' => 'object',
                'properties' => [
                    'connection_id' => ['type' => 'integer'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['connection_id', 'reason'],
            ],
            function (array $args) use ($connections): array {
                $connections->quarantine((int) $args['connection_id'], $args['reason']);

                return ['status' => 'quarantined', 'connection_id' => $args['connection_id']];
            }
        );

        $registry->register(
            'get_deliverability_summary',
            'Fetch aggregated deliverability stats (sent, delivered, opened, bounced, complained) for the ' .
            'current client, optionally filtered by ISP and date range, to answer analytics questions.',
            [
                'type' => 'object',
                'properties' => [
                    'isp' => ['type' => 'string', 'description' => 'e.g. gmail, outlook, yahoo, apple, other'],
                    'days' => ['type' => 'integer', 'description' => 'How many days back to include, default 7'],
                ],
                'required' => [],
            ],
            function (array $args): array {
                return self::deliverabilitySummary($args['isp'] ?? null, (int) ($args['days'] ?? 7));
            }
        );

        $registry->register(
            'create_email_template',
            'Create a new AI-generated email template for the current client from a subject/body the agent ' .
            'has drafted. Always run the content through spam-risk review before calling this.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'html_body' => ['type' => 'string'],
                ],
                'required' => ['name', 'subject', 'html_body'],
            ],
            function (array $args): array {
                return self::createTemplate($args['name'], $args['subject'], $args['html_body']);
            }
        );

        $registry->register(
            'score_content_spam_risk',
            'Run heuristic spam-risk analysis on a subject/body pair before sending. Returns a 0-100 risk ' .
            'score, risk level, and specific issues found. This is a heuristic estimate, not a guarantee — ' .
            'say so plainly if reporting the score to a client.',
            [
                'type' => 'object',
                'properties' => [
                    'subject' => ['type' => 'string'],
                    'html_body' => ['type' => 'string'],
                ],
                'required' => ['subject', 'html_body'],
            ],
            function (array $args): array {
                return (new ContentScorer())->score($args['subject'], $args['html_body']);
            }
        );

        $registry->register(
            'clean_contact_list',
            'Scan a contact list for risky addresses (role accounts, disposable domains, invalid syntax, ' .
            'prior hard bounces) and flag/suppress them. Invalid-syntax and previously-hard-bounced addresses ' .
            'are suppressed automatically; role accounts and other soft signals are only flagged for review.',
            [
                'type' => 'object',
                'properties' => [
                    'list_id' => ['type' => 'integer'],
                ],
                'required' => ['list_id'],
            ],
            function (array $args): array {
                return (new ListCleaner(Database::connection()))->cleanCurrentClientList((int) $args['list_id']);
            }
        );
    }

    private static function recordWarmupHistory(int $connectionId, int $newLimit, string $reason): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "INSERT INTO warmup_schedules (client_id, connection_id, day_number, target_volume, ai_adjusted, adjustment_reason, applies_on)
             VALUES (:client_id, :connection_id, 0, :target_volume, 1, :reason, CURDATE())"
        );
        $stmt->execute([
            'client_id' => ClientContext::clientId(),
            'connection_id' => $connectionId,
            'target_volume' => $newLimit,
            'reason' => $reason,
        ]);
    }

    private static function deliverabilitySummary(?string $isp, int $days): array
    {
        $db = Database::connection();
        $sql = "SELECT isp,
                       SUM(sent) AS sent, SUM(delivered) AS delivered, SUM(opened) AS opened,
                       SUM(clicked) AS clicked, SUM(soft_bounced) AS soft_bounced,
                       SUM(hard_bounced) AS hard_bounced, SUM(complained) AS complained
                FROM isp_deliverability_stats
                WHERE client_id = :client_id AND stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)";
        $params = ['client_id' => ClientContext::clientId(), 'days' => $days];

        if ($isp !== null) {
            $sql .= ' AND isp = :isp';
            $params['isp'] = $isp;
        }
        $sql .= ' GROUP BY isp';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return ['days' => $days, 'isp_filter' => $isp, 'rows' => $stmt->fetchAll()];
    }

    private static function createTemplate(string $name, string $subject, string $htmlBody): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "INSERT INTO email_templates (client_id, name, subject, html_body, ai_generated, created_at)
             VALUES (:client_id, :name, :subject, :html_body, 1, NOW())"
        );
        $stmt->execute([
            'client_id' => ClientContext::clientId(),
            'name' => $name,
            'subject' => $subject,
            'html_body' => $htmlBody,
        ]);

        return ['status' => 'created', 'template_id' => (int) $db->lastInsertId()];
    }
}
