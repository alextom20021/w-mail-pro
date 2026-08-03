<?php

declare(strict_types=1);

namespace MailAI\AI;

use MailAI\Analytics\AnalyticsService;
use MailAI\Core\CampaignRepository;
use MailAI\Core\ClientContext;
use MailAI\Core\ContactListRepository;
use MailAI\Core\ContactRepository;
use MailAI\Core\Database;
use MailAI\Core\DomainRepository;
use MailAI\Security\EncryptionService;
use MailAI\Sending\CampaignQueueingService;
use MailAI\Sending\SendingConnectionRepository;
use MailAI\Webhooks\WebhookRepository;

/**
 * AIPlatformTools
 *
 * The second half of the agent's tool surface (the first half — warm-up,
 * quarantine, templates-create, spam scoring, list cleaning, connection/
 * domain provisioning — lives in AIToolHandlers). Together they cover
 * every management surface the dashboard exposes, so the agent can run
 * the ENTIRE platform for the client conversationally:
 *
 *   account   — get_account_overview
 *   templates — list_templates / get_template / update_template
 *   lists     — create_contact_list / add_contacts / get_list_contacts
 *   hygiene   — suppress_email / list_suppressions
 *   campaigns — list_campaigns / get_campaign_progress /
 *               schedule_campaign / pause_campaign / resume_campaign /
 *               cancel_campaign / send_ab_test_campaign
 *   sending   — resume_connection / get_warmup_history
 *   insight   — get_analytics
 *   webhooks  — list_webhooks / create_webhook / set_webhook_active
 *
 * Every handler goes through the same tenant-scoped repositories the
 * dashboard uses — the agent never gets raw DB access, and nothing here
 * bypasses ClientContext scoping or AIAgent's autonomy/destructive-tool
 * gating (schedule_campaign, cancel_campaign, send_ab_test_campaign are
 * in AIAgent::DESTRUCTIVE_TOOLS alongside send_campaign_now, because
 * each one commits or destroys real send volume).
 */
final class AIPlatformTools
{
    public static function registerAll(AIToolRegistry $registry, EncryptionService $encryption): void
    {
        self::registerAccountTools($registry, $encryption);
        self::registerTemplateTools($registry);
        self::registerListTools($registry);
        self::registerCampaignTools($registry);
        self::registerConnectionTools($registry, $encryption);
        self::registerAnalyticsTools($registry);
        self::registerWebhookTools($registry, $encryption);
    }

    // -----------------------------------------------------------------
    // Account
    // -----------------------------------------------------------------

    private static function registerAccountTools(AIToolRegistry $registry, EncryptionService $encryption): void
    {
        $registry->register(
            'get_account_overview',
            'Full snapshot of this client\'s account: connections (by status), domains (by verification), ' .
            'contact lists, campaigns (by status), sends in the last 24h, suppression count, and what\'s ' .
            'still missing for a working setup. Call this first when the client asks "where am I at", "what ' .
            'should I do next", or at the start of helping someone new — then propose the next concrete step.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function () use ($encryption): array {
                $db = Database::connection();
                $clientId = ClientContext::clientId();

                $one = static function (string $sql) use ($db, $clientId) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute(['client_id' => $clientId]);
                    return $stmt->fetchAll();
                };

                $connections = $one('SELECT status, COUNT(*) AS n FROM sending_connections WHERE client_id = :client_id GROUP BY status');
                $domains = $one('SELECT dns_verification_status, COUNT(*) AS n FROM domains WHERE client_id = :client_id GROUP BY dns_verification_status');
                $lists = $one('SELECT COUNT(*) AS lists, COALESCE(SUM(contact_count),0) AS contacts FROM contact_lists WHERE client_id = :client_id');
                $campaigns = $one('SELECT status, COUNT(*) AS n FROM campaigns WHERE client_id = :client_id GROUP BY status');
                $sends24h = $one("SELECT COUNT(*) AS n FROM outbox WHERE client_id = :client_id AND status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
                $suppressions = $one('SELECT COUNT(*) AS n FROM suppressions WHERE client_id = :client_id');

                $missing = [];
                if (array_sum(array_column($connections, 'n')) === 0) {
                    $missing[] = 'no sending connection yet — add one (SMTP or SendGrid/Mailgun/SES/Postmark)';
                }
                $verified = 0;
                foreach ($domains as $d) {
                    if (($d['dns_verification_status'] ?? '') === 'verified') {
                        $verified += (int) $d['n'];
                    }
                }
                if ($verified === 0) {
                    $missing[] = 'no DNS-verified sending domain yet';
                }
                if ((int) ($lists[0]['contacts'] ?? 0) === 0) {
                    $missing[] = 'no contacts imported yet';
                }

                return [
                    'connections_by_status' => $connections,
                    'domains_by_verification' => $domains,
                    'lists' => $lists[0] ?? ['lists' => 0, 'contacts' => 0],
                    'campaigns_by_status' => $campaigns,
                    'sent_last_24h' => (int) ($sends24h[0]['n'] ?? 0),
                    'suppression_count' => (int) ($suppressions[0]['n'] ?? 0),
                    'setup_gaps' => $missing,
                ];
            }
        );
    }

    // -----------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------

    private static function registerTemplateTools(AIToolRegistry $registry): void
    {
        $registry->register(
            'list_templates',
            'List this client\'s email templates (id, name, subject, spam score if scored) — use to find a ' .
            'template_id before creating/sending a campaign, or to see what content already exists.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function (): array {
                $db = Database::connection();
                $stmt = $db->prepare('SELECT id, name, subject, spam_score FROM email_templates WHERE client_id = :client_id ORDER BY id DESC LIMIT 100');
                $stmt->execute(['client_id' => ClientContext::clientId()]);

                return ['templates' => $stmt->fetchAll()];
            }
        );

        $registry->register(
            'get_template',
            'Fetch one template\'s full subject and HTML body — e.g. to review, improve, or spam-score it.',
            ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer']], 'required' => ['template_id']],
            function (array $args): array {
                $db = Database::connection();
                $stmt = $db->prepare('SELECT id, name, subject, html_body, spam_score FROM email_templates WHERE id = :id AND client_id = :client_id');
                $stmt->execute(['id' => (int) $args['template_id'], 'client_id' => ClientContext::clientId()]);
                $row = $stmt->fetch();
                if ($row === false) {
                    throw new \RuntimeException("Template {$args['template_id']} not found for this client.");
                }

                return ['template' => $row];
            }
        );

        $registry->register(
            'update_template',
            'Update a template\'s name, subject, and/or HTML body — e.g. after the client asked you to ' .
            'improve the copy or fix spam-risk issues. Re-run score_content_spam_risk on the new content ' .
            'and tell the client the before/after scores.',
            [
                'type' => 'object',
                'properties' => [
                    'template_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'subject' => ['type' => 'string'],
                    'html_body' => ['type' => 'string'],
                ],
                'required' => ['template_id'],
            ],
            function (array $args): array {
                $sets = [];
                $params = ['id' => (int) $args['template_id'], 'client_id' => ClientContext::clientId()];
                foreach (['name', 'subject', 'html_body'] as $field) {
                    if (isset($args[$field]) && $args[$field] !== '') {
                        $sets[] = "$field = :$field";
                        $params[$field] = $args[$field];
                    }
                }
                if ($sets === []) {
                    throw new \RuntimeException('Provide at least one of name, subject, html_body to update.');
                }

                $db = Database::connection();
                $stmt = $db->prepare('UPDATE email_templates SET ' . implode(', ', $sets) . ' WHERE id = :id AND client_id = :client_id');
                $stmt->execute($params);

                return ['status' => $stmt->rowCount() > 0 ? 'updated' : 'not_found_or_unchanged', 'template_id' => $args['template_id']];
            }
        );
    }

    // -----------------------------------------------------------------
    // Contact lists, contacts, suppression hygiene
    // -----------------------------------------------------------------

    private static function registerListTools(AIToolRegistry $registry): void
    {
        $registry->register(
            'create_contact_list',
            'Create a new, empty contact list. Follow up with add_contacts to put addresses in it.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ],
            function (array $args): array {
                $id = (new ContactListRepository())->create(trim($args['name']), $args['description'] ?? null);

                return ['status' => 'created', 'list_id' => $id];
            }
        );

        $registry->register(
            'add_contacts',
            'Add contacts to a list (up to 500 per call — for more, call repeatedly). Each contact needs an ' .
            'email; first_name/last_name are optional. Invalid addresses, duplicates already on the list, ' .
            'and suppressed addresses are skipped automatically and reported back, never silently dropped.',
            [
                'type' => 'object',
                'properties' => [
                    'list_id' => ['type' => 'integer'],
                    'contacts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'email' => ['type' => 'string'],
                                'first_name' => ['type' => 'string'],
                                'last_name' => ['type' => 'string'],
                            ],
                            'required' => ['email'],
                        ],
                    ],
                ],
                'required' => ['list_id', 'contacts'],
            ],
            function (array $args): array {
                $listRepo = new ContactListRepository();
                if ($listRepo->find((int) $args['list_id']) === null) {
                    throw new \RuntimeException("List {$args['list_id']} not found for this client.");
                }

                $contacts = array_slice($args['contacts'], 0, 500);
                $repo = new ContactRepository();
                $counts = ['inserted' => 0, 'duplicate' => 0, 'suppressed' => 0, 'invalid' => 0];

                foreach ($contacts as $contact) {
                    $extra = array_filter([
                        'first_name' => $contact['first_name'] ?? null,
                        'last_name' => $contact['last_name'] ?? null,
                    ], static fn($v) => $v !== null && $v !== '');
                    $result = $repo->importCsvRow((int) $args['list_id'], (string) $contact['email'], $extra);
                    $counts[$result] = ($counts[$result] ?? 0) + 1;
                }

                $listRepo->refreshContactCount((int) $args['list_id']);

                return ['status' => 'done', 'list_id' => $args['list_id'], 'results' => $counts];
            }
        );

        $registry->register(
            'get_list_contacts',
            'Peek at a contact list: total subscribed count plus a sample of contacts (max 50 per call, ' .
            'use offset to page). Use this to sanity-check an import or answer "who is on this list?".',
            [
                'type' => 'object',
                'properties' => [
                    'list_id' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
                'required' => ['list_id'],
            ],
            function (array $args): array {
                $db = Database::connection();
                $stmt = $db->prepare("SELECT COUNT(*) FROM contacts WHERE client_id = :client_id AND list_id = :list_id AND status = 'subscribed'");
                $stmt->execute(['client_id' => ClientContext::clientId(), 'list_id' => (int) $args['list_id']]);
                $total = (int) $stmt->fetchColumn();

                $sample = (new ContactRepository())->findSubscribed((int) $args['list_id'], 50, (int) ($args['offset'] ?? 0));

                return [
                    'total_subscribed' => $total,
                    'sample' => array_map(
                        fn($c) => ['id' => $c['id'], 'email' => $c['email'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'engagement_score' => $c['engagement_score'] ?? null],
                        $sample
                    ),
                ];
            }
        );

        $registry->register(
            'suppress_email',
            'Add an email address to this client\'s suppression list so it is never mailed again (checked at ' .
            'import AND again at send time). Use when the client says "never email this address again" or ' .
            'when hygiene demands it. There is deliberately NO unsuppress tool — removing a suppression can ' .
            'violate an unsubscribe and must be done by a human from the dashboard.',
            [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string'],
                    'detail' => ['type' => 'string', 'description' => 'Why — recorded for the audit trail'],
                ],
                'required' => ['email', 'detail'],
            ],
            function (array $args): array {
                $email = trim(strtolower((string) $args['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException("'$email' is not a valid email address.");
                }

                $db = Database::connection();
                $stmt = $db->prepare(
                    "INSERT INTO suppressions (client_id, email, reason, source_detail, created_at)
                     VALUES (:client_id, :email, 'manual', :detail, NOW())
                     ON DUPLICATE KEY UPDATE source_detail = VALUES(source_detail)"
                );
                $stmt->execute(['client_id' => ClientContext::clientId(), 'email' => $email, 'detail' => mb_substr((string) $args['detail'], 0, 255)]);

                return ['status' => 'suppressed', 'email' => $email];
            }
        );

        $registry->register(
            'list_suppressions',
            'Total suppression count broken down by reason (unsubscribe / hard_bounce / complaint / manual / ' .
            'ai_list_clean) plus the most recent entries.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function (): array {
                $db = Database::connection();
                $clientId = ClientContext::clientId();

                $byReason = $db->prepare('SELECT reason, COUNT(*) AS n FROM suppressions WHERE client_id = :client_id GROUP BY reason');
                $byReason->execute(['client_id' => $clientId]);

                $recent = $db->prepare('SELECT email, reason, source_detail, created_at FROM suppressions WHERE client_id = :client_id ORDER BY id DESC LIMIT 25');
                $recent->execute(['client_id' => $clientId]);

                return ['by_reason' => $byReason->fetchAll(), 'recent' => $recent->fetchAll()];
            }
        );
    }

    // -----------------------------------------------------------------
    // Campaign lifecycle
    // -----------------------------------------------------------------

    private static function registerCampaignTools(AIToolRegistry $registry): void
    {
        $registry->register(
            'list_campaigns',
            'List this client\'s campaigns with status and send progress (queued/paused/sent/failed counts ' .
            'from the outbox). Use to get real campaign_id values and to answer "how did my campaign do?".',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function (): array {
                $db = Database::connection();
                $stmt = $db->prepare(
                    "SELECT c.id, c.name, c.status, c.template_id, c.list_id, c.ab_test_enabled, c.created_at,
                            SUM(o.status = 'queued') AS queued, SUM(o.status = 'paused') AS paused,
                            SUM(o.status = 'sent') AS sent, SUM(o.status IN ('failed','hard_bounced')) AS failed
                     FROM campaigns c
                     LEFT JOIN outbox o ON o.campaign_id = c.id AND o.client_id = c.client_id
                     WHERE c.client_id = :client_id
                     GROUP BY c.id ORDER BY c.id DESC LIMIT 50"
                );
                $stmt->execute(['client_id' => ClientContext::clientId()]);

                return ['campaigns' => $stmt->fetchAll()];
            }
        );

        $registry->register(
            'get_campaign_progress',
            'Detailed live progress for one campaign: outbox counts by status, open/click counts, and A/B ' .
            'variant performance + winner if it was an A/B test.',
            ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'integer']], 'required' => ['campaign_id']],
            function (array $args): array {
                $campaign = (new CampaignRepository())->find((int) $args['campaign_id']);
                if ($campaign === null) {
                    throw new \RuntimeException("Campaign {$args['campaign_id']} not found for this client.");
                }

                $db = Database::connection();
                $clientId = ClientContext::clientId();

                $byStatus = $db->prepare('SELECT status, COUNT(*) AS n FROM outbox WHERE client_id = :client_id AND campaign_id = :campaign_id GROUP BY status');
                $byStatus->execute(['client_id' => $clientId, 'campaign_id' => (int) $args['campaign_id']]);

                $variants = $db->prepare('SELECT variant_label, traffic_percent, sent_count, opened_count, clicked_count, is_winner FROM campaign_variants WHERE client_id = :client_id AND campaign_id = :campaign_id');
                $variants->execute(['client_id' => $clientId, 'campaign_id' => (int) $args['campaign_id']]);

                return [
                    'campaign' => ['id' => $campaign['id'], 'name' => $campaign['name'], 'status' => $campaign['status'], 'ab_test_enabled' => $campaign['ab_test_enabled'] ?? 0],
                    'outbox_by_status' => $byStatus->fetchAll(),
                    'ab_variants' => $variants->fetchAll(),
                ];
            }
        );

        $registry->register(
            'schedule_campaign',
            'Queue a campaign to send at a specific future date/time (UTC, format YYYY-MM-DD HH:MM:SS). Like ' .
            'send_campaign_now but deferred — workers pick it up when the time arrives. Commits real send ' .
            'volume, so it always requires human approval regardless of autonomy level.',
            [
                'type' => 'object',
                'properties' => [
                    'campaign_id' => ['type' => 'integer'],
                    'template_id' => ['type' => 'integer'],
                    'list_id' => ['type' => 'integer'],
                    'send_at' => ['type' => 'string', 'description' => 'UTC datetime, YYYY-MM-DD HH:MM:SS, must be in the future'],
                ],
                'required' => ['campaign_id', 'template_id', 'list_id', 'send_at'],
            ],
            function (array $args): array {
                $ts = strtotime($args['send_at'] . ' UTC');
                if ($ts === false || $ts <= time()) {
                    throw new \RuntimeException("send_at must be a valid future UTC datetime (got '{$args['send_at']}').");
                }

                $db = Database::connection();
                $stmt = $db->prepare('SELECT subject, html_body FROM email_templates WHERE id = :id AND client_id = :client_id');
                $stmt->execute(['id' => (int) $args['template_id'], 'client_id' => ClientContext::clientId()]);
                $template = $stmt->fetch();
                if ($template === false) {
                    throw new \RuntimeException("Template {$args['template_id']} not found for this client.");
                }

                $enqueued = (new CampaignQueueingService($db))->enqueue(
                    (int) $args['campaign_id'],
                    $template['subject'],
                    $template['html_body'],
                    (int) $args['list_id'],
                    gmdate('Y-m-d H:i:s', $ts)
                );

                return ['status' => 'scheduled', 'campaign_id' => $args['campaign_id'], 'send_at_utc' => gmdate('Y-m-d H:i:s', $ts), 'contacts_enqueued' => $enqueued];
            }
        );

        $registry->register(
            'pause_campaign',
            'Pause a campaign that is queued or mid-send: every not-yet-sent email is held (already-sent ones ' .
            'are already gone). Reversible with resume_campaign — safe to do immediately when a client says ' .
            '"stop/hold my campaign".',
            ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'integer']], 'required' => ['campaign_id']],
            function (array $args): array {
                return self::transitionCampaign((int) $args['campaign_id'], "status = 'paused'", "status = 'queued'", 'paused');
            }
        );

        $registry->register(
            'resume_campaign',
            'Resume a paused campaign — held emails go back into the queue and workers continue sending.',
            ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'integer']], 'required' => ['campaign_id']],
            function (array $args): array {
                return self::transitionCampaign((int) $args['campaign_id'], "status = 'queued'", "status = 'paused'", 'sending');
            }
        );

        $registry->register(
            'cancel_campaign',
            'Permanently cancel every not-yet-sent email in a campaign. Cannot be undone (a new campaign ' .
            'would have to be created to send again), so it always requires human approval.',
            ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'integer'], 'reason' => ['type' => 'string']], 'required' => ['campaign_id', 'reason']],
            function (array $args): array {
                return self::transitionCampaign((int) $args['campaign_id'], "status = 'cancelled'", "status IN ('queued','paused')", 'cancelled');
            }
        );

        $registry->register(
            'send_ab_test_campaign',
            'Send a campaign as an A/B test: provide 2+ variants (each with its own template_id, a label like ' .
            '"A"/"B", traffic_percent summing to 100, optional subject_override). The list is split ' .
            'deterministically, and the platform auto-picks a winner by open rate once results are in ' .
            '(ai_cycle runs every minute). Commits real send volume — always requires human approval.',
            [
                'type' => 'object',
                'properties' => [
                    'campaign_id' => ['type' => 'integer'],
                    'list_id' => ['type' => 'integer'],
                    'variants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'template_id' => ['type' => 'integer'],
                                'traffic_percent' => ['type' => 'integer'],
                                'subject_override' => ['type' => 'string'],
                            ],
                            'required' => ['label', 'template_id', 'traffic_percent'],
                        ],
                    ],
                ],
                'required' => ['campaign_id', 'list_id', 'variants'],
            ],
            function (array $args): array {
                if (count($args['variants']) < 2) {
                    throw new \RuntimeException('An A/B test needs at least 2 variants.');
                }
                if (array_sum(array_column($args['variants'], 'traffic_percent')) !== 100) {
                    throw new \RuntimeException('traffic_percent across variants must sum to exactly 100.');
                }

                $db = Database::connection();
                $clientId = ClientContext::clientId();
                $campaignId = (int) $args['campaign_id'];

                if ((new CampaignRepository())->find($campaignId) === null) {
                    throw new \RuntimeException("Campaign $campaignId not found for this client.");
                }

                $fullVariants = [];
                foreach ($args['variants'] as $v) {
                    $stmt = $db->prepare('SELECT subject, html_body FROM email_templates WHERE id = :id AND client_id = :client_id');
                    $stmt->execute(['id' => (int) $v['template_id'], 'client_id' => $clientId]);
                    $template = $stmt->fetch();
                    if ($template === false) {
                        throw new \RuntimeException("Template {$v['template_id']} (variant {$v['label']}) not found for this client.");
                    }

                    $subject = ($v['subject_override'] ?? '') !== '' ? $v['subject_override'] : $template['subject'];

                    $ins = $db->prepare(
                        'INSERT INTO campaign_variants (client_id, campaign_id, variant_label, template_id, subject_override, traffic_percent, created_at)
                         VALUES (:client_id, :campaign_id, :label, :template_id, :subject_override, :traffic_percent, NOW())'
                    );
                    $ins->execute([
                        'client_id' => $clientId,
                        'campaign_id' => $campaignId,
                        'label' => mb_substr((string) $v['label'], 0, 10),
                        'template_id' => (int) $v['template_id'],
                        'subject_override' => ($v['subject_override'] ?? '') !== '' ? $v['subject_override'] : null,
                        'traffic_percent' => (int) $v['traffic_percent'],
                    ]);

                    $fullVariants[] = [
                        'id' => (int) $db->lastInsertId(),
                        'template_id' => (int) $v['template_id'],
                        'subject' => $subject,
                        'html_body' => $template['html_body'],
                        'traffic_percent' => (int) $v['traffic_percent'],
                    ];
                }

                $db->prepare('UPDATE campaigns SET ab_test_enabled = 1 WHERE id = :id AND client_id = :client_id')
                    ->execute(['id' => $campaignId, 'client_id' => $clientId]);

                $enqueued = (new CampaignQueueingService($db))->enqueueAbTest($campaignId, $fullVariants, (int) $args['list_id']);

                return ['status' => 'ab_test_sending', 'campaign_id' => $campaignId, 'variants' => count($fullVariants), 'contacts_enqueued' => $enqueued];
            }
        );
    }

    // -----------------------------------------------------------------
    // Connections (the lifecycle pieces AIToolHandlers doesn't cover)
    // -----------------------------------------------------------------

    private static function registerConnectionTools(AIToolRegistry $registry, EncryptionService $encryption): void
    {
        $registry->register(
            'resume_connection',
            'Re-activate a disabled or quarantined sending connection. For a quarantined one, first check ' .
            'get_deliverability_summary — if the complaint/bounce spike that caused the quarantine hasn\'t ' .
            'cleared, tell the client resuming is a bad idea before doing it.',
            ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer'], 'reason' => ['type' => 'string']], 'required' => ['connection_id', 'reason']],
            function (array $args) use ($encryption): array {
                $repo = new SendingConnectionRepository($encryption);
                $conn = $repo->find((int) $args['connection_id']);
                if ($conn === null) {
                    throw new \RuntimeException("Connection {$args['connection_id']} not found for this client.");
                }
                $repo->update((int) $args['connection_id'], ['status' => 'active', 'quarantine_reason' => null]);

                return ['status' => 'active', 'connection_id' => $args['connection_id'], 'previous_status' => $conn['status']];
            }
        );

        $registry->register(
            'get_warmup_history',
            'Warm-up ramp history for a connection: every daily-limit adjustment, who/what made it (AI or ' .
            'schedule), and why. Use to answer "why is my sending volume still low?".',
            ['type' => 'object', 'properties' => ['connection_id' => ['type' => 'integer']], 'required' => ['connection_id']],
            function (array $args): array {
                $db = Database::connection();
                $stmt = $db->prepare(
                    'SELECT day_number, target_volume, ai_adjusted, adjustment_reason, applies_on
                     FROM warmup_schedules WHERE client_id = :client_id AND connection_id = :connection_id
                     ORDER BY applies_on DESC, id DESC LIMIT 60'
                );
                $stmt->execute(['client_id' => ClientContext::clientId(), 'connection_id' => (int) $args['connection_id']]);

                return ['history' => $stmt->fetchAll()];
            }
        );
    }

    // -----------------------------------------------------------------
    // Analytics
    // -----------------------------------------------------------------

    private static function registerAnalyticsTools(AIToolRegistry $registry): void
    {
        $registry->register(
            'get_analytics',
            'Pull real analytics for this client. report is one of: isp (per mailbox provider), country, ' .
            'connection (per sending connection), timeseries (daily totals), failures (bounce/failure reason ' .
            'breakdown). Never estimate or invent numbers — if a metric isn\'t in the result, say it isn\'t tracked.',
            [
                'type' => 'object',
                'properties' => [
                    'report' => ['type' => 'string', 'enum' => ['isp', 'country', 'connection', 'timeseries', 'failures']],
                    'days' => ['type' => 'integer', 'description' => '1-365, default 30'],
                ],
                'required' => ['report'],
            ],
            function (array $args): array {
                $service = new AnalyticsService(Database::connection());
                $days = max(1, min(365, (int) ($args['days'] ?? 30)));

                $rows = match ($args['report']) {
                    'isp' => $service->byIsp($days),
                    'country' => $service->byCountry($days),
                    'connection' => $service->byConnection($days),
                    'timeseries' => $service->timeSeries($days),
                    'failures' => $service->failureReasonBreakdown($days),
                    default => throw new \RuntimeException("Unknown report '{$args['report']}'."),
                };

                return ['report' => $args['report'], 'days' => $days, 'rows' => $rows];
            }
        );
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    private static function registerWebhookTools(AIToolRegistry $registry, EncryptionService $encryption): void
    {
        $registry->register(
            'list_webhooks',
            'List this client\'s webhook subscriptions (id, url, events, active) — real-time event streaming ' .
            'to the client\'s own systems.',
            ['type' => 'object', 'properties' => [], 'required' => []],
            function () use ($encryption): array {
                $repo = new WebhookRepository($encryption);

                return ['webhooks' => array_map(
                    fn($w) => ['id' => $w['id'], 'url' => $w['url'], 'events' => json_decode((string) $w['events'], true), 'is_active' => (bool) $w['is_active']],
                    $repo->findAllWhere()
                )];
            }
        );

        $registry->register(
            'create_webhook',
            'Create a webhook subscription POSTing signed events to the client\'s https:// endpoint. Valid ' .
            'events: send, open, click, bounce, complaint. The response includes the HMAC signing secret — ' .
            'relay it to the client verbatim and tell them it is shown exactly once.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'https:// endpoint on the client\'s side'],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => WebhookRepository::VALID_EVENTS]],
                ],
                'required' => ['url', 'events'],
            ],
            function (array $args) use ($encryption): array {
                $created = (new WebhookRepository($encryption))->create((string) $args['url'], $args['events']);

                return ['status' => 'created', 'webhook_id' => $created['id'], 'signing_secret_shown_once' => $created['secret']];
            }
        );

        $registry->register(
            'set_webhook_active',
            'Pause (active=false) or resume (active=true) an existing webhook subscription.',
            [
                'type' => 'object',
                'properties' => ['webhook_id' => ['type' => 'integer'], 'active' => ['type' => 'boolean']],
                'required' => ['webhook_id', 'active'],
            ],
            function (array $args) use ($encryption): array {
                $ok = (new WebhookRepository($encryption))->setActive((int) $args['webhook_id'], (bool) $args['active']);

                return ['status' => $ok ? ((bool) $args['active'] ? 'active' : 'paused') : 'not_found', 'webhook_id' => $args['webhook_id']];
            }
        );
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /** queued<->paused<->cancelled transitions on a campaign's outbox rows, tenant-scoped, campaign status kept in sync. */
    private static function transitionCampaign(int $campaignId, string $setClause, string $whereClause, string $campaignStatus): array
    {
        if ((new CampaignRepository())->find($campaignId) === null) {
            throw new \RuntimeException("Campaign $campaignId not found for this client.");
        }

        $db = Database::connection();
        $clientId = ClientContext::clientId();

        $stmt = $db->prepare(
            "UPDATE outbox SET $setClause WHERE client_id = :client_id AND campaign_id = :campaign_id AND $whereClause"
        );
        $stmt->execute(['client_id' => $clientId, 'campaign_id' => $campaignId]);
        $affected = $stmt->rowCount();

        $db->prepare('UPDATE campaigns SET status = :status WHERE id = :id AND client_id = :client_id')
            ->execute(['status' => $campaignStatus, 'id' => $campaignId, 'client_id' => $clientId]);

        return ['status' => $campaignStatus, 'campaign_id' => $campaignId, 'emails_affected' => $affected];
    }
}
