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

    if (($_POST['action'] ?? '') === 'link_cloudflare' && !empty($_POST['domain_id'])) {
        try {
            $repo->linkCloudflare((int) $_POST['domain_id'], trim($_POST['cf_token'] ?? ''), trim($_POST['cf_zone_id'] ?? ''));
            $flash = 'Cloudflare linked. Use "Auto-apply DNS records" to publish SPF/DKIM/DMARC directly.';
        } catch (\RuntimeException $e) {
            $flash = null;
            $cfError = $e->getMessage();
        }
    }

    if (($_POST['action'] ?? '') === 'unlink_cloudflare' && !empty($_POST['domain_id'])) {
        $repo->unlinkDnsProvider((int) $_POST['domain_id']);
        $flash = 'Cloudflare unlinked — DNS records revert to manual copy/paste.';
    }

    if (($_POST['action'] ?? '') === 'auto_apply_dns' && !empty($_POST['domain_id'])) {
        try {
            $repo->autoApplyDnsRecords((int) $_POST['domain_id']);
            $flash = 'DNS records published via Cloudflare. Click "Re-check DNS" in a minute or two once propagation catches up.';
        } catch (\RuntimeException $e) {
            $cfError = $e->getMessage();
        }
    }
}

$domains = $repo->findAllWhere();

$pageTitle = 'Domains';
$activeNav = 'domains';
include __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($cfError)): ?><div class="alert alert-danger"><?= htmlspecialchars($cfError) ?></div><?php endif; ?>

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
                    <hr class="my-2">
                    <?php if (!empty($d['dns_provider'])): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-info-subtle text-info"><i class="bi bi-cloud-check"></i> Cloudflare linked</span>
                            <form method="post" class="d-inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="auto_apply_dns">
                                <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-sm btn-primary" type="submit">Auto-apply DNS records</button>
                            </form>
                            <form method="post" class="d-inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="unlink_cloudflare">
                                <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Unlink</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="post" class="row g-2 align-items-end">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="link_cloudflare">
                            <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                            <div class="col-auto"><label class="form-label small mb-0">Cloudflare API Token</label><input type="password" name="cf_token" class="form-control form-control-sm" placeholder="Zone:DNS:Edit scoped token"></div>
                            <div class="col-auto"><label class="form-label small mb-0">Zone ID</label><input name="cf_zone_id" class="form-control form-control-sm" placeholder="Zone ID"></div>
                            <div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Link Cloudflare</button></div>
                        </form>
                        <div class="form-text">Optional — lets the platform publish these records for you instead of copy/paste.</div>
                    <?php endif; ?>
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
