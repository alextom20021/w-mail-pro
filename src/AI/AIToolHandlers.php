<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Core\CampaignRepository;
use MailAI\Core\ClientContext;
use MailAI\Core\ContactListRepository;
use MailAI\Core\Database;
use MailAI\Core\DomainRepository;
use MailAI\Sending\CampaignQueueingService;
use MailAI\Sending\SendingConnectionRepository;
use MailAI\Security\EncryptionService;

/**
 * AIToolHandlers
 *
 * Registers the concrete tool set the agent can call. Each handler is a
 * thin wrapper around an existing repository/service method — the agent
 * never gets raw DB or filesystem access, only these named actions.
 *
 * This is the "AI does the setup work for the client" surface: a client
 * can say "connect my SendGrid account" or "add mail.mydomain.com and
 * verify it" in chat, the agent asks for whatever structured details it
 * needs (API key, domain name, etc.) via normal conversation, and once
 * the client confirms, calls add_sending_connection / add_domain /
 * create_campaign here — same repository code the dashboard forms call,
 * just invoked by the agent instead of a browser POST. Nothing here
 * bypasses ClientContext/TenantRepository scoping, autonomy-level
 * gating, or AIAgent::DESTRUCTIVE_TOOLS (send_campaign_now,
 * disable_connection, delete_contact_list all still route through human
 * approval regardless of autonomy level — see AIAgent).
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

        // ---------------------------------------------------------------
        // Setup / provisioning tools — the agent doing onboarding work
        // conversationally instead of the client filling out dashboard
        // forms by hand. Ask for whatever fields are missing, confirm
        // with the client, then call these.
        // ---------------------------------------------------------------

        $registry->register(
            'list_connections',
            'List the current client\'s sending connections (SMTP/API) with status, type, and daily limit — ' .
            'use this to answer questions or to get real connection_id values before calling another tool.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function () use ($connections): array {
                return ['connections' => array_map(
                    fn($c) => ['id' => $c['id'], 'label' => $c['label'], 'type' => $c['type'], 'status' => $c['status'], 'daily_limit' => $c['daily_limit']],
                    $connections->findAllWhere()
                )];
            }
        );

        $registry->register(
            'add_sending_connection',
            'Add a new SMTP or API sending connection after the client has provided credentials in chat and ' .
            'confirmed you should create it. type is one of smtp, sendgrid, mailgun, ses, postmark. Field ' .
            'requirements by type — smtp: host, port, username, password, encryption(tls|ssl); sendgrid: ' .
            'api_key; mailgun: api_key, domain, region(us|eu); ses: access_key, secret_key, region; postmark: ' .
            'server_token. All types also need from_email and from_name. New connections start in "warming" ' .
            'status — the warm-up scheduler ramps them automatically, never send at full volume immediately.',
            [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['smtp', 'sendgrid', 'mailgun', 'ses', 'postmark']],
                    'label' => ['type' => 'string'],
                    'from_email' => ['type' => 'string'],
                    'from_name' => ['type' => 'string'],
                    'daily_limit' => ['type' => 'integer', 'description' => 'Starting daily limit, default 50 — keep conservative for a new connection'],
                    'credentials' => [
                        'type' => 'object',
                        'description' => 'Type-specific fields as documented in the tool description (host/port/username/password/encryption for smtp, api_key for sendgrid, etc).',
                    ],
                ],
                'required' => ['type', 'label', 'from_email', 'credentials'],
            ],
            function (array $args) use ($connections): array {
                $credentials = array_merge($args['credentials'], [
                    'from_email' => $args['from_email'],
                    'from_name' => $args['from_name'] ?? '',
                ]);
                $id = $connections->create($args['type'], $args['label'], $credentials, (int) ($args['daily_limit'] ?? 50));

                return ['status' => 'created', 'connection_id' => $id, 'note' => 'Starts in warming status; AI warm-up scheduler will ramp it automatically.'];
            }
        );

        $registry->register(
            'disable_connection',
            'Pause a sending connection so it stops receiving new sends (distinct from quarantine_connection, ' .
            'which is for reputation emergencies — use this for a client-requested pause, e.g. "stop using my old SMTP").',
            [
                'type' => 'object',
                'properties' => [
                    'connection_id' => ['type' => 'integer'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['connection_id', 'reason'],
            ],
            function (array $args) use ($connections): array {
                $connections->update((int) $args['connection_id'], ['status' => 'disabled']);

                return ['status' => 'disabled', 'connection_id' => $args['connection_id']];
            }
        );

        $registry->register(
            'list_domains',
            'List the current client\'s registered sending domains with DNS verification status.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function (): array {
                $repo = new DomainRepository(new EncryptionService($_ENV['APP_ENCRYPTION_KEY']));
                return ['domains' => array_map(
                    fn($d) => ['id' => $d['id'], 'domain' => $d['domain'], 'dns_verification_status' => $d['dns_verification_status']],
                    $repo->findAllWhere()
                )];
            }
        );

        $registry->register(
            'add_domain',
            'Register a new sending domain and generate its DKIM key pair. After this, use ' .
            'get_domain_dns_records to tell the client exactly what to publish, or apply_dns_records if they ' .
            'have Cloudflare linked for that domain.',
            [
                'type' => 'object',
                'properties' => ['domain' => ['type' => 'string', 'description' => 'e.g. mail.clientdomain.com']],
                'required' => ['domain'],
            ],
            function (array $args): array {
                $repo = new DomainRepository(new EncryptionService($_ENV['APP_ENCRYPTION_KEY']));
                $id = $repo->create(trim($args['domain']));

                return ['status' => 'created', 'domain_id' => $id, 'dns_records' => $repo->requiredDnsRecords($id)];
            }
        );

        $registry->register(
            'get_domain_dns_records',
            'Get the exact SPF/DKIM/DMARC DNS records a client needs to publish for a domain, to relay to them in chat.',
            ['type' => 'object', 'properties' => ['domain_id' => ['type' => 'integer']], 'required' => ['domain_id']],
            function (array $args): array {
                $repo = new DomainRepository(new EncryptionService($_ENV['APP_ENCRYPTION_KEY']));
                return ['dns_records' => $repo->requiredDnsRecords((int) $args['domain_id'])];
            }
        );

        $registry->register(
            'apply_dns_records',
            'Automatically publish the SPF/DKIM/DMARC records for a domain via its linked Cloudflare zone ' .
            '(only works if the client already linked Cloudflare for this domain from the dashboard — the ' .
            'API token itself is never entered through chat). Fails with a clear error if nothing is linked.',
            ['type' => 'object', 'properties' => ['domain_id' => ['type' => 'integer']], 'required' => ['domain_id']],
            function (array $args): array {
                $repo = new DomainRepository(new EncryptionService($_ENV['APP_ENCRYPTION_KEY']));
                $result = $repo->autoApplyDnsRecords((int) $args['domain_id']);

                return ['status' => 'applied', 'records' => $result];
            }
        );

        $registry->register(
            'verify_domain_dns',
            'Run a live SPF/DKIM/DMARC check for a domain and return pass/fail with specific issues found.',
            ['type' => 'object', 'properties' => ['domain_id' => ['type' => 'integer']], 'required' => ['domain_id']],
            function (array $args): array {
                $repo = new DomainRepository(new EncryptionService($_ENV['APP_ENCRYPTION_KEY']));
                return ['results' => $repo->verify((int) $args['domain_id'])];
            }
        );

        $registry->register(
            'list_contact_lists',
            'List the current client\'s contact lists with subscriber counts.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function (): array {
                $repo = new ContactListRepository();
                return ['lists' => array_map(
                    fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'contact_count' => $l['contact_count'] ?? null],
                    $repo->findAllWhere()
                )];
            }
        );

        $registry->register(
            'delete_contact_list',
            'Permanently delete a contact list and its contacts. Irreversible — this is a destructive tool ' .
            'that always requires human approval regardless of autonomy level.',
            ['type' => 'object', 'properties' => ['list_id' => ['type' => 'integer'], 'reason' => ['type' => 'string']], 'required' => ['list_id', 'reason']],
            function (array $args): array {
                $deleted = (new ContactListRepository())->delete((int) $args['list_id']);
                return ['status' => $deleted ? 'deleted' : 'not_found', 'list_id' => $args['list_id']];
            }
        );

        $registry->register(
            'create_campaign',
            'Create a draft campaign (not sent yet) from a template and a contact list. Use ' .
            'score_content_spam_risk on the template content first and mention the result to the client. ' .
            'Use send_campaign_now separately to actually send — that always requires approval.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'template_id' => ['type' => 'integer'],
                    'list_id' => ['type' => 'integer'],
                ],
                'required' => ['name', 'template_id', 'list_id'],
            ],
            function (array $args): array {
                $id = (new CampaignRepository())->create($args['name'], (int) $args['template_id'], (int) $args['list_id']);
                return ['status' => 'draft_created', 'campaign_id' => $id];
            }
        );

        $registry->register(
            'send_campaign_now',
            'Queue a draft campaign for immediate sending — expands it into individual sends across the ' .
            'client\'s connection pool. Irreversible once workers pick up the queue. Always requires human ' .
            'approval regardless of autonomy level.',
            [
                'type' => 'object',
                'properties' => [
                    'campaign_id' => ['type' => 'integer'],
                    'template_id' => ['type' => 'integer'],
                    'list_id' => ['type' => 'integer'],
                ],
                'required' => ['campaign_id', 'template_id', 'list_id'],
            ],
            function (array $args): array {
                $db = Database::connection();
                $stmt = $db->prepare('SELECT subject, html_body FROM email_templates WHERE id = :id AND client_id = :client_id');
                $stmt->execute(['id' => $args['template_id'], 'client_id' => ClientContext::clientId()]);
                $template = $stmt->fetch();
                if ($template === false) {
                    throw new \RuntimeException("Template {$args['template_id']} not found for this client.");
                }

                $enqueued = (new CampaignQueueingService($db))->enqueue(
                    (int) $args['campaign_id'], $template['subject'], $template['html_body'], (int) $args['list_id']
                );

                return ['status' => 'sending', 'campaign_id' => $args['campaign_id'], 'contacts_enqueued' => $enqueued];
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
