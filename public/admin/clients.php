<?php

declare(strict_types=1);

/**
 * public/admin/clients.php
 *
 * Super Admin — full client/tenant CRUD (spec 1.2). Distinct from
 * index.php, which stays focused on platform-wide health (spec 1.1);
 * this page is search/filter/bulk-action over the client list itself,
 * with a link into client_detail.php for the single-client deep dive
 * (plan/quota edit, notes, activity timeline, impersonate, danger zone).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\AdminAuditLogger;
use MailAI\Core\ClientAdminRepository;
use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireSuperAdmin();
$db = Database::connection();
$repo = new ClientAdminRepository($db);
$audit = new AdminAuditLogger($db);
$adminEmail = $_SESSION['super_admin_email'] ?? 'super_admin';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_client') {
        try {
            $newId = $repo->create(
                trim((string) $_POST['company_name']),
                trim((string) $_POST['email']),
                (string) $_POST['password'],
                (string) ($_POST['plan'] ?? 'trial')
            );
            $audit->log($adminEmail, 'create_client', $newId, ['email' => $_POST['email']]);
            $flash = "Client created (#$newId).";
        } catch (\Throwable $e) {
            $flash = 'Error: ' . $e->getMessage();
        }
    } elseif ($action === 'bulk_status') {
        $ids = array_map('intval', $_POST['client_ids'] ?? []);
        $status = (string) ($_POST['bulk_status'] ?? '');
        if ($ids !== [] && in_array($status, ['active', 'suspended'], true)) {
            foreach ($ids as $id) {
                $repo->updateStatus($id, $status);
                $audit->log($adminEmail, 'bulk_' . $status, $id);
            }
            $flash = count($ids) . " client(s) set to $status.";
        }
    } elseif ($action === 'update_status') {
        $id = (int) $_POST['client_id'];
        $repo->updateStatus($id, (string) $_POST['status']);
        $audit->log($adminEmail, 'update_status', $id, ['status' => $_POST['status']]);
    }
}

$query = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$clients = $repo->search($query, $statusFilter);

$pageTitle = 'Client Management';
$activeNav = 'manage_clients';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-info py-2 small"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <form method="get" class="d-flex gap-2">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" class="form-control form-control-sm" placeholder="Search company or email..." style="width:260px;">
        <select name="status" class="form-select form-select-sm" style="width:160px;">
            <option value="">All statuses</option>
            <?php foreach (['active', 'suspended', 'pending_verification', 'cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= htmlspecialchars(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newClientModal"><i class="bi bi-plus-lg"></i> New Client</button>
</div>

<form method="post" id="bulkForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="bulk_status">
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle small">
                <thead>
                <tr>
                    <th style="width:2rem;"><input type="checkbox" onclick="document.querySelectorAll('.client-check').forEach(c=>c.checked=this.checked)"></th>
                    <th>Company</th><th>Email</th><th>Plan</th><th>Status</th><th>Connections</th><th>Campaigns</th><th>Contacts</th><th>Created</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <tr>
                        <td><input type="checkbox" class="client-check" name="client_ids[]" value="<?= (int) $c['id'] ?>"></td>
                        <td class="fw-medium"><?= htmlspecialchars($c['company_name']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($c['email']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary text-capitalize"><?= htmlspecialchars($c['plan']) ?></span></td>
                        <td>
                            <span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'warning' ?>-subtle text-<?= $c['status'] === 'active' ? 'success' : 'warning' ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $c['status'])) ?>
                            </span>
                            <?php if ($c['deleted_at']): ?><span class="badge bg-danger-subtle text-danger">deleted</span><?php endif; ?>
                        </td>
                        <td><?= (int) $c['connection_count'] ?></td>
                        <td><?= (int) $c['campaign_count'] ?></td>
                        <td><?= (int) $c['contact_count'] ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars(substr((string) $c['created_at'], 0, 10)) ?></td>
                        <td><a href="/admin/client_detail.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-primary">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($clients)): ?><tr><td colspan="9" class="text-center text-muted py-4">No clients match.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-2 d-flex gap-2">
        <button type="submit" name="bulk_status" value="suspended" class="btn btn-sm btn-outline-danger" onclick="return confirm('Suspend all selected clients?')">Suspend selected</button>
        <button type="submit" name="bulk_status" value="active" class="btn btn-sm btn-outline-success" onclick="return confirm('Activate all selected clients?')">Activate selected</button>
    </div>
</form>

<div class="modal fade" id="newClientModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_client">
            <div class="modal-header"><h6 class="modal-title fw-bold">New Client</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="text" name="company_name" class="form-control mb-2" placeholder="Company name" required>
                <input type="email" name="email" class="form-control mb-2" placeholder="Email" required>
                <input type="password" name="password" class="form-control mb-2" placeholder="Initial password" required minlength="8">
                <select name="plan" class="form-select">
                    <option value="trial">Trial</option>
                    <option value="starter">Starter</option>
                    <option value="pro">Pro</option>
                    <option value="enterprise">Enterprise</option>
                </select>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Create</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
