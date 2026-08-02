<?php

declare(strict_types=1);

/**
 * public/api/v1/index.php
 *
 * REST API v1 front controller. All requests to /api/v1/* route through
 * here. Handles: Bearer auth, per-client rate limiting, routing, and a
 * uniform error envelope. Every request is logged to api_request_log
 * (method/path/status only — never headers or body, since Authorization
 * carries the API key).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use MailAI\Api\ApiAuthenticator;
use MailAI\Api\Controllers\AiChatController;
use MailAI\Api\Controllers\AnalyticsController;
use MailAI\Api\Controllers\CampaignsController;
use MailAI\Api\Controllers\ConnectionsController;
use MailAI\Api\Controllers\DomainsController;
use MailAI\Api\Controllers\ListsController;
use MailAI\Api\Controllers\SuppressionsController;
use MailAI\Api\JsonResponse;
use MailAI\Api\RateLimiter;
use MailAI\Api\Router;
use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Security\EncryptionService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../..');
$dotenv->safeLoad();

$db = Database::connection();
$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);

// --- Auth --------------------------------------------------------------
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$auth = (new ApiAuthenticator($db))->authenticate($authHeader);

if ($auth === null) {
    JsonResponse::error('Unauthorized — missing or invalid Bearer token.', 401);
    exit;
}

// --- Rate limiting -------------------------------------------------------
// 120 req/min is a reasonable default for a per-client API key; make this
// plan-dependent (clients.plan) in Phase 2 rather than a flat constant.
$rate = (new RateLimiter($db))->check($auth['client_id'], 120);
header("X-RateLimit-Limit: {$rate['limit']}");
header("X-RateLimit-Remaining: {$rate['remaining']}");
header("X-RateLimit-Reset: {$rate['reset_at']}");

if (!$rate['allowed']) {
    JsonResponse::error('Rate limit exceeded.', 429);
    exit;
}

// --- Parse body ----------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'] ?? '/';
$rawBody = file_get_contents('php://input');
$body = $rawBody ? (json_decode($rawBody, true) ?? []) : [];

// --- Routes ----------------------------------------------------------------
$router = new Router();

$connections = new ConnectionsController($encryption);
$router->add('GET', '/api/v1/connections', fn() => $connections->index());
$router->add('POST', '/api/v1/connections', fn() => $connections->create($body));
$router->add('GET', '/api/v1/connections/{id}', fn($p) => $connections->show($p));
$router->add('DELETE', '/api/v1/connections/{id}', fn($p) => $connections->delete($p));

$domains = new DomainsController($encryption);
$router->add('GET', '/api/v1/domains', fn() => $domains->index());
$router->add('POST', '/api/v1/domains', fn() => $domains->create($body));
$router->add('POST', '/api/v1/domains/{id}/verify', fn($p) => $domains->verify($p));

$lists = new ListsController();
$router->add('GET', '/api/v1/lists', fn() => $lists->index());
$router->add('POST', '/api/v1/lists', fn() => $lists->create($body));
$router->add('POST', '/api/v1/lists/{id}/contacts', fn($p) => $lists->importContacts($p, $body));

$campaigns = new CampaignsController();
$router->add('GET', '/api/v1/campaigns', fn() => $campaigns->index());
$router->add('POST', '/api/v1/campaigns', fn() => $campaigns->create($body));
$router->add('POST', '/api/v1/campaigns/{id}/send', fn($p) => $campaigns->send($p));

$analytics = new AnalyticsController();
$router->add('GET', '/api/v1/analytics/isp', fn() => $analytics->byIsp());
$router->add('GET', '/api/v1/analytics/country', fn() => $analytics->byCountry());
$router->add('GET', '/api/v1/analytics/connections', fn() => $analytics->byConnection());
$router->add('GET', '/api/v1/analytics/timeseries', fn() => $analytics->timeSeries());
$router->add('GET', '/api/v1/analytics/failures', fn() => $analytics->failures());

$suppressions = new SuppressionsController();
$router->add('GET', '/api/v1/suppressions', fn() => $suppressions->index());
$router->add('POST', '/api/v1/suppressions', fn() => $suppressions->create($body));

$aiChat = new AiChatController($encryption);
$router->add('POST', '/api/v1/ai/chat', fn() => $aiChat->sendMessage($body));

// --- Dispatch ----------------------------------------------------------------
$match = $router->match($method, $path);
$statusForLog = 200;

try {
    if ($match === null) {
        JsonResponse::error('Not found', 404);
    } else {
        ($match['handler'])($match['params']);
    }
} catch (\Throwable $e) {
    error_log('[api/v1] ' . $e->getMessage());
    JsonResponse::error('Internal server error', 500);
} finally {
    // Read back whatever status code the controller/JsonResponse actually
    // set (http_response_code() with no args returns the current value)
    // rather than assuming 200 — controllers return 201/404/422/etc.
    $statusForLog = http_response_code() ?: 500;
    logRequest($db, $auth['client_id'], $method, $path, $statusForLog);
    ClientContext::clear();
}

function logRequest(\PDO $db, int $clientId, string $method, string $path, int $status): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO api_request_log (client_id, method, path, status_code, ip_address)
             VALUES (:client_id, :method, :path, :status, :ip)'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'method' => $method,
            'path' => parse_url($path, PHP_URL_PATH) ?? $path,
            'status' => $status,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[api/v1] request log failed: ' . $e->getMessage());
    }
}
