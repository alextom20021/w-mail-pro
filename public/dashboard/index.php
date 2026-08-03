<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Analytics\AnalyticsService;
use MailAI\Core\ClientContext;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;
use MailAI\Sending\SendingConnectionRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$db = Database::connection();
$analytics = new AnalyticsService($db);
$isp = $analytics->byIsp(7);
$series = $analytics->timeSeries(14);

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$connections = (new SendingConnectionRepository($encryption))->findAllWhere();
$warming = count(array_filter($connections, fn($c) => $c['status'] === 'warming'));
$quarantined = count(array_filter($connections, fn($c) => $c['status'] === 'quarantined'));

$totalSent = array_sum(array_column($isp, 'sent'));
$totalDelivered = array_sum(array_column($isp, 'delivered'));
$totalComplained = array_sum(array_column($isp, 'complained'));
$avgInboxRate = $totalSent > 0 ? round($totalDelivered / $totalSent * 100, 1) : 0;

// AI activity feed (spec 2.1): this client's own agent actions, newest first.
$aiFeed = $db->prepare(
    "SELECT tool_name, status, created_at FROM ai_audit_log
     WHERE client_id = :client_id ORDER BY id DESC LIMIT 8"
);
$aiFeed->execute(['client_id' => ClientContext::clientId()]);
$aiFeed = $aiFeed->fetchAll();

// Upcoming warm-up milestones (spec 2.1): next scheduled ramp targets.
$milestones = $db->prepare(
    "SELECT w.connection_id, w.target_volume, w.applies_on, s.label
     FROM warmup_schedules w
     JOIN sending_connections s ON s.id = w.connection_id
     WHERE w.client_id = :client_id AND w.applies_on >= CURDATE()
     ORDER BY w.applies_on ASC LIMIT 5"
);
$milestones->execute(['client_id' => ClientContext::clientId()]);
$milestones = $milestones->fetchAll();

$pageTitle = 'Overview';
$activeNav = 'overview';
include __DIR__ . '/partials/layout_head.php';
?>

<div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="/dashboard/campaigns.php" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i> New Campaign</a>
    <a href="/dashboard/lists.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-upload me-1"></i> Import List</a>
    <a href="/dashboard/connections.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-hdd-network me-1"></i> Add Connection</a>
    <a href="#" class="btn btn-outline-secondary btn-sm" data-bs-toggle="offcanvas" data-bs-target="#aiAssistant"><i class="bi bi-robot me-1"></i> Ask the AI to do it for you</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-soft-indigo"><i class="bi bi-send-check-fill"></i></div>
            <div><div class="text-muted small">Sent (7d)</div><div class="fs-4 fw-bold"><?= number_format($totalSent) ?></div></div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-soft-emerald"><i class="bi bi-inbox-fill"></i></div>
            <div><div class="text-muted small">Avg Inbox Rate</div><div class="fs-4 fw-bold"><?= $avgInboxRate ?>%</div></div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-soft-amber"><i class="bi bi-thermometer-half"></i></div>
            <div><div class="text-muted small">Warming Connections</div><div class="fs-4 fw-bold"><?= $warming ?></div></div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="icon-wrap bg-soft-rose"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div><div class="text-muted small">Quarantined</div><div class="fs-4 fw-bold"><?= $quarantined ?></div></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">Sends & Opens (14 days)</h6></div>
            <div class="card-body"><canvas id="seriesChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">By ISP (7 days)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ISP</th><th>Sent</th><th>Inbox %</th></tr></thead>
                    <tbody>
                    <?php foreach ($isp as $row): ?>
                        <tr><td class="text-capitalize"><?= htmlspecialchars($row['isp']) ?></td><td><?= number_format($row['sent']) ?></td><td><?= $row['inbox_rate_pct'] ?>%</td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($isp)): ?><tr><td colspan="3" class="text-muted text-center py-3">No data yet — send a campaign to see stats here.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($totalComplained > 0): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <?= $totalComplained ?> complaint(s) in the last 7 days. Check the Connections page — the AI anomaly
    detector quarantines connections automatically above threshold, but review is always worthwhile.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold"><i class="bi bi-robot text-primary me-1"></i> AI Activity Feed</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($aiFeed as $a): ?>
                        <tr>
                            <td class="text-nowrap text-muted small"><?= htmlspecialchars($a['created_at']) ?></td>
                            <td><code class="small"><?= htmlspecialchars($a['tool_name']) ?></code></td>
                            <td><span class="badge bg-<?= in_array($a['status'], ['executed', 'approved'], true) ? 'success' : ($a['status'] === 'rejected' || $a['status'] === 'failed' ? 'danger' : 'warning') ?>-subtle text-dark"><?= htmlspecialchars($a['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($aiFeed)): ?><tr><td class="text-muted text-center py-3">No AI actions yet — open the assistant (bottom-right) and ask it to set something up.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold"><i class="bi bi-thermometer-half text-warning me-1"></i> Upcoming Warm-up Milestones</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($milestones as $m): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($m['label']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($m['applies_on']) ?></td>
                            <td class="small fw-medium"><?= number_format((int) $m['target_volume']) ?>/day</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($milestones)): ?><tr><td class="text-muted text-center py-3 small">No upcoming ramp steps — warm-up milestones appear here once a warming connection is active.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('seriesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($series, 'stat_date')) ?>,
        datasets: [
            { label: 'Sent', data: <?= json_encode(array_map('intval', array_column($series, 'sent'))) ?>, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,0.1)', fill: true, tension: 0.35 },
            { label: 'Opened', data: <?= json_encode(array_map('intval', array_column($series, 'opened'))) ?>, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.1)', fill: true, tension: 0.35 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
