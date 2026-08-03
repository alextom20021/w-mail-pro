<?php
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — MailAI Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; }
        #sidebar { width: 240px; height: 100vh; position: fixed; background: #0f172a; color: #e2e8f0; }
        #sidebar .brand { padding: 1.1rem 1.3rem; font-weight: 700; color: #fff; border-bottom: 1px solid #1e293b; }
        #sidebar .nav-link { color: #94a3b8; padding: .6rem 1.3rem; display: block; font-size: .9rem; }
        #sidebar .nav-link.active, #sidebar .nav-link:hover { color: #fff; background: #1e293b; }
        #main { margin-left: 240px; padding: 1.75rem; }
        .table-card { border: none; border-radius: .8rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    </style>
</head>
<body>
<nav id="sidebar">
    <div class="brand"><i class="bi bi-shield-lock-fill text-primary"></i> Super Admin</div>
    <a href="/admin/index.php" class="nav-link <?= $activeNav === 'clients' ? 'active' : '' ?>">Global Health</a>
    <a href="/admin/clients.php" class="nav-link <?= $activeNav === 'manage_clients' ? 'active' : '' ?>">Manage Clients</a>
    <a href="/admin/audit_log.php" class="nav-link <?= $activeNav === 'audit' ? 'active' : '' ?>">AI Audit Log</a>
    <a href="/admin/logout.php" class="nav-link">Logout</a>
</nav>
<div id="main">
<h4 class="fw-bold mb-4"><?= htmlspecialchars($pageTitle ?? '') ?></h4>
