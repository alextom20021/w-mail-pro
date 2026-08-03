<?php

declare(strict_types=1);

/**
 * public/dashboard/signup.php
 *
 * Self-serve registration — replaces the "insert a row by hand" flow
 * documented in the old README. Creates a `clients` row via
 * ClientRegistrationService, then logs the new client straight in via
 * SessionAuth (same as login.php would) so signup -> dashboard is one
 * step, landing on the onboarding wizard.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\ClientRegistrationService;
use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::startIfNeeded();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();

    $companyName = (string) ($_POST['company_name'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $service = new ClientRegistrationService(Database::connection());
            $service->register($companyName, $email, $password);

            // Log straight in — same session shape as SessionAuth::attemptClientLogin.
            $ok = SessionAuth::attemptClientLogin(Database::connection(), $email, $password);
            if ($ok) {
                header('Location: /dashboard/onboarding.php');
                exit;
            }
            $error = 'Account created — please sign in.';
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8"><title>Create your account — MailAI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width:440px;">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-1"><i class="bi bi-envelope-check-fill text-primary"></i> MailAI</h4>
            <p class="text-muted small mb-3">Create your account — free trial, no card required.</p>
            <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <?= Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label small">Company / organization name</label>
                    <input type="text" name="company_name" class="form-control" required autofocus
                           value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Email</label>
                    <input type="email" name="email" class="form-control" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Password</label>
                    <input type="password" name="password" class="form-control" minlength="8" required>
                    <div class="form-text">At least 8 characters.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Confirm password</label>
                    <input type="password" name="password_confirm" class="form-control" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create account</button>
            </form>
            <p class="text-center small text-muted mt-3 mb-0">
                Already have an account? <a href="/dashboard/login.php">Sign in</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
