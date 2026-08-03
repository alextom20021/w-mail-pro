<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\Csrf;
use MailAI\Core\DomainRepository;
use MailAI\Core\SessionAuth;
use MailAI\Security\EncryptionService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

SessionAuth::requireClient();

$encryption = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
$repo = new DomainRepository($encryption);

$flash = null;
$lastVerification = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrDie();

    if (($_POST['action'] ?? '') === 'create' && !empty($_POST['domain'])) {
        $repo->create(trim($_POST['domain']));
        $flash = 'Domain added. Publish the DNS records shown below, then click Verify.';
    }

    if (($_POST['action'] ?? '') === 'verify' && !empty($_POST['domain_id'])) {
        $lastVerification = $repo->verify((int) $_POST['domain_id']);
    }
}

$domains = $repo->findAllWhere();

$pageTitle = 'Domains';
$activeNav = 'domains';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">Each domain gets its own DKIM key pair automatically. SPF/DKIM/DMARC are checked live against DNS.</p>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDomainModal"><i class="bi bi-plus-lg"></i> Add Domain</button>
</div>

<div class="card table-card mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Domain</th><th>SPF</th><th>DKIM</th><th>DMARC</th><th>Overall</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($domains as $d): ?>
                <?php $badge = fn($s) => $s === 'pass' ? '<span class="badge bg-success-subtle text-success">pass</span>' : ($s === 'fail' ? '<span class="badge bg-danger-subtle text-danger">fail</span>' : '<span class="badge bg-secondary-subtle text-secondary">unknown</span>'); ?>
                <tr>
                    <td class="fw-medium"><?= htmlspecialchars($d['domain']) ?></td>
                    <td><?= $badge($d['spf_status']) ?></td>
                    <td><?= $badge($d['dkim_status']) ?></td>
                    <td><?= $badge($d['dmarc_status']) ?></td>
                    <td><span class="badge bg-<?= $d['dns_verification_status'] === 'verified' ? 'success' : 'warning' ?>-subtle text-<?= $d['dns_verification_status'] === 'verified' ? 'success' : 'warning' ?>"><?= htmlspecialchars($d['dns_verification_status']) ?></span></td>
                    <td>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Re-check DNS</button>
                        </form>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#dns-<?= (int) $d['id'] ?>">DNS records</button>
                    </td>
                </tr>
                <tr class="collapse" id="dns-<?= (int) $d['id'] ?>"><td colspan="6">
                    <?php foreach ($repo->requiredDnsRecords((int) $d['id']) as $type => $rec): ?>
                        <div class="small mb-1"><strong class="text-uppercase"><?= $type ?></strong> — Host: <code><?= htmlspecialchars($rec['host']) ?></code> — Value: <code><?= htmlspecialchars($rec['value']) ?></code></div>
                    <?php endforeach; ?>
                </td></tr>
            <?php endforeach; ?>
            <?php if (empty($domains)): ?><tr><td colspan="6" class="text-center text-muted py-4">No domains yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addDomainModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header"><h5 class="modal-title">Add Domain</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input name="domain" class="form-control" placeholder="mail.yourdomain.com" required></div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Add & Generate DKIM Key</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/layout_foot.php'; ?>
