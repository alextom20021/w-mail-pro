<?php

declare(strict_types=1);

/**
 * public/admin/stop_impersonate.php
 *
 * Hands control back to the super admin session — linked from the
 * "You are impersonating..." banner injected into the client dashboard
 * chrome while SessionAuth::isImpersonating() is true.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::startIfNeeded();

if (empty($_SESSION['super_admin'])) {
    header('Location: /admin/login.php');
    exit;
}

SessionAuth::stopImpersonation();
