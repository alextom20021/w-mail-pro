<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Csrf;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;
use MailAI\Sending\SendingConnectionRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$repo = new SendingConnectionRepository($encryption);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    Csrf::verifyOrDie();
    $type = $_POST['type'] ?? 'smtp';
    $credentials = match ($type) {
        'smtp' => [
            'host' => $_POST['host'] ?? '', 'port' => (int) ($_POST['port'] ?? 587),
            'username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '',
            'encryption' => $_POST['encryption'] ?? 'tls',
            'from_email' => $_POST['from_email'] ?? '', 'from_name' => $_POST['from_name'] ?? '',
        ],
        'sendgrid' => ['api_key' => $_POST['api_key'] ?? '', 'from_email' => $_POST['from_email'] ?? '', 'from_name' => $_POST['from_name'] ?? ''],
        'mailgun' => ['api_key' => $_POST['api_key'] ?? '', 'domain' => $_POST['mg_domain'] ?? '', 'region' => $_POST['region'] ?? 'us', 'from_email' => $_POST['from_email'] ?? '', 'from_name' => $_POST['from_name'] ?? ''],
        'ses' => ['access_key' => $_POST['access_key'] ?? '', 'secret_key' => $_POST['secret_key'] ?? '', 'region' => $_POST['aws_region'] ?? 'us-east-1', 'from_email' => $_POST['from_email'] ?? '', 'from_name' => $_POST['from_name'] ?? ''],
        'postmark' => ['server_token' => $_POST['server_token'] ?? '', 'from_email' => $_POST['from_email'] ?? '', 'from_name' => $_POST['from_name'] ?? ''],
        default => [],
    };

    $repo->create($type, $_POST['label'] ?? $type, $credentials, (int) ($_POST['daily_limit'] ?? 50));
    $flash = 'Connection added — it starts in warming status and the AI warm-up scheduler will ramp it automatically.';
}

$connections = $repo->findAllWhere();

$pageTitle = 'Sending Connections';
$activeNav = 'connections';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">SMTP servers and API connections rotate together as one pool — the AI-scored rotator picks the best one per send.</p>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addConnectionModal"><i class="bi bi-plus-lg"></i> Add Connection</button>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Label</th><th>Type</th><th>Status</th><th>Warm-up Stage</th><th>Daily Limit</th><th>Sent Today</th><th>Reputation</th></tr></thead>
            <tbody>
            <?php foreach ($connections as $c): ?>
                <tr>
                    <td class="fw-medium"><?= htmlspecialchars($c['label']) ?></td>
                    <td><span class="badge bg-secondary-subtle text-secondary text-uppercase"><?= htmlspecialchars($c['type']) ?></span></td>
                    <td>
                        <?php $statusClass = ['active' => 'success', 'warming' => 'warning', 'quarantined' => 'danger', 'disabled' => 'secondary'][$c['status']] ?? 'secondary'; ?>
                        <span class="badge bg-<?= $statusClass ?>-subtle text-<?= $statusClass ?>"><?= htmlspecialchars($c['status']) ?></span>
                    </td>
                    <td><?= (int) $c['warmup_stage'] ?>/8</td>
                    <td><?= (int) $c['daily_limit'] ?></td>
                    <td><?= (int) $c['sent_today'] ?></td>
                    <td><?= number_format((float) $c['reputation_score'], 1) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($connections)): ?><tr><td colspan="7" class="text-center text-muted py-4">No connections yet — add your first SMTP server or API key.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addConnectionModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header"><h5 class="modal-title">Add Sending Connection</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select" onchange="document.querySelectorAll('.type-fields').forEach(f=>f.style.display='none'); document.getElementById('fields-'+this.value).style.display='block';">
                        <option value="smtp">SMTP</option><option value="sendgrid">SendGrid</option><option value="mailgun">Mailgun</option><option value="ses">Amazon SES</option><option value="postmark">Postmark</option>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label small">Label</label><input name="label" class="form-control" placeholder="e.g. Primary SMTP" required></div>
                <div class="mb-3"><label class="form-label small">Daily Limit</label><input type="number" name="daily_limit" class="form-control" value="50" min="1"></div>
                <div class="mb-3"><label class="form-label small">From Email</label><input name="from_email" type="email" class="form-control" required></div>
                <div class="mb-3"><label class="form-label small">From Name</label><input name="from_name" class="form-control"></div>

                <div id="fields-smtp" class="type-fields">
                    <div class="row g-2 mb-2"><div class="col-8"><input name="host" class="form-control form-control-sm" placeholder="smtp.host.com"></div><div class="col-4"><input name="port" type="number" class="form-control form-control-sm" placeholder="587" value="587"></div></div>
                    <input name="username" class="form-control form-control-sm mb-2" placeholder="Username">
                    <input name="password" type="password" class="form-control form-control-sm mb-2" placeholder="Password">
                    <select name="encryption" class="form-select form-select-sm"><option value="tls">STARTTLS</option><option value="ssl">SSL</option></select>
                </div>
                <div id="fields-sendgrid" class="type-fields" style="display:none;"><input name="api_key" class="form-control form-control-sm" placeholder="SendGrid API Key"></div>
                <div id="fields-mailgun" class="type-fields" style="display:none;">
                    <input name="api_key" class="form-control form-control-sm mb-2" placeholder="Mailgun API Key">
                    <input name="mg_domain" class="form-control form-control-sm mb-2" placeholder="mg.yourdomain.com">
                    <select name="region" class="form-select form-select-sm"><option value="us">US</option><option value="eu">EU</option></select>
                </div>
                <div id="fields-ses" class="type-fields" style="display:none;">
                    <input name="access_key" class="form-control form-control-sm mb-2" placeholder="AWS Access Key">
                    <input name="secret_key" type="password" class="form-control form-control-sm mb-2" placeholder="AWS Secret Key">
                    <input name="aws_region" class="form-control form-control-sm" placeholder="us-east-1" value="us-east-1">
                </div>
                <div id="fields-postmark" class="type-fields" style="display:none;"><input name="server_token" class="form-control form-control-sm" placeholder="Postmark Server Token"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Add Connection</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
