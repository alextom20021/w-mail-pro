<?php

declare(strict_types=1);

namespace MailAI\Core;

/**
 * Csrf
 *
 * Minimal session-bound CSRF token helper for the server-rendered
 * dashboard forms (connections/domains/lists/campaigns/onboarding all
 * POST directly, no SPA/JS framework in front of them, so a classic
 * synchronizer token is the right fit — no need for a JS library).
 */
final class Csrf
{
    public static function token(): string
    {
        SessionAuth::startIfNeeded();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }

    /** Call at the top of every POST handler before touching $_POST data. */
    public static function verifyOrDie(): void
    {
        SessionAuth::startIfNeeded();
        $submitted = $_POST['csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
            http_response_code(419);
            die('Invalid or expired form token — please refresh and try again.');
        }
    }
}
