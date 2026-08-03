<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::startIfNeeded();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = SessionAuth::attemptClientLogin(Database::connection(), $_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($ok) {
        header('Location: /dashboard/index.php');
        exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8"><title>Sign in — MailAI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width:400px;">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-3"><i class="bi bi-envelope-check-fill text-primary"></i> MailAI</h4>
            <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label small">Email</label>
                    <input type="email" name="email" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>
            <p class="text-center small text-muted mt-3 mb-0">
                No account yet? <a href="/dashboard/signup.php">Create one</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
