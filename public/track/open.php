<?php

declare(strict_types=1);

/**
 * public/track/open.php
 *
 * 1x1 transparent GIF tracking pixel. Embedded in outgoing HTML emails as
 * <img src="https://app.example.com/track/open.php?t=TOKEN">.
 *
 * Deliberately fails silent and fast: any error here still returns a
 * valid pixel image so a broken tracking call never shows as a broken
 * image in the recipient's inbox (which looks unprofessional and can
 * itself hurt engagement signals).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Database;
use MailAI\Tracking\GeoIpService;
use MailAI\Tracking\TrackingEventRecorder;
use MailAI\Tracking\TrackingTokenService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

$PIXEL = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Length: ' . strlen($PIXEL));

try {
    $token = $_GET['t'] ?? '';
    $tokens = new TrackingTokenService($_ENV['APP_ENCRYPTION_KEY'] ?? '');
    $data = $tokens->verify($token);

    if ($data !== null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $geo = new GeoIpService(__DIR__ . '/../../storage/geoip/GeoLite2-Country.mmdb');
        $country = $ip ? $geo->countryCode($ip) : null;

        $recorder = new TrackingEventRecorder(Database::connection());
        $recorder->recordOpen($data['client_id'], $data['contact_id'], $data['campaign_id'], $ip, $country, $data['outbox_id'] ?: null);
    }
} catch (\Throwable $e) {
    // Swallow — tracking failures must never surface to the recipient or
    // break email rendering. Logged to error_log for ops visibility only.
    error_log('[track/open] ' . $e->getMessage());
}

echo $PIXEL;
