<?php

declare(strict_types=1);

namespace MailAI\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database
 *
 * Thin PDO wrapper. Always use prepared statements — never interpolate
 * user input into SQL. This class does NOT enforce tenant scoping itself;
 * that's TenantRepository's job (see below). Keeping them separate means
 * super-admin code can legitimately query across all clients.
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $name = $_ENV['DB_NAME'] ?? 'mailai';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASS'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                ]);
            } catch (PDOException $e) {
                // Never leak DSN/credentials in the exception message.
                throw new RuntimeException('Database connection failed.', 0, $e);
            }
        }

        return self::$instance;
    }

    /** For tests / worker forks that need a fresh connection. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
