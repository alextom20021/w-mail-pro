<?php

declare(strict_types=1);

/**
 * public/track/click.php
 *
 * Click redirector: /track/click.php?t=TOKEN&u=BASE64_DESTINATION_URL
 * Records the click, then 302-redirects to the original link.
 *
 * SECURITY: the destination URL is cross-checked against
 * campaign_links — the set of URLs LinkRewriter actually found and
 * registered for this campaign at queue time (see
 * CampaignQueueingService/LinkRewriter). A valid signed token alone is
 * no longer enough to redirect anywhere; the URL must also have been a
 * real link in that campaign's original content. If the destination
 * isn't registered (or the token doesn't resolve to a campaign at all —
 * e.g. malformed/forged token), this falls back to APP_URL instead of
 * failing open to an attacker-supplied redirect. Scheme is restricted to
 * http/https as a second guardrail regardless.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Database;
use MailAI\Tracking\ClickAllowlist;
use MailAI\Tracking\GeoIpService;
use MailAI\Tracking\TrackingEventRecorder;
use MailAI\Tracking\TrackingTokenService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

$fallback = $_ENV['APP_URL'] ?? 'https://app.example.com';

$rawUrl = $_GET['u'] ?? '';
$destination = base64_decode(strtr($rawUrl, '-_', '+/'), true);

if ($destination === false || !preg_match('#^https?://#i', $destination)) {
    // Refuse to redirect to a non-http(s) scheme (javascript:, data:, etc.)
    // or a malformed payload — bounce to the app instead of failing open.
    $destination = $fallback;
}

try {
    $token = $_GET['t'] ?? '';
    $tokens = new TrackingTokenService($_ENV['APP_ENCRYPTION_KEY'] ?? '');
    $data = $tokens->verify($token);

    if ($data !== null) {
        $db = Database::connection();

        // Allowlist check: the destination must be a link this campaign's
        // content actually contained (registered by LinkRewriter/
        // CampaignQueueingService at queue time), not just any URL an
        // attacker can pass in `u` alongside a guessed-but-valid token.
        $allowlist = new ClickAllowlist($db);
        if (!$allowlist->isRegistered($data['client_id'], $data['campaign_id'], $destination)) {
            $destination = $fallback;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $geo = new GeoIpService(__DIR__ . '/../../storage/geoip/GeoLite2-Country.mmdb');
        $country = $ip ? $geo->countryCode($ip) : null;

        $recorder = new TrackingEventRecorder($db);
        $recorder->recordClick($data['client_id'], $data['contact_id'], $data['campaign_id'], $ip, $country, $data['outbox_id'] ?: null);
    }
} catch (\Throwable $e) {
    error_log('[track/click] ' . $e->getMessage());
}

header('Location: ' . $destination, true, 302);
exit;
