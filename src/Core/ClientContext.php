<?php

declare(strict_types=1);

namespace MailAI\Core;

use RuntimeException;

/**
 * ClientContext
 *
 * Holds the "who is making this request" for the current execution
 * (an authenticated client/tenant, or the super-admin). Every
 * TenantRepository call reads the client_id from here rather than
 * trusting a value passed up from a form/request — that's what
 * prevents one client from touching another client's rows by
 * tampering with a parameter.
 *
 * Set once per request, immediately after auth (session or API token).
 */
final class ClientContext
{
    private static ?int $clientId = null;
    private static bool $isSuperAdmin = false;

    public static function setClient(int $clientId): void
    {
        self::$clientId = $clientId;
        self::$isSuperAdmin = false;
    }

    public static function setSuperAdmin(): void
    {
        self::$clientId = null;
        self::$isSuperAdmin = true;
    }

    public static function clientId(): int
    {
        if (self::$clientId === null) {
            throw new RuntimeException(
                'No client scoped to this request. Call ClientContext::setClient() after authentication.'
            );
        }

        return self::$clientId;
    }

    public static function isSuperAdmin(): bool
    {
        return self::$isSuperAdmin;
    }

    public static function hasClient(): bool
    {
        return self::$clientId !== null;
    }

    /** Reset between requests in long-running workers (CLI loop, PHP-FPM reuse). */
    public static function clear(): void
    {
        self::$clientId = null;
        self::$isSuperAdmin = false;
    }
}
