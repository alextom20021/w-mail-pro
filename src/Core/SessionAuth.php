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
        $stmt = $db->prepare('SELECT id, password_hash, status, session_version, deleted_at FROM clients WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $client = $stmt->fetch();

        if ($client === false || $client['status'] !== 'active' || $client['deleted_at'] !== null
            || !password_verify($password, $client['password_hash'])) {
            return false;
        }

        self::startIfNeeded();
        session_regenerate_id(true);
        $_SESSION['client_id'] = (int) $client['id'];
        $_SESSION['session_version'] = (int) $client['session_version'];
        unset($_SESSION['super_admin'], $_SESSION['impersonating_admin_email']);

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
        $_SESSION['super_admin_email'] = $email;
        unset($_SESSION['client_id']);

        return true;
    }

    /**
     * Call at the top of every dashboard page. Redirects to login if not
     * authed. Also re-checks session_version/status/deleted_at against
     * the DB on every request (not just at login) — this is what makes
     * super admin's "Force logout", "Suspend", and "Soft-delete" actions
     * actually kick an already-open browser session instead of only
     * blocking the *next* login attempt.
     */
    public static function requireClient(?PDO $db = null): int
    {
        self::startIfNeeded();
        if (empty($_SESSION['client_id'])) {
            header('Location: /dashboard/login.php');
            exit;
        }

        $db ??= Database::connection();
        $stmt = $db->prepare('SELECT status, session_version, deleted_at FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $_SESSION['client_id']]);
        $client = $stmt->fetch();

        if ($client === false
            || $client['deleted_at'] !== null
            || $client['status'] !== 'active'
            || (int) $client['session_version'] !== (int) ($_SESSION['session_version'] ?? -1)) {
            $_SESSION = [];
            session_destroy();
            header('Location: /dashboard/login.php?reason=session_ended');
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

    /**
     * Super admin "log in as this client" — spec 1.2's impersonate.
     * Keeps super_admin_id in the session (not unset) so
     * stopImpersonation() can hand control back without a fresh login,
     * and stamps impersonating_admin_email so the dashboard chrome can
     * render an unmistakable "you are impersonating X" banner.
     */
    public static function startImpersonation(PDO $db, int $clientId, string $adminEmail): bool
    {
        $stmt = $db->prepare('SELECT session_version FROM clients WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch();
        if ($client === false) {
            return false;
        }

        self::startIfNeeded();
        $_SESSION['client_id'] = $clientId;
        $_SESSION['session_version'] = (int) $client['session_version'];
        $_SESSION['impersonating_admin_email'] = $adminEmail;

        return true;
    }

    /** Hands control back to the super admin session that started impersonating. */
    public static function stopImpersonation(): void
    {
        self::startIfNeeded();
        unset($_SESSION['client_id'], $_SESSION['session_version'], $_SESSION['impersonating_admin_email']);
        header('Location: /admin/clients.php');
        exit;
    }

    public static function isImpersonating(): bool
    {
        self::startIfNeeded();

        return !empty($_SESSION['impersonating_admin_email']);
    }

    public static function impersonatingAdminEmail(): ?string
    {
        self::startIfNeeded();

        return $_SESSION['impersonating_admin_email'] ?? null;
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
