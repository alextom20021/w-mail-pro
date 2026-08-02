<?php

declare(strict_types=1);

/**
 * public/track/click.php
 *
 * Click redirector: /track/click.php?t=TOKEN&u=BASE64_DESTINATION_URL
 * Records the click, then 302-redirects to the original link.
 *
 * SECURITY NOTE (Phase 1 scope): the destination URL is taken from the
 * request rather than being cross-checked against a registered list of
 * links extracted from the campaign at send time. That means the token
 * proves "this is a real tracked send for this contact/campaign" but
 * NOT "this exact URL was in the original email" — a party who guesses
 * a valid token could redirect through this domain to an arbitrary URL
 * (a classic open-redirect risk, mitigated only by requiring a valid
 * signed token). Phase 2 should extract and store links per campaign at
 * queue time and validate `u` against that allowlist before redirecting.
 * Scheme is restricted to http/https here as a minimum guardrail.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Database;
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $geo = new GeoIpService(__DIR__ . '/../../storage/geoip/GeoLite2-Country.mmdb');
        $country = $ip ? $geo->countryCode($ip) : null;

        $recorder = new TrackingEventRecorder(Database::connection());
        $recorder->recordClick($data['client_id'], $data['contact_id'], $data['campaign_id'], $ip, $country);
    }
} catch (\Throwable $e) {
    error_log('[track/click] ' . $e->getMessage());
}

header('Location: ' . $destination, true, 302);
exit;
