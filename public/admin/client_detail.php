<?php

declare(strict_types=1);

/**
 * public/admin/client_detail.php
 *
 * Single-client deep dive for Super Admin (spec 1.2): plan/quota edit,
 * status, force password reset, force logout, internal notes, activity
 * timeline, impersonate, and the soft-delete -> permanent-purge flow.
 * Every mutating action here goes through AdminAuditLogger so there's a
 * durable "who did what to this client, when" trail (spec 1.7).
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

$clientId = (int) ($_GET['id'] ?? 0);
$client = $repo->find($clientId);
if ($client === null) {
    http_response_code(404);
    die('Client not found.');
}

$flash = null;
$resetPasswordShown = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_plan':
            $repo->updatePlanAndQuotas(
                $clientId,
                (string) $_POST['plan'],
                (int) $_POST['quota_daily_sends'],
                (int) $_POST['quota_contacts'],
                (int) $_POST['quota_connections']
            );
            $audit->log($adminEmail, 'update_plan', $clientId, ['plan' => $_POST['plan']]);
            $flash = 'Plan & quotas updated.';
            break;

        case 'update_status':
            $repo->updateStatus($clientId, (string) $_POST['status']);
            $audit->log($adminEmail, 'update_status', $clientId, ['status' => $_POST['status']]);
            $flash = 'Status updated.';
            break;

        case 'force_logout':
            $repo->forceLogout($clientId);
            $audit->log($adminEmail, 'force_logout', $clientId);
            $flash = 'Every active session for this client has been invalidated.';
            break;

        case 'force_password_reset':
            $resetPasswordShown = $repo->forcePasswordReset($clientId);
            $audit->log($adminEmail, 'force_password_reset', $clientId);
            break;

        case 'add_note':
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($note !== '') {
                $repo->addNote($clientId, $adminEmail, $note);
                $audit->log($adminEmail, 'add_note', $clientId);
            }
            break;

        case 'soft_delete':
            $repo->softDelete($clientId);
            $audit->log($adminEmail, 'soft_delete', $clientId);
            $flash = 'Client soft-deleted. Data is retained until a permanent purge.';
            break;

        case 'restore':
            $repo->restore($clientId);
            $audit->log($adminEmail, 'restore', $clientId);
            $flash = 'Client restored.';
            break;

        case 'purge':
            if (($_POST['confirm_text'] ?? '') === $client['email']) {
                $audit->log($adminEmail, 'purge_client', $clientId, ['company_name' => $client['company_name'], 'email' => $client['email']]);
                $repo->purge($clientId);
                header('Location: /admin/clients.php');
                exit;
            }
            $flash = 'Purge confirmation text did not match the client email exactly — nothing was deleted.';
            break;

        case 'impersonate':
            $audit->log($adminEmail, 'impersonate', $clientId);
            if (SessionAuth::startImpersonation($db, $clientId, $adminEmail)) {
                header('Location: /dashboard/index.php');
                exit;
            }
            $flash = 'Could not impersonate — client may be soft-deleted.';
            break;
    }

    $client = $repo->find($clientId);
}

$notes = $repo->notes($clientId);
$timeline = $repo->activityTimeline($clientId);

$pageTitle = 'Client: ' . $client['company_name'];
$activeNav = 'manage_clients';
include __DIR__ . '/partials/layout_head.php';
?>

<a href="/admin/clients.php" class="small text-decoration-none">&laquo; Back to all clients</a>

<?php if ($flash): ?><div class="alert alert-info py-2 small mt-2"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($resetPasswordShown): ?>
    <div class="alert alert-warning py-2 small mt-2">
        New password (shown once, not recoverable after you navigate away): <code><?= htmlspecialchars($resetPasswordShown) ?></code>
    </div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <div class="col-md-7">
        <div class="card table-card p-3 mb-3">
            <h6 class="fw-bold">Overview</h6>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Company</td><td><?= htmlspecialchars($client['company_name']) ?></td></tr>
                <tr><td class="text-muted">Email</td><td><?= htmlspecialchars($client['email']) ?></td></tr>
                <tr><td class="text-muted">Status</td><td class="text-capitalize"><?= htmlspecialchars($client['status']) ?><?= $client['deleted_at'] ? ' (soft-deleted)' : '' ?></td></tr>
                <tr><td class="text-muted">AI Autonomy</td><td class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $client['ai_autonomy_level'])) ?></td></tr>
                <tr><td class="text-muted">Created</td><td><?= htmlspecialchars($client['created_at']) ?></td></tr>
                <tr><td class="text-muted">API Key</td><td><code class="small"><?= htmlspecialchars($client['api_key']) ?></code></td></tr>
            </table>
        </div>

        <div class="card table-card p-3 mb-3">
            <h6 class="fw-bold">Plan &amp; Quotas</h6>
            <form method="post" class="row g-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update_plan">
                <div class="col-6">
                    <label class="form-label small">Plan</label>
                    <select name="plan" class="form-select form-select-sm">
                        <?php foreach (['trial', 'starter', 'pro', 'enterprise'] as $p): ?>
                            <option value="<?= $p ?>" <?= $client['plan'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"></div>
                <div class="col-4">
                    <label class="form-label small">Daily send quota</label>
                    <input type="number" name="quota_daily_sends" value="<?= (int) $client['quota_daily_sends'] ?>" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-4">
                    <label class="form-label small">Contact quota</label>
                    <input type="number" name="quota_contacts" value="<?= (int) $client['quota_contacts'] ?>" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-4">
                    <label class="form-label small">Max connections</label>
                    <input type="number" name="quota_connections" value="<?= (int) $client['quota_connections'] ?>" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-12"><button class="btn btn-sm btn-primary mt-1">Save plan &amp; quotas</button></div>
            </form>
        </div>

        <div class="card table-card p-3 mb-3">
            <h6 class="fw-bold">Activity Timeline</h6>
            <table class="table table-sm small mb-0">
                <?php foreach ($timeline as $t): ?>
                    <tr>
                        <td class="text-nowrap text-muted"><?= htmlspecialchars((string) $t['created_at']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['kind']) ?></span></td>
                        <td><?= htmlspecialchars((string) $t['summary']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars((string) ($t['detail'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($timeline)): ?><tr><td colspan="4" class="text-muted text-center py-3">No activity yet.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card table-card p-3 mb-3">
            <h6 class="fw-bold">Quick Actions</h6>
            <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="impersonate">
                <button class="btn btn-sm btn-outline-dark w-100 mb-2"><i class="bi bi-person-badge"></i> Impersonate (log in as this client)</button>
            </form>
            <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update_status">
                <?php if ($client['status'] === 'active'): ?>
                    <input type="hidden" name="status" value="suspended">
                    <button class="btn btn-sm btn-outline-warning w-100 mb-2">Suspend client</button>
                <?php else: ?>
                    <input type="hidden" name="status" value="active">
                    <button class="btn btn-sm btn-outline-success w-100 mb-2">Activate client</button>
                <?php endif; ?>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Invalidate every active session for this client?')">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="force_logout">
                <button class="btn btn-sm btn-outline-secondary w-100 mb-2">Force logout (all sessions)</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Generate a new random password for this client? Their old password stops working immediately.')">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="force_password_reset">
                <button class="btn btn-sm btn-outline-secondary w-100">Force password reset</button>
            </form>
        </div>

        <div class="card table-card p-3 mb-3">
            <h6 class="fw-bold">Internal Notes</h6>
            <form method="post" class="mb-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="add_note">
                <textarea name="note" class="form-control form-control-sm mb-2" rows="2" placeholder="Add an internal note..." required></textarea>
                <button class="btn btn-sm btn-outline-primary">Add note</button>
            </form>
            <?php foreach ($notes as $n): ?>
                <div class="border-top pt-2 mt-2 small">
                    <div><?= nl2br(htmlspecialchars($n['note'])) ?></div>
                    <div class="text-muted"><?= htmlspecialchars($n['admin_email']) ?> — <?= htmlspecialchars($n['created_at']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($notes)): ?><div class="text-muted small">No notes yet.</div><?php endif; ?>
        </div>

        <div class="card border-danger table-card p-3">
            <h6 class="fw-bold text-danger">Danger Zone</h6>
            <?php if ($client['deleted_at'] === null): ?>
                <form method="post" onsubmit="return confirm('Soft-delete this client? They will be unable to log in, but data is retained until a permanent purge.')">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="soft_delete">
                    <button class="btn btn-sm btn-outline-danger w-100">Soft-delete client</button>
                </form>
            <?php else: ?>
                <form method="post" class="mb-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="restore">
                    <button class="btn btn-sm btn-outline-success w-100">Restore client</button>
                </form>
                <form method="post" onsubmit="return confirm('This permanently and irreversibly deletes this client and all of their data. Are you absolutely sure?')">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="purge">
                    <label class="form-label small">Type the client's email to confirm permanent purge:</label>
                    <input type="text" name="confirm_text" class="form-control form-control-sm mb-2" placeholder="<?= htmlspecialchars($client['email']) ?>" required>
                    <button class="btn btn-sm btn-danger w-100">Permanently purge (irreversible)</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
