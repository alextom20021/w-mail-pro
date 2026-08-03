<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\AI\AIAgent;
use MailAI\AI\AIAuditLogger;
use MailAI\AI\AIProviderFactory;
use MailAI\AI\AIPlatformTools;
use MailAI\AI\AIToolHandlers;
use MailAI\AI\AIToolRegistry;
use MailAI\Api\JsonResponse;
use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Security\EncryptionService;
use MailAI\Sending\SendingConnectionRepository;

/**
 * AiChatController
 *
 * The "AI Chat Assistant" endpoint — a client sends a message, the
 * agent replies and/or proposes tool calls, gated by the client's
 * configured `ai_autonomy_level`. Conversation history persists in
 * `ai_conversations`/`ai_messages` so context carries across requests.
 */
final class AiChatController
{
    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function sendMessage(array $body): void
    {
        if (empty($body['message'])) {
            JsonResponse::error('Missing required field: message', 422);
            return;
        }

        $db = Database::connection();
        $clientId = ClientContext::clientId();

        $conversationId = isset($body['conversation_id'])
            ? (int) $body['conversation_id']
            : $this->createConversation($db, $clientId, $body['message']);

        $this->appendMessage($db, $conversationId, 'user', $body['message']);

        $autonomy = $this->clientAutonomyLevel($db, $clientId);
        $history = $this->loadHistory($db, $conversationId);

        $registry = new AIToolRegistry();
        AIToolHandlers::registerAll($registry, new SendingConnectionRepository($this->encryption));
        AIPlatformTools::registerAll($registry, $this->encryption);

        $provider = AIProviderFactory::fromDatabase($this->encryption);
        $agent = new AIAgent($provider, $registry, new AIAuditLogger($db));

        $systemPrompt = [
            'role' => 'system',
            'content' => 'You are the MailAI account manager — an AI agent that RUNS this client\'s entire ' .
                'email deliverability operation for them. The client works with you through prompts; you do ' .
                'the actual work with your tools. You can manage everything they could do from the dashboard: ' .
                'sending connections (add SMTP/SendGrid/Mailgun/SES/Postmark, pause, resume, warm-up ramps, ' .
                'quarantine), domains (add, DKIM/SPF/DMARC records, auto-apply via Cloudflare, verify), ' .
                'templates (create, review, improve, update, spam-score), contact lists (create, add contacts, ' .
                'inspect, AI-clean, suppress addresses), campaigns (create drafts, send now, schedule for ' .
                'later, pause, resume, cancel, full A/B tests with automatic winner selection), analytics ' .
                '(per-ISP, per-country, per-connection, time series, failure reasons), and webhooks. ' .
                "\n\nHow you work: (1) understand what the client wants; (2) look up real state first — " .
                'get_account_overview for the big picture, list_connections/list_domains/list_contact_lists/' .
                'list_templates/list_campaigns for real IDs — never ask the client for an ID and never guess ' .
                'one; (3) gather missing details conversationally (credentials, domain names, send times); ' .
                '(4) state exactly what you are about to do and get their OK in chat; (5) call the tool; (6) ' .
                'report what actually happened, including failures, honestly. For a brand-new client, ' .
                'proactively drive onboarding: connection -> domain + DNS -> verify -> import contacts -> ' .
                'first campaign, one step at a time. ' .
                "\n\nHard rules: never fabricate statistics — only report numbers a tool returned. " .
                'High-blast-radius actions (send_campaign_now, schedule_campaign, send_ab_test_campaign, ' .
                'cancel_campaign, disable_connection, delete_contact_list) ALWAYS pause for the client\'s ' .
                'explicit Approve click regardless of autonomy settings — when that happens, say plainly the ' .
                'action is pending their approval, never imply it already ran. Credentials the client pastes ' .
                'are encrypted at rest and never shown back. Be concise, specific, and act like the ' .
                'competent operator of their account, not a chatbot.',
        ];

        $result = $agent->runTurn(array_merge([$systemPrompt], $history), $clientId, $autonomy);

        $this->appendMessage($db, $conversationId, 'assistant', $result->finalText);

        JsonResponse::ok([
            'conversation_id' => $conversationId,
            'reply' => $result->finalText,
            'executed_actions' => $result->executedActions,
            'pending_approvals' => $result->pendingApprovals,
            'provider' => $result->provider,
        ]);
    }

    private function createConversation(\PDO $db, int $clientId, string $firstMessage): int
    {
        $stmt = $db->prepare('INSERT INTO ai_conversations (client_id, title) VALUES (:client_id, :title)');
        $stmt->execute(['client_id' => $clientId, 'title' => mb_substr($firstMessage, 0, 80)]);

        return (int) $db->lastInsertId();
    }

    private function appendMessage(\PDO $db, int $conversationId, string $role, string $content): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ai_messages (conversation_id, role, content) VALUES (:conversation_id, :role, :content)'
        );
        $stmt->execute(['conversation_id' => $conversationId, 'role' => $role, 'content' => $content]);
    }

    private function loadHistory(\PDO $db, int $conversationId): array
    {
        $stmt = $db->prepare(
            'SELECT role, content FROM ai_messages WHERE conversation_id = :id ORDER BY id ASC LIMIT 50'
        );
        $stmt->execute(['id' => $conversationId]);

        return $stmt->fetchAll();
    }

    private function clientAutonomyLevel(\PDO $db, int $clientId): string
    {
        $stmt = $db->prepare('SELECT ai_autonomy_level FROM clients WHERE id = :id');
        $stmt->execute(['id' => $clientId]);

        return $stmt->fetchColumn() ?: 'suggest_only';
    }
}
