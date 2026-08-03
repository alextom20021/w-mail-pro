<?php

declare(strict_types=1);

/**
 * public/dashboard/onboarding.php
 *
 * Step-by-step wizard per the spec: add SMTP/API connection -> add
 * domain -> DNS verification -> warm-up starts automatically (every
 * new connection is inserted with status='warming', so there's no
 * separate "start warm-up" step — ai_cycle.php's WarmupScheduler picks
 * it up on its next run).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Csrf;
use MailAI\Core\DomainRepository;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;
use MailAI\Sending\SendingConnectionRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$connectionRepo = new SendingConnectionRepository($encryption);
$domainRepo = new DomainRepository($encryption);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();

    if (($_POST['action'] ?? '') === 'add_smtp') {
        $connectionRepo->create('smtp', $_POST['label'] ?? 'Primary SMTP', [
            'host' => $_POST['host'] ?? '', 'port' => (int) ($_POST['port'] ?? 587),
            'username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '',
            'encryption' => 'tls', 'from_email' => $_POST['from_email'] ?? '', 'from_name' => $_POST['from_name'] ?? '',
        ], 50);
    }

    if (($_POST['action'] ?? '') === 'add_domain' && !empty($_POST['domain'])) {
        $domainRepo->create(trim($_POST['domain']));
    }

    if (($_POST['action'] ?? '') === 'verify_domain' && !empty($_POST['domain_id'])) {
        $domainRepo->verify((int) $_POST['domain_id']);
    }
}

$connections = $connectionRepo->findAllWhere();
$domains = $domainRepo->findAllWhere();

$step1Done = !empty($connections);
$step2Done = !empty($domains);
$step3Done = $step2Done && ($domains[0]['dns_verification_status'] ?? '') === 'verified';

$pageTitle = 'Onboarding';
$activeNav = 'onboarding';
include __DIR__ . '/partials/layout_head.php';
?>

<div class="card table-card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge <?= $step1Done ? 'bg-success' : 'bg-secondary' ?>">1</span>
            <h5 class="mb-0">Add a sending connection</h5>
        </div>
        <p class="text-muted small">SMTP, or an API provider (SendGrid/Mailgun/SES/Postmark — add those from the Connections page). It starts in "warming" status automatically.</p>
        <?php if ($step1Done): ?>
            <div class="alert alert-success py-2 small mb-0">✓ <?= count($connections) ?> connection(s) added.</div>
        <?php else: ?>
            <form method="post" class="row g-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="add_smtp">
                <div class="col-md-4"><input name="label" class="form-control form-control-sm" placeholder="Label" required></div>
                <div class="col-md-4"><input name="host" class="form-control form-control-sm" placeholder="SMTP host" required></div>
                <div class="col-md-2"><input name="port" type="number" class="form-control form-control-sm" placeholder="Port" value="587" required></div>
                <div class="col-md-4"><input name="username" class="form-control form-control-sm" placeholder="Username" required></div>
                <div class="col-md-4"><input name="password" type="password" class="form-control form-control-sm" placeholder="Password" required></div>
                <div class="col-md-4"><input name="from_email" type="email" class="form-control form-control-sm" placeholder="From email" required></div>
                <div class="col-md-8"><input name="from_name" class="form-control form-control-sm" placeholder="From name"></div>
                <div class="col-md-4"><button class="btn btn-primary btn-sm w-100">Add Connection</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge <?= $step2Done ? 'bg-success' : 'bg-secondary' ?>">2</span>
            <h5 class="mb-0">Add your sending domain</h5>
        </div>
        <p class="text-muted small">A DKIM key pair is generated automatically when you add a domain.</p>
        <?php if ($step2Done): ?>
            <div class="alert alert-success py-2 small mb-0">✓ Domain "<?= htmlspecialchars($domains[0]['domain']) ?>" added.</div>
        <?php else: ?>
            <form method="post" class="row g-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="add_domain">
                <div class="col-md-8"><input name="domain" class="form-control form-control-sm" placeholder="mail.yourdomain.com" required></div>
                <div class="col-md-4"><button class="btn btn-primary btn-sm w-100">Add Domain</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card table-card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge <?= $step3Done ? 'bg-success' : 'bg-secondary' ?>">3</span>
            <h5 class="mb-0">Verify DNS (SPF / DKIM / DMARC)</h5>
        </div>
        <?php if ($step2Done): ?>
            <?php $d = $domains[0]; ?>
            <p class="text-muted small">Publish these TXT records at your DNS provider, then click Verify.</p>
            <?php foreach ($domainRepo->requiredDnsRecords((int) $d['id']) as $type => $rec): ?>
                <div class="small mb-1"><strong class="text-uppercase"><?= $type ?></strong> — Host: <code><?= htmlspecialchars($rec['host']) ?></code> — Value: <code><?= htmlspecialchars($rec['value']) ?></code></div>
            <?php endforeach; ?>
            <form method="post" class="mt-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="verify_domain">
                <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                <button class="btn btn-sm <?= $step3Done ? 'btn-success' : 'btn-primary' ?>"><?= $step3Done ? '✓ Verified — Re-check' : 'Verify DNS Now' ?></button>
            </form>
        <?php else: ?>
            <p class="text-muted small mb-0">Complete step 2 first.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card table-card">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge <?= $step1Done ? 'bg-success' : 'bg-secondary' ?>">4</span>
            <h5 class="mb-0">Warm-up starts automatically</h5>
        </div>
        <p class="text-muted small mb-0">
            No action needed — the AI warm-up scheduler (runs every minute) ramps your connection's daily
            volume on a conservative curve and halts the ramp automatically if bounce/complaint signals look
            bad. Track progress on the <a href="/dashboard/connections.php">Connections</a> page.
        </p>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
