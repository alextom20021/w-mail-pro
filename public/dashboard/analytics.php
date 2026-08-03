<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Analytics\AnalyticsService;
use MailAI\Core\Database;
use MailAI\Core\SessionAuth;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
$analytics = new AnalyticsService(Database::connection());

$byIsp = $analytics->byIsp($days);
$byCountry = $analytics->byCountry($days);
$byConnection = $analytics->byConnection($days);
$failures = $analytics->failureReasonBreakdown($days);

$pageTitle = 'Analytics';
$activeNav = 'analytics';
include __DIR__ . '/partials/layout_head.php';
?>

<form method="get" class="mb-3 d-flex align-items-center gap-2">
    <select name="days" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
        <?php foreach ([7, 30, 90] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?>
    </select>
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Export</button>
        <ul class="dropdown-menu">
            <?php foreach (['isp' => 'By ISP', 'country' => 'By Country', 'connection' => 'By Connection', 'failures' => 'Failure Reasons'] as $type => $label): ?>
                <li><h6 class="dropdown-header"><?= $label ?></h6></li>
                <li><a class="dropdown-item" href="/dashboard/analytics_export.php?type=<?= $type ?>&format=csv&days=<?= $days ?>">CSV</a></li>
                <li><a class="dropdown-item" href="/dashboard/analytics_export.php?type=<?= $type ?>&format=pdf&days=<?= $days ?>">PDF</a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">By ISP</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ISP</th><th>Sent</th><th>Inbox %</th><th>Open %</th><th>Click %</th><th>Bounce %</th><th>Complaint %</th></tr></thead>
                    <tbody>
                    <?php foreach ($byIsp as $r): ?>
                        <tr>
                            <td class="text-capitalize"><?= htmlspecialchars($r['isp']) ?></td>
                            <td><?= number_format((int) $r['sent']) ?></td>
                            <td><?= $r['inbox_rate_pct'] ?>%</td><td><?= $r['open_rate_pct'] ?>%</td>
                            <td><?= $r['click_rate_pct'] ?>%</td><td><?= $r['bounce_rate_pct'] ?>%</td><td><?= $r['complaint_rate_pct'] ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($byIsp)): ?><tr><td colspan="7" class="text-center text-muted py-3">No data for this range.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">By Country</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Country</th><th>Sent</th><th>Inbox %</th><th>Open %</th><th>Click %</th></tr></thead>
                    <tbody>
                    <?php foreach ($byCountry as $r): ?>
                        <tr><td><?= htmlspecialchars($r['country']) ?></td><td><?= number_format((int) $r['sent']) ?></td>
                            <td><?= $r['inbox_rate_pct'] ?>%</td><td><?= $r['open_rate_pct'] ?>%</td><td><?= $r['click_rate_pct'] ?>%</td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($byCountry)): ?><tr><td colspan="5" class="text-center text-muted py-3">No GeoIP data yet — requires the MaxMind database (see storage/geoip/README.md).</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">By Connection</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Connection</th><th>Type</th><th>Sent</th><th>Inbox %</th></tr></thead>
                    <tbody>
                    <?php foreach ($byConnection as $r): ?>
                        <tr><td><?= htmlspecialchars($r['connection_label'] ?? '—') ?></td><td class="text-uppercase small"><?= htmlspecialchars($r['connection_type'] ?? '') ?></td>
                            <td><?= number_format((int) $r['sent']) ?></td><td><?= $r['inbox_rate_pct'] ?>%</td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($byConnection)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card table-card h-100">
            <div class="card-header bg-white border-0"><h6 class="mb-0 fw-semibold">Failure Reasons</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Type</th><th>SMTP Code</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($failures as $f): ?>
                        <tr><td class="text-capitalize"><?= htmlspecialchars($f['bounce_type']) ?></td><td><?= htmlspecialchars($f['smtp_code'] ?? '—') ?></td><td><?= (int) $f['count'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($failures)): ?><tr><td colspan="3" class="text-center text-muted py-3">No bounces recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
