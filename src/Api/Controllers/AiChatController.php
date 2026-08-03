<?php

declare(strict_types=1);

namespace MailAI\Api\Controllers;

use MailAI\AI\AIAgent;
use MailAI\AI\AIAuditLogger;
use MailAI\AI\AIProviderFactory;
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

        $provider = AIProviderFactory::fromDatabase($this->encryption);
        $agent = new AIAgent($provider, $registry, new AIAuditLogger($db));

        $systemPrompt = [
            'role' => 'system',
            'content' => 'You are the MailAI platform assistant — you do the setup and management work for ' .
                'this client\'s email deliverability account, not just answer questions about it. When a client ' .
                'wants something done (connect a sending provider, add a domain, clean a list, launch a ' .
                'campaign), gather whatever structured details you need through normal conversation (e.g. ' .
                '"what\'s your SendGrid API key?", "which domain?"), summarize what you\'re about to do, and ' .
                'only then call the tool. Use list_connections/list_domains/list_contact_lists to look up real ' .
                'IDs instead of asking the client to know them. Use get_deliverability_summary for real data — ' .
                'never fabricate statistics. Some actions (sending a campaign, disabling a connection, deleting ' .
                'a list) always pause for the client\'s explicit approval regardless of settings — when that ' .
                'happens, say plainly that it is pending approval and why, don\'t imply it already happened. Be ' .
                'concise and specific.',
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
