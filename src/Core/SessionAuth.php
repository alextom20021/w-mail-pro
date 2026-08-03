<?php

declare(strict_types=1);

namespace MailAI\Core;

use PDO;

/**
 * SessionAuth
 *
 * Cookie-session-based authentication for the server-rendered dashboard
 * (distinct from ApiAuthenticator's Bearer-token auth used by the REST
 * API / external integrations). Both ultimately call ClientContext, so
 * TenantRepository-based code doesn't care which auth path set it.
 */
final class SessionAuth
{
    public static function startIfNeeded(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => self::isHttps()]);
            session_start();
        }
    }

    public static function attemptClientLogin(PDO $db, string $email, string $password): bool
    {
        $stmt = $db->prepare('SELECT id, password_hash, status FROM clients WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $client = $stmt->fetch();

        if ($client === false || $client['status'] !== 'active' || !password_verify($password, $client['password_hash'])) {
            return false;
        }

        self::startIfNeeded();
        session_regenerate_id(true);
        $_SESSION['client_id'] = (int) $client['id'];
        unset($_SESSION['super_admin']);

        return true;
    }

    public static function attemptSuperAdminLogin(PDO $db, string $email, string $password): bool
    {
        $stmt = $db->prepare('SELECT id, password_hash FROM super_admins WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        if ($admin === false || !password_verify($password, $admin['password_hash'])) {
            return false;
        }

        self::startIfNeeded();
        session_regenerate_id(true);
        $_SESSION['super_admin'] = true;
        $_SESSION['super_admin_id'] = (int) $admin['id'];
        unset($_SESSION['client_id']);

        return true;
    }

    /** Call at the top of every dashboard page. Redirects to login if not authed. */
    public static function requireClient(): int
    {
        self::startIfNeeded();
        if (empty($_SESSION['client_id'])) {
            header('Location: /dashboard/login.php');
            exit;
        }

        ClientContext::setClient((int) $_SESSION['client_id']);

        return (int) $_SESSION['client_id'];
    }

    public static function requireSuperAdmin(): void
    {
        self::startIfNeeded();
        if (empty($_SESSION['super_admin'])) {
            header('Location: /admin/login.php');
            exit;
        }

        ClientContext::setSuperAdmin();
    }

    public static function logout(): void
    {
        self::startIfNeeded();
        $_SESSION = [];
        session_destroy();
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
