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
    $ok = SessionAuth::attemptSuperAdminLogin(Database::connection(), $_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($ok) {
        header('Location: /admin/index.php');
        exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head><meta charset="UTF-8"><title>Super Admin Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-dark d-flex align-items-center" style="min-height:100vh;">
<div class="container" style="max-width:380px;">
    <div class="card shadow border-0 rounded-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-shield-lock-fill text-primary"></i> Super Admin</h5>
            <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="email" name="email" class="form-control mb-2" placeholder="Email" required autofocus>
                <input type="password" name="password" class="form-control mb-3" placeholder="Password" required>
                <button class="btn btn-dark w-100">Sign in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
