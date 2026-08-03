<?php
/**
 * Shared dashboard chrome: sidebar + topbar. Included by every
 * public/dashboard/*.php page after SessionAuth::requireClient().
 * Expects $pageTitle and $activeNav to be set by the including page.
 */
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(\MailAI\Core\Csrf::token()) ?>">
    <title><?= htmlspecialchars($pageTitle ?? 'MailAI') ?> — MailAI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root { --sidebar-width: 250px; --sidebar-bg: #0f172a; --sidebar-hover: #1e293b; --accent: #6366f1; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f1f5f9; }
        #sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; top: 0; left: 0; background: var(--sidebar-bg); color: #e2e8f0; z-index: 1040; display: flex; flex-direction: column; }
        #sidebar .brand { padding: 1.15rem 1.4rem; font-size: 1.15rem; font-weight: 700; color: #fff; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: .6rem; }
        #sidebar .brand i { color: var(--accent); font-size: 1.4rem; }
        #sidebar .nav-link { color: #94a3b8; padding: .6rem 1.4rem; display: flex; align-items: center; gap: .75rem; font-size: .9rem; border-left: 3px solid transparent; }
        #sidebar .nav-link:hover, #sidebar .nav-link.active { color: #fff; background: var(--sidebar-hover); border-left-color: var(--accent); }
        #main { margin-left: var(--sidebar-width); min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: .8rem 1.6rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 1030; }
        .stat-card { border: none; border-radius: .8rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .icon-wrap { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .bg-soft-indigo { background: #e0e7ff; color: #4f46e5; } .bg-soft-emerald { background: #d1fae5; color: #059669; }
        .bg-soft-amber { background: #fef3c7; color: #d97706; } .bg-soft-rose { background: #ffe4e6; color: #e11d48; }
        .table-card { border: none; border-radius: .8rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .ai-fab { position: fixed; bottom: 1.6rem; right: 1.6rem; width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg,#6366f1,#8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 8px 25px rgba(99,102,241,.4); z-index: 1050; border: none; }
    </style>
</head>
<body>
<?php if (\MailAI\Core\SessionAuth::isImpersonating()): ?>
<div class="alert alert-warning rounded-0 mb-0 py-2 text-center small" style="position:sticky; top:0; z-index:2000;">
    <i class="bi bi-eye-fill"></i> A super admin (<?= htmlspecialchars(\MailAI\Core\SessionAuth::impersonatingAdminEmail()) ?>) is viewing this account as you.
    <a href="/admin/stop_impersonate.php" class="fw-bold ms-2">Return to Admin</a>
</div>
<?php endif; ?>
<nav id="sidebar">
    <div class="brand"><i class="bi bi-envelope-check-fill"></i><span>MailAI</span></div>
    <div class="flex-grow-1 overflow-auto py-2">
        <a href="/dashboard/index.php" class="nav-link <?= $activeNav === 'overview' ? 'active' : '' ?>"><i class="bi bi-speedometer2"></i> Overview</a>
        <a href="/dashboard/connections.php" class="nav-link <?= $activeNav === 'connections' ? 'active' : '' ?>"><i class="bi bi-hdd-network"></i> Connections</a>
        <a href="/dashboard/domains.php" class="nav-link <?= $activeNav === 'domains' ? 'active' : '' ?>"><i class="bi bi-globe2"></i> Domains</a>
        <a href="/dashboard/lists.php" class="nav-link <?= $activeNav === 'lists' ? 'active' : '' ?>"><i class="bi bi-list-ul"></i> Contact Lists</a>
        <a href="/dashboard/campaigns.php" class="nav-link <?= $activeNav === 'campaigns' ? 'active' : '' ?>"><i class="bi bi-send"></i> Campaigns</a>
        <a href="/dashboard/analytics.php" class="nav-link <?= $activeNav === 'analytics' ? 'active' : '' ?>"><i class="bi bi-bar-chart-line"></i> Analytics</a>
        <a href="/dashboard/onboarding.php" class="nav-link <?= $activeNav === 'onboarding' ? 'active' : '' ?>"><i class="bi bi-flag"></i> Onboarding</a>
        <a href="/dashboard/webhooks.php" class="nav-link <?= $activeNav === 'webhooks' ? 'active' : '' ?>"><i class="bi bi-plug"></i> Webhooks</a>
        <a href="/dashboard/logout.php" class="nav-link"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
</nav>
<div id="main">
    <div class="topbar">
        <h5 class="mb-0 fw-bold"><?= htmlspecialchars($pageTitle ?? '') ?></h5>
        <a href="#" class="btn btn-sm btn-outline-secondary" data-bs-toggle="offcanvas" data-bs-target="#aiAssistant"><i class="bi bi-robot me-1"></i> AI Assistant</a>
    </div>
    <div class="p-4">
