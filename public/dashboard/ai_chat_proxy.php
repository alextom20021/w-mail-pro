<?php

declare(strict_types=1);

/**
 * public/dashboard/ai_chat_proxy.php
 *
 * Session-authenticated bridge between the dashboard's AI chat widget
 * and AiChatController. Calls the controller class directly (not over
 * HTTP through /api/v1) since the dashboard already has a trusted
 * session — no need to round-trip through Bearer-token auth for
 * same-origin, cookie-authenticated requests.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Api\Controllers\AiChatController;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);

(new AiChatController($encryption))->sendMessage($body);
