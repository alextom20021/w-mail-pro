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

$pageTitle = 'Overview';
$activeNav = 'overview';
include __DIR__ . '/partials/layout_head.php';
?>

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
