<?php

declare(strict_types=1);

/**
 * public/admin/index.php — Super Admin: Dashboard & Global Health
 * (spec 1.1). Platform-wide, cross-tenant view: overview counters,
 * global inbox placement, anomaly counters with severity, live AI
 * engine activity feed, system health (DB / queue depth / worker
 * liveness / webhook backlog), deliverability heatmap by ISP, and a
 * critical-alerts panel with one-click quarantine/release/pause-client
 * actions. Client CRUD lives on clients.php; this page is the pulse.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\AdminAuditLogger;
use MailAI\Core\Csrf;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireSuperAdmin();
$db = Database::connection();
$audit = new AdminAuditLogger($db);
$adminEmail = $_SESSION['super_admin_email'] ?? 'super_admin';
$flash = null;

// One-click critical-alert actions (spec 1.1) — all audited.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'quarantine_connection' || $action === 'release_connection') {
        $connId = (int) $_POST['connection_id'];
        $status = $action === 'quarantine_connection' ? 'quarantined' : 'active';
        $db->prepare("UPDATE sending_connections SET status = :status, quarantine_reason = :reason WHERE id = :id")
            ->execute([
                'status' => $status,
                'reason' => $status === 'quarantined' ? 'Manually isolated by super admin' : null,
                'id' => $connId,
            ]);
        $audit->log($adminEmail, $action, null, ['connection_id' => $connId]);
        $flash = "Connection #$connId " . ($status === 'quarantined' ? 'isolated.' : 'released.');
    }

    if ($action === 'pause_client') {
        $clientId = (int) $_POST['client_id'];
        $db->prepare("UPDATE clients SET status = 'suspended', session_version = session_version + 1 WHERE id = :id")
            ->execute(['id' => $clientId]);
        $audit->log($adminEmail, 'pause_client', $clientId, ['from' => 'global_health_alerts']);
        $flash = "Client #$clientId paused (suspended + force-logged-out).";
    }
}

$q = static function (string $sql) use ($db): array {
    return $db->query($sql)->fetchAll();
};

// --- Platform overview -------------------------------------------------
$clientTotals = $q("SELECT COUNT(*) AS total, SUM(status = 'active' AND deleted_at IS NULL) AS active FROM clients")[0];
$sends = $q(
    "SELECT SUM(sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS h24,
            SUM(sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS d7,
            SUM(sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS d30
     FROM outbox WHERE status = 'sent'"
)[0];

// Global inbox placement: weighted average across all tenants, 7 days.
$placement = $q(
    "SELECT SUM(sent) AS sent, SUM(delivered) AS delivered
     FROM isp_deliverability_stats WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
)[0];
$globalInboxRate = ((int) $placement['sent']) > 0
    ? round((int) $placement['delivered'] / (int) $placement['sent'] * 100, 1) : null;

// --- Anomalies + severity ----------------------------------------------
$quarantined = $q(
    "SELECT sc.id, sc.label, sc.type, sc.quarantine_reason, c.company_name, c.id AS client_id
     FROM sending_connections sc JOIN clients c ON c.id = sc.client_id
     WHERE sc.status = 'quarantined' ORDER BY sc.id DESC LIMIT 20"
);
$complaints24h = (int) ($q(
    "SELECT COALESCE(SUM(complained),0) AS n FROM isp_deliverability_stats WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
)[0]['n'] ?? 0);
$anomalyCount = count($quarantined) + ($complaints24h > 0 ? 1 : 0);
$severity = $anomalyCount === 0 ? ['OK', 'success'] : ($anomalyCount < 3 ? ['ELEVATED', 'warning'] : ['CRITICAL', 'danger']);

// High-complaint clients (top offenders last 24h) for the alerts panel.
$hotClients = $q(
    "SELECT c.id, c.company_name, SUM(s.complained) AS complaints, SUM(s.sent) AS sent
     FROM isp_deliverability_stats s JOIN clients c ON c.id = s.client_id
     WHERE s.stat_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND c.status = 'active'
     GROUP BY c.id HAVING complaints > 0 ORDER BY complaints DESC LIMIT 5"
);

// --- System health ------------------------------------------------------
$queueDepth = (int) $q("SELECT COUNT(*) AS n FROM outbox WHERE status = 'queued'")[0]['n'];
$oldestQueuedMin = $q("SELECT TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) AS m FROM outbox WHERE status = 'queued' AND scheduled_at <= NOW()")[0]['m'];
$sentLast10m = (int) $q("SELECT COUNT(*) AS n FROM outbox WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")[0]['n'];
$webhookBacklog = (int) $q("SELECT COUNT(*) AS n FROM webhook_deliveries WHERE delivered_at IS NULL AND attempt_count < 5")[0]['n'];
$workerHealthy = $queueDepth === 0 || $sentLast10m > 0 || $oldestQueuedMin === null || (int) $oldestQueuedMin < 10;

// --- ISP heatmap (7 days, all tenants) ---------------------------------
$ispHeat = $q(
    "SELECT isp, SUM(sent) AS sent, SUM(delivered) AS delivered, SUM(opened) AS opened,
            SUM(hard_bounced + soft_bounced) AS bounced, SUM(complained) AS complained
     FROM isp_deliverability_stats
     WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY isp ORDER BY sent DESC"
);

// --- Live AI engine activity feed --------------------------------------
$aiFeed = $q(
    "SELECT a.tool_name, a.status, a.created_at, c.company_name
     FROM ai_audit_log a LEFT JOIN clients c ON c.id = a.client_id
     ORDER BY a.id DESC LIMIT 12"
);

$pageTitle = 'Global Health';
$activeNav = 'clients';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-info py-2 small"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="badge bg-<?= $severity[1] ?> me-2">Platform status: <?= $severity[0] ?></span>
        <span class="text-muted small"><?= $anomalyCount ?> open anomaly indicator(s)</span>
    </div>
    <div class="btn-group">
        <a href="/admin/health_export.php?format=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
        <a href="/admin/health_export.php?format=pdf" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-pdf me-1"></i>Export PDF</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-2"><div class="card table-card p-3"><div class="text-muted small">Clients</div><div class="fs-4 fw-bold"><?= (int) $clientTotals['total'] ?></div><div class="small text-success"><?= (int) $clientTotals['active'] ?> active</div></div></div>
    <div class="col-6 col-xl-2"><div class="card table-card p-3"><div class="text-muted small">Sent (24h)</div><div class="fs-4 fw-bold"><?= number_format((int) $sends['h24']) ?></div></div></div>
    <div class="col-6 col-xl-2"><div class="card table-card p-3"><div class="text-muted small">Sent (7d)</div><div class="fs-4 fw-bold"><?= number_format((int) $sends['d7']) ?></div></div></div>
    <div class="col-6 col-xl-2"><div class="card table-card p-3"><div class="text-muted small">Sent (30d)</div><div class="fs-4 fw-bold"><?= number_format((int) $sends['d30']) ?></div></div></div>
    <div class="col-6 col-xl-2"><div class="card table-card p-3"><div class="text-muted small">Global Inbox Rate (7d)</div><div class="fs-4 fw-bold"><?= $globalInboxRate !== null ? $globalInboxRate . '%' : '—' ?></div></div></div>
    <div class="col-6 col-xl-2"><div class="card table-card p-3"><div class="text-muted small">Complaints (24h)</div><div class="fs-4 fw-bold <?= $complaints24h > 0 ? 'text-danger' : '' ?>"><?= $complaints24h ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6"><div class="card table-card p-3">
        <div class="text-muted small">MySQL</div>
        <div class="fw-bold text-success"><i class="bi bi-check-circle-fill me-1"></i>Connected</div>
    </div></div>
    <div class="col-xl-3 col-md-6"><div class="card table-card p-3">
        <div class="text-muted small">Queue depth</div>
        <div class="fw-bold"><?= number_format($queueDepth) ?> queued<?= $oldestQueuedMin !== null ? ' · oldest ' . (int) $oldestQueuedMin . 'm' : '' ?></div>
    </div></div>
    <div class="col-xl-3 col-md-6"><div class="card table-card p-3">
        <div class="text-muted small">Send worker</div>
        <div class="fw-bold text-<?= $workerHealthy ? 'success' : 'danger' ?>">
            <i class="bi bi-<?= $workerHealthy ? 'check-circle-fill' : 'x-circle-fill' ?> me-1"></i>
            <?= $workerHealthy ? "Healthy ({$sentLast10m} sent /10m)" : 'Stalled — queued mail is aging' ?>
        </div>
    </div></div>
    <div class="col-xl-3 col-md-6"><div class="card table-card p-3">
        <div class="text-muted small">Webhook backlog</div>
        <div class="fw-bold"><?= number_format($webhookBacklog) ?> pending</div>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">Deliverability Heatmap by ISP (7d, all tenants)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ISP</th><th>Sent</th><th>Inbox %</th><th>Open %</th><th>Bounce %</th><th>Complaints</th></tr></thead>
                    <tbody>
                    <?php foreach ($ispHeat as $r):
                        $sent = max(1, (int) $r['sent']);
                        $inboxPct = round((int) $r['delivered'] / $sent * 100, 1);
                        $heat = $inboxPct >= 95 ? 'success' : ($inboxPct >= 85 ? 'warning' : 'danger');
                    ?>
                        <tr>
                            <td class="text-capitalize fw-medium"><?= htmlspecialchars($r['isp']) ?></td>
                            <td><?= number_format((int) $r['sent']) ?></td>
                            <td><span class="badge bg-<?= $heat ?>-subtle text-<?= $heat ?>"><?= $inboxPct ?>%</span></td>
                            <td><?= round((int) $r['opened'] / $sent * 100, 1) ?>%</td>
                            <td><?= round((int) $r['bounced'] / $sent * 100, 1) ?>%</td>
                            <td class="<?= (int) $r['complained'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= (int) $r['complained'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ispHeat)): ?><tr><td colspan="6" class="text-center text-muted py-3">No send data yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold"><i class="bi bi-robot text-primary me-1"></i> Live AI Engine Activity (all tenants)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($aiFeed as $a): ?>
                        <tr>
                            <td class="text-nowrap text-muted small"><?= htmlspecialchars($a['created_at']) ?></td>
                            <td class="small"><?= htmlspecialchars($a['company_name'] ?? 'platform') ?></td>
                            <td><code class="small"><?= htmlspecialchars($a['tool_name']) ?></code></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($a['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($aiFeed)): ?><tr><td class="text-center text-muted py-3">No AI actions yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card table-card border-danger mb-4">
    <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold text-danger"><i class="bi bi-exclamation-octagon-fill me-1"></i> Critical Alerts — one-click actions</h6></div>
    <div class="card-body pt-0">
        <?php if (empty($quarantined) && empty($hotClients)): ?>
            <div class="text-muted small py-2">No critical alerts. Quarantined connections and high-complaint clients appear here with one-click isolate/release/pause actions.</div>
        <?php endif; ?>

        <?php foreach ($quarantined as $c): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <div>
                    <strong><?= htmlspecialchars($c['label']) ?></strong> (<?= htmlspecialchars($c['type']) ?>) — <?= htmlspecialchars($c['company_name']) ?>
                    <span class="text-muted">· <?= htmlspecialchars($c['quarantine_reason'] ?? 'quarantined') ?></span>
                </div>
                <form method="post" class="d-inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="release_connection">
                    <input type="hidden" name="connection_id" value="<?= (int) $c['id'] ?>">
                    <button class="btn btn-sm btn-outline-success">Release</button>
                </form>
            </div>
        <?php endforeach; ?>

        <?php foreach ($hotClients as $h): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <div>
                    <strong><?= htmlspecialchars($h['company_name']) ?></strong> — <span class="text-danger fw-bold"><?= (int) $h['complaints'] ?> complaint(s)</span>
                    in 24h across <?= number_format((int) $h['sent']) ?> sends
                </div>
                <div class="d-flex gap-1">
                    <a href="/admin/client_detail.php?id=<?= (int) $h['id'] ?>" class="btn btn-sm btn-outline-secondary">Inspect</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Pause (suspend + force logout) this client?')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="pause_client">
                        <input type="hidden" name="client_id" value="<?= (int) $h['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Pause client</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
