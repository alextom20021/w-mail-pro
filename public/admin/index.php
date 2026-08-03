<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireSuperAdmin();
$db = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    Csrf::verifyOrDie();
    $db->prepare('UPDATE clients SET status = :status WHERE id = :id')
        ->execute(['status' => $_POST['status'], 'id' => (int) $_POST['client_id']]);
}

// Global health: sends/complaints across all tenants in the last 24h.
$health = $db->query(
    "SELECT COUNT(DISTINCT client_id) AS active_clients,
            SUM(sent) AS sent_24h, SUM(complained) AS complained_24h,
            SUM(hard_bounced + soft_bounced) AS bounced_24h
     FROM isp_deliverability_stats WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
)->fetch();

$clients = $db->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM sending_connections sc WHERE sc.client_id = c.id) AS connection_count,
            (SELECT COUNT(*) FROM sending_connections sc WHERE sc.client_id = c.id AND sc.status = 'quarantined') AS quarantined_count
     FROM clients c ORDER BY c.created_at DESC"
)->fetchAll();

$pageTitle = 'Clients';
$activeNav = 'clients';
include __DIR__ . '/partials/layout_head.php';
?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card table-card p-3"><div class="text-muted small">Active Clients (24h)</div><div class="fs-4 fw-bold"><?= (int) ($health['active_clients'] ?? 0) ?></div></div></div>
    <div class="col-md-3"><div class="card table-card p-3"><div class="text-muted small">Sends (24h)</div><div class="fs-4 fw-bold"><?= number_format((int) ($health['sent_24h'] ?? 0)) ?></div></div></div>
    <div class="col-md-3"><div class="card table-card p-3"><div class="text-muted small">Bounces (24h)</div><div class="fs-4 fw-bold"><?= number_format((int) ($health['bounced_24h'] ?? 0)) ?></div></div></div>
    <div class="col-md-3"><div class="card table-card p-3"><div class="text-muted small">Complaints (24h)</div><div class="fs-4 fw-bold text-danger"><?= number_format((int) ($health['complained_24h'] ?? 0)) ?></div></div></div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Company</th><th>Email</th><th>Plan</th><th>Status</th><th>AI Autonomy</th><th>Connections</th><th>Quarantined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr>
                    <td class="fw-medium"><?= htmlspecialchars($c['company_name']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($c['email']) ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary text-capitalize"><?= htmlspecialchars($c['plan']) ?></span></td>
                    <td><span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'warning' ?>-subtle text-<?= $c['status'] === 'active' ? 'success' : 'warning' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                    <td class="small text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $c['ai_autonomy_level'])) ?></td>
                    <td><?= (int) $c['connection_count'] ?></td>
                    <td><?= $c['quarantined_count'] > 0 ? '<span class="text-danger fw-medium">' . (int) $c['quarantined_count'] . '</span>' : '0' ?></td>
                    <td>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
                            <?php if ($c['status'] === 'active'): ?>
                                <button name="status" value="suspended" class="btn btn-sm btn-outline-danger">Suspend</button>
                            <?php else: ?>
                                <button name="status" value="active" class="btn btn-sm btn-outline-success">Activate</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($clients)): ?><tr><td colspan="8" class="text-center text-muted py-4">No clients yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
