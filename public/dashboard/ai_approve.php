<?php

declare(strict_types=1);

/**
 * public/dashboard/ai_approve.php
 *
 * Session-authenticated "Approve" / "Reject" action for AI tool calls
 * the agent queued for human confirmation (suggest_only/approve_required
 * autonomy, or any AIAgent::DESTRUCTIVE_TOOLS call regardless of
 * autonomy level). This is the other half of the "client confirms, agent
 * works" loop: the chat UI shows a pending action, the client clicks
 * Approve, this endpoint re-validates it belongs to their own client_id
 * (never trust the audit_id alone) and only then actually executes it
 * via AIAgent::executeApproved.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\AI\AIAgent;
use MailAI\AI\AIAuditLogger;
use MailAI\AI\AIProviderFactory;
use MailAI\AI\AIToolHandlers;
use MailAI\AI\AIToolRegistry;
use MailAI\Core\ClientContext;
use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;
use MailAI\Sending\SendingConnectionRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

$clientId = SessionAuth::requireClient();

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$submittedCsrf = $body['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $submittedCsrf)) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid or expired form token — refresh and try again.']);
    exit;
}

$auditId = (int) ($body['audit_id'] ?? 0);
$action = $body['action'] ?? ''; // 'approve' | 'reject'

$db = Database::connection();
$logger = new AIAuditLogger($db);

// Re-fetch the audit row ourselves — never trust tool/arguments passed
// back from the browser, and confirm this audit entry actually belongs
// to the logged-in client before touching it.
$stmt = $db->prepare('SELECT * FROM ai_audit_log WHERE id = :id AND client_id = :client_id AND status = \'proposed\' LIMIT 1');
$stmt->execute(['id' => $auditId, 'client_id' => $clientId]);
$row = $stmt->fetch();

if ($row === false) {
    http_response_code(404);
    echo json_encode(['error' => 'No pending approval found with that id for your account.']);
    exit;
}

if ($action === 'reject') {
    $logger->markRejected($auditId);
    echo json_encode(['status' => 'rejected', 'audit_id' => $auditId]);
    exit;
}

if ($action !== 'approve') {
    http_response_code(422);
    echo json_encode(['error' => 'action must be "approve" or "reject"']);
    exit;
}

$logger->markApproved($auditId, $clientId);

ClientContext::setClient($clientId);

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$registry = new AIToolRegistry();
AIToolHandlers::registerAll($registry, new SendingConnectionRepository($encryption));
\MailAI\AI\AIPlatformTools::registerAll($registry, $encryption);

$provider = AIProviderFactory::fromDatabase($encryption);
$agent = new AIAgent($provider, $registry, $logger);

$arguments = json_decode($row['input_json'], true) ?? [];
$result = $agent->executeApproved($auditId, $row['tool_name'], $arguments);

echo json_encode(array_merge($result, ['audit_id' => $auditId]));
