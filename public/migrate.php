<?php

declare(strict_types=1);

/**
 * public/migrate.php
 *
 * ONE-TIME (well — one-time PER FILE) deployment utility. Runs
 * database/*.sql migrations against whatever DB_* the running
 * environment is configured with, then (optionally, via ?seed=1)
 * creates a first client + super-admin login.
 *
 * By default runs every 00N_*.sql file in database/, in order — correct
 * for a genuinely fresh database. Pass &only=004 (matches the numeric
 * prefix) to run a single migration file instead, which is what you want
 * once earlier files have already been applied: none of these migrations
 * are safe to re-run (plain ADD COLUMN/CREATE TABLE, not idempotent
 * IF NOT EXISTS throughout — see the note in 001_platform_schema.sql for
 * why ADD COLUMN IF NOT EXISTS isn't used), so re-running an already-
 * applied file fails with a duplicate-column/table error rather than
 * silently no-op'ing.
 *
 * Guarded by MIGRATE_TOKEN (an env var, not committed anywhere) so this
 * isn't a public unauthenticated "drop tables" endpoint sitting on the
 * live site. There is no default token — if MIGRATE_TOKEN isn't set in
 * the environment, every request 403s. Set MIGRATE_TOKEN only for the
 * duration of a migration run, then delete it again.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailAI\Core\Database;

header('Content-Type: application/json');

$expectedToken = $_ENV['MIGRATE_TOKEN'] ?? '';
$givenToken = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, (string) $givenToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$results = [];

try {
    $pdo = Database::connection();

    $allFiles = glob(__DIR__ . '/../database/0*.sql');
    sort($allFiles);

    $only = $_GET['only'] ?? '';
    if ($only !== '') {
        $files = array_values(array_filter($allFiles, static fn($f) => str_contains(basename($f), $only)));
        if ($files === []) {
            throw new RuntimeException("No migration file matches only=$only");
        }
    } else {
        $files = $allFiles;
    }

    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Could not read $file");
        }

        // Strip -- line comments, then split on statement-terminating
        // semicolons — but NOT semicolons that appear inside a quoted
        // string (e.g. a COMMENT '...' containing a semicolon), which a
        // naive explode(';', ...) would incorrectly split on. None of
        // these migrations use stored procedures/triggers with an
        // alternate DELIMITER, so a quote-aware split covers every case
        // here.
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $statements = [];
        $current = '';
        $inString = null; // null, "'", or '"'
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString !== null) {
                $current .= $ch;
                if ($ch === '\\') {
                    // consume the escaped character verbatim so an
                    // escaped quote doesn't end the string early
                    if ($i + 1 < $len) {
                        $current .= $sql[++$i];
                    }
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        $statements = array_filter($statements, static fn($s) => $s !== '');

        $count = 0;
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
            $count++;
        }

        $results[basename($file)] = "ok ($count statements)";
    }

    if (($_GET['seed'] ?? '') === '1') {
        $clientEmail = $_GET['client_email'] ?? 'alextom20021@gmail.com';
        $adminEmail = $_GET['admin_email'] ?? 'alextom20021@gmail.com';
        $password = $_GET['password'] ?? '';

        if ($password === '') {
            throw new RuntimeException('seed=1 requires a &password= query param');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // clients table shape: database/001_platform_schema.sql. uuid and
        // api_key are NOT NULL UNIQUE with no DB-side default, so generate
        // both here.
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
        $apiKey = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare(
            'INSERT INTO clients (uuid, company_name, email, password_hash, api_key, status, ai_autonomy_level, created_at)
             VALUES (:uuid, :company_name, :email, :password_hash, :api_key, :status, :autonomy, NOW())'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'company_name' => 'Alex',
            'email' => $clientEmail,
            'password_hash' => $passwordHash,
            'api_key' => $apiKey,
            'status' => 'active',
            'autonomy' => 'suggest_only',
        ]);
        $clientId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO super_admins (email, password_hash, created_at)
             VALUES (:email, :password_hash, NOW())'
        );
        $stmt->execute([
            'email' => $adminEmail,
            'password_hash' => $passwordHash,
        ]);

        $results['seed'] = [
            'client_id' => $clientId,
            'client_login_email' => $clientEmail,
            'client_api_key' => $apiKey,
            'super_admin_login_email' => $adminEmail,
            'note' => 'Use the &password= you passed in this request to log in at /dashboard/login.php and /admin/login.php',
        ];
    }

    echo json_encode(['status' => 'ok', 'results' => $results], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_PRETTY_PRINT);
}
