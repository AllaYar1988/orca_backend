<?php
$pageTitle = 'Simu Licences';
$currentPage = 'simu_licenses';

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/../models/SimuLicense.php';
require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../services/SimuSigner.php';

$model     = new SimuLicense();
$adminUser = getCurrentUser();
$actor     = 'admin' . (!empty($adminUser['username']) ? ':' . $adminUser['username'] : '');
$adminIp   = $_SERVER['REMOTE_ADDR'] ?? null;
$flash     = null;

// ---- download: the offline path, before any HTML ---------------------------
// The file the customer drops beside Simu.exe. Only an active row has one.
if (isset($_GET['download'])) {
    $row = $model->getById((int)$_GET['download']);
    if (!$row || $row['status'] !== SimuLicense::ACTIVE || !$row['license_doc']) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "No licence document for that row.\n";
        exit;
    }
    $pretty = json_encode(json_decode($row['license_doc'], true),
                          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="license.json"');
    header('Content-Length: ' . strlen($pretty));
    echo $pretty;
    exit;
}

function simuSignerOrFlash(&$flash) {
    try {
        return new SimuSigner();
    } catch (Exception $e) {
        $flash = ['danger', 'Signing key not available: ' . htmlspecialchars($e->getMessage())];
        return null;
    }
}

// ---- actions ---------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id'])) {
    $signer = simuSignerOrFlash($flash);
    if ($signer) {
        try {
            $row = $model->approve((int)$_POST['approve_id'], $signer, $actor, $adminIp);
            header('Location: simu_licenses.php?approved=' . (int)$row['id']);
            exit;
        } catch (SimuLicenseRefused $e) {
            $flash = ['danger', 'Not approved: ' . htmlspecialchars($e->getMessage())];
        } catch (Exception $e) {
            $flash = ['danger', 'Approval failed: ' . htmlspecialchars($e->getMessage())];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_id'])) {
    try {
        $note = trim((string)($_POST['reject_note'] ?? '')) ?: null;
        $row = $model->reject((int)$_POST['reject_id'], $actor, $adminIp, $note);
        header('Location: simu_licenses.php?rejected=' . (int)$row['id']);
        exit;
    } catch (SimuLicenseRefused $e) {
        $flash = ['danger', 'Not rejected: ' . htmlspecialchars($e->getMessage())];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_id'])) {
    try {
        $row = $model->disable((int)$_POST['disable_id'], $actor, $adminIp);
        header('Location: simu_licenses.php?disabled=' . (int)$row['id']);
        exit;
    } catch (SimuLicenseRefused $e) {
        $flash = ['danger', 'Not disabled: ' . htmlspecialchars($e->getMessage())];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    try {
        $row = $model->restore((int)$_POST['restore_id'], $actor, $adminIp);
        header('Location: simu_licenses.php?restored=' . (int)$row['id']);
        exit;
    } catch (SimuLicenseRefused $e) {
        $flash = ['danger', 'Not restored: ' . htmlspecialchars($e->getMessage())];
    }
}

// Issue by hand: the customer emailed the Machine ID. The row is born
// active; download the file from the flash message and email it back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_machine_id'])) {
    $mid      = strtoupper(trim((string)$_POST['issue_machine_id']));
    $customer = trim((string)($_POST['issue_customer'] ?? ''));
    $company  = (int)($_POST['issue_company_id'] ?? 0) ?: null;
    $notes    = substr(trim((string)($_POST['issue_notes'] ?? '')), 0, 255) ?: null;
    if (!SimuSigner::isValidMachineId($mid)) {
        $flash = ['danger', 'Machine ID must be 32 hex characters, as Simu shows it'];
    } elseif (!SimuSigner::isValidCustomer($customer)) {
        $flash = ['danger', 'Customer must be 1-100 printable characters'];
    } else {
        $signer = simuSignerOrFlash($flash);
        if ($signer) {
            try {
                $row = $model->issueManual($mid, $customer, $company, $notes, $signer, $actor, $adminIp);
                header('Location: simu_licenses.php?issued=' . (int)$row['id']);
                exit;
            } catch (SimuLicenseRefused $e) {
                $flash = ['danger', 'Not issued: ' . htmlspecialchars($e->getMessage())];
            } catch (Exception $e) {
                $flash = ['danger', 'Issue failed: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
}

// ---- listing ---------------------------------------------------------------

$search       = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$filters = ['limit' => $limit, 'offset' => $offset];
if ($search) {
    $filters['search'] = $search;
}
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}

$rows        = $model->getAll($filters);
$totalCount  = $model->count($filters);
$totalPages  = ceil($totalCount / $limit);
$stats       = $model->stats();
$pendingRows = $model->pending();

$detail  = isset($_GET['id']) ? $model->getById((int)$_GET['id']) : null;
$history = $detail ? $model->historyFor($detail['id']) : [];
$detailFields = ($detail && $detail['license_doc']) ? SimuSigner::decodeDocument($detail['license_doc']) : null;

$companies = (new Company())->getAll();

$signerState = 'ok';
$signerNote  = '';
try {
    $signer = new SimuSigner();
    $signerNote = $signer->keyPath();
} catch (Exception $e) {
    $signerState = 'missing';
    $signerNote  = $e->getMessage();
}

$appKeySet = trim((string)env('SIMU_APP_KEY', '')) !== '';

function statusBadge($status) {
    $map = [
        'pending'  => ['warning text-dark', 'pending'],
        'active'   => ['success', 'active'],
        'rejected' => ['secondary', 'rejected'],
        'disabled' => ['danger', 'disabled'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', $status];
    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function whenAgo($ts) {
    if (!$ts) {
        return '—';
    }
    $t = strtotime($ts);
    $d = time() - $t;
    if ($d < 90) {
        return 'just now';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' min ago';
    }
    if ($d < 86400 * 2) {
        return floor($d / 3600) . ' h ago';
    }
    return date('Y-m-d H:i', $t);
}

include 'includes/header.php';
?>

<?php if (isset($_GET['approved'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Licence <strong>#<?php echo (int)$_GET['approved']; ?></strong> approved. Simu on that PC picks it up on its next check (within 30 seconds while its activation page is open).
    If the PC is offline, <a href="simu_licenses.php?download=<?php echo (int)$_GET['approved']; ?>">download license.json</a> and email it.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['issued'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Licence <strong>#<?php echo (int)$_GET['issued']; ?></strong> issued.
    <a href="simu_licenses.php?download=<?php echo (int)$_GET['issued']; ?>" class="alert-link"><i class="bi bi-download"></i> Download license.json</a>
    and email it to the customer - they upload it on Simu's activation page or copy it next to Simu.exe.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['rejected'])): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    Request <strong>#<?php echo (int)$_GET['rejected']; ?></strong> rejected. Simu shows the customer your note; if they ask again the row comes back here as pending.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['disabled'])): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    Licence <strong>#<?php echo (int)$_GET['disabled']; ?></strong> disabled. That PC is refused from now on; the file already on it keeps working until there is an expiry to enforce.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['restored'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Licence <strong>#<?php echo (int)$_GET['restored']; ?></strong> restored.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash[0]; ?> alert-dismissible fade show" role="alert">
    <?php echo $flash[1]; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($signerState !== 'ok'): ?>
<div class="alert alert-danger" role="alert">
    <i class="bi bi-key"></i> <strong>Signing key not available</strong> — nothing can be approved or issued until it is.
    <div class="small mt-1"><?php echo htmlspecialchars($signerNote); ?></div>
</div>
<?php endif; ?>
<?php if (!$appKeySet): ?>
<div class="alert alert-warning" role="alert">
    <i class="bi bi-plug"></i> <strong>SIMU_APP_KEY is not set in .env</strong> — Simu cannot send requests; only the manual path below works.
</div>
<?php endif; ?>

<!-- The numbers -->
<div class="row mb-4">
  <?php
    $cards = [
      ['Waiting for approval', $stats['pending'],    'bi-hourglass-split', 'warning',   'requests from Simu not yet decided'],
      ['Licensed PCs',         $stats['active'],     'bi-pc-display',      'success',   'one licence per computer'],
      ['This month',           $stats['this_month'], 'bi-calendar-plus',   'primary',   'approved or issued since the 1st'],
      ['Rejected',             $stats['rejected'],   'bi-x-circle',        'secondary', 'refused; asking again re-opens them'],
      ['Disabled',             $stats['disabled'],   'bi-slash-circle',    'danger',    'revoked by an admin'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-6 col-lg">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="text-muted small"><?php echo $c[0]; ?></div>
              <div class="fs-3 fw-semibold"><?php echo (int)$c[1]; ?></div>
            </div>
            <i class="bi <?php echo $c[2]; ?> fs-4 text-<?php echo $c[3]; ?>"></i>
          </div>
          <div class="text-muted" style="font-size:.75rem"><?php echo $c[4]; ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-4">
  <div class="card-body py-2 d-flex flex-wrap gap-4 align-items-center small">
    <span><i class="bi bi-key"></i> Signing key:
      <?php if ($signerState === 'ok'): ?>
        <span class="text-success">ready</span> <code><?php echo htmlspecialchars($signerNote); ?></code>
      <?php else: ?>
        <span class="text-danger">not available</span>
      <?php endif; ?>
    </span>
    <span><i class="bi bi-plug"></i> App key: <?php echo $appKeySet ? '<span class="text-success">set</span>' : '<span class="text-danger">not set</span>'; ?></span>
    <span class="text-muted">Both live in <code>.env</code> (<code>SIMU_LICENSE_KEY_PATH</code>, <code>SIMU_APP_KEY</code>).</span>
    <?php if ($stats['last_approved_at']): ?>
      <span class="text-muted">Last approved <?php echo date('Y-m-d H:i', strtotime($stats['last_approved_at'])); ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- Pending requests: the queue -->
<?php if ($pendingRows): ?>
<div class="card mb-4 border-warning">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Waiting for approval</h5>
        <span class="badge bg-warning text-dark"><?php echo count($pendingRows); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Customer</th><th>Contact</th><th>PC</th><th>Machine ID</th><th>Asked</th><th>Still checking?</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pendingRows as $p): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($p['customer']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($p['contact'] ?? '—'); ?></td>
                        <td class="small"><?php echo htmlspecialchars($p['hostname'] ?? '—'); ?>
                            <?php if ($p['simu_version']): ?><span class="text-muted">· Simu <?php echo htmlspecialchars($p['simu_version']); ?></span><?php endif; ?></td>
                        <td class="font-monospace small"><a href="simu_licenses.php?id=<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['machine_id']); ?></a></td>
                        <td class="small text-muted" title="<?php echo htmlspecialchars($p['requested_at'] ?? ''); ?>"><?php echo whenAgo($p['requested_at']); ?><br><span style="font-size:.75rem">from <?php echo htmlspecialchars($p['ip_address'] ?? '—'); ?></span></td>
                        <td class="small text-muted"><?php echo $p['last_poll_at'] ? 'last check ' . whenAgo($p['last_poll_at']) : 'not yet'; ?></td>
                        <td class="text-nowrap">
                            <form method="POST" class="d-inline" onsubmit="return confirm('Approve <?php echo htmlspecialchars(addslashes($p['customer'])); ?> on this PC?');">
                                <input type="hidden" name="approve_id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-success" <?php echo $signerState === 'ok' ? '' : 'disabled'; ?>><i class="bi bi-check-lg"></i> Approve</button>
                            </form>
                            <form method="POST" class="d-inline-flex gap-1 ms-1" onsubmit="return confirm('Reject this request?');">
                                <input type="hidden" name="reject_id" value="<?php echo (int)$p['id']; ?>">
                                <input type="text" name="reject_note" class="form-control form-control-sm" placeholder="reason (shown in Simu)" style="width:12rem">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted small">
        Each row is a PC that asked from Simu's activation page. Approve signs the licence for that Machine ID; the page on the PC
        checks every 30 seconds and unlocks itself. A row whose last check is old is a customer who closed the page - the
        licence still waits for them, and Simu asks again at its next start.
    </div>
</div>
<?php endif; ?>

<!-- One licence in detail -->
<?php if ($detail): ?>
<div class="card mb-4 border-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-pc-display"></i> Licence #<?php echo (int)$detail['id']; ?> — <?php echo htmlspecialchars($detail['customer']); ?> <?php echo statusBadge($detail['status']); ?></h5>
        <a href="simu_licenses.php" class="btn btn-sm btn-outline-secondary">Close</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted fw-normal" style="width:40%">Machine ID</th><td class="font-monospace"><?php echo htmlspecialchars($detail['machine_id']); ?></td></tr>
                    <tr><th class="text-muted fw-normal">Customer</th><td><?php echo htmlspecialchars($detail['customer']); ?>
                        <?php if ($detail['company_name']): ?><span class="text-muted">· account: <?php echo htmlspecialchars($detail['company_name']); ?></span><?php endif; ?></td></tr>
                    <tr><th class="text-muted fw-normal">Contact</th><td><?php echo htmlspecialchars($detail['contact'] ?? '—'); ?></td></tr>
                    <tr><th class="text-muted fw-normal">PC</th><td><?php echo htmlspecialchars($detail['hostname'] ?? '—'); ?> <?php if ($detail['simu_version']): ?><span class="text-muted">· Simu <?php echo htmlspecialchars($detail['simu_version']); ?></span><?php endif; ?></td></tr>
                    <tr><th class="text-muted fw-normal">Source</th><td><?php echo $detail['source'] === 'manual' ? 'issued by hand (Machine ID typed here)' : 'requested from Simu'; ?></td></tr>
                    <tr><th class="text-muted fw-normal">Requested</th><td><?php echo $detail['requested_at'] ? date('Y-m-d H:i', strtotime($detail['requested_at'])) : '—'; ?> <span class="text-muted small">from <?php echo htmlspecialchars($detail['ip_address'] ?? '—'); ?></span></td></tr>
                    <tr><th class="text-muted fw-normal">Approved</th><td><?php echo $detail['approved_at'] ? date('Y-m-d H:i', strtotime($detail['approved_at'])) : '—'; ?></td></tr>
                    <tr><th class="text-muted fw-normal">Checks / re-asks</th><td><?php echo (int)$detail['poll_count']; ?> / <?php echo (int)$detail['reissue_count']; ?> <span class="text-muted small">(last <?php echo whenAgo($detail['last_poll_at']); ?>)</span></td></tr>
                    <?php if ($detailFields): ?>
                    <tr><th class="text-muted fw-normal">Signed payload</th><td class="font-monospace small">v<?php echo (int)$detailFields['v']; ?>, issued <?php echo date('Y-m-d H:i', (int)$detailFields['issued_utc']); ?> UTC</td></tr>
                    <?php endif; ?>
                    <?php if ($detail['notes']): ?>
                    <tr><th class="text-muted fw-normal">Notes</th><td><?php echo htmlspecialchars($detail['notes']); ?></td></tr>
                    <?php endif; ?>
                </table>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <?php if ($detail['status'] === 'active'): ?>
                        <a href="simu_licenses.php?download=<?php echo (int)$detail['id']; ?>" class="btn btn-sm btn-primary"><i class="bi bi-download"></i> Download license.json</a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Disable this licence? The PC will be refused from now on.');">
                            <input type="hidden" name="disable_id" value="<?php echo (int)$detail['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-slash-circle"></i> Disable</button>
                        </form>
                    <?php elseif ($detail['status'] === 'disabled'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="restore_id" value="<?php echo (int)$detail['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Approve this PC?');">
                            <input type="hidden" name="approve_id" value="<?php echo (int)$detail['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success" <?php echo $signerState === 'ok' ? '' : 'disabled'; ?>><i class="bi bi-check-lg"></i> Approve</button>
                        </form>
                        <?php if ($detail['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline-flex gap-1" onsubmit="return confirm('Reject this request?');">
                            <input type="hidden" name="reject_id" value="<?php echo (int)$detail['id']; ?>">
                            <input type="text" name="reject_note" class="form-control form-control-sm" placeholder="reason (shown in Simu)" style="width:14rem">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Reject</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small mb-1">History</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>When</th><th>Event</th><th>By</th><th>Note</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="small text-muted text-nowrap"><?php echo date('Y-m-d H:i', strtotime($h['created_at'])); ?></td>
                                <td class="small"><?php echo htmlspecialchars($h['event']); ?><?php if ($h['event'] === 'requested' && $h['customer']): ?> <span class="text-muted">as <?php echo htmlspecialchars($h['customer']); ?></span><?php endif; ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($h['actor'] ?? '—'); ?></td>
                                <td class="small"><?php echo htmlspecialchars($h['note'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?><tr><td colspan="4" class="text-muted small">nothing yet</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Issue by hand: the offline path -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-envelope"></i> Issue for a Machine ID sent by email</h5>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Machine ID (32 hex, from Simu's activation page)</label>
                <input type="text" name="issue_machine_id" class="form-control font-monospace" placeholder="C5902C7CE3377C21FF770464B37CF963" pattern="[0-9A-Fa-f]{32}" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Customer</label>
                <input type="text" name="issue_customer" class="form-control" placeholder="Acme Water Ltd" maxlength="100" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Account (optional)</label>
                <select name="issue_company_id" class="form-select">
                    <option value="">—</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Notes</label>
                <input type="text" name="issue_notes" class="form-control" maxlength="255">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-outline-primary w-100" <?php echo $signerState === 'ok' ? '' : 'disabled'; ?>><i class="bi bi-key"></i> Issue</button>
            </div>
        </form>
        <div class="form-text mt-2">
            For a PC with no internet. The customer reads the Machine ID off Simu's activation page ("No internet on this PC?")
            and emails it; you issue here, download <code>license.json</code> from the message that follows, and email it back.
            They upload it on the same page, or copy it next to <code>Simu.exe</code>.
        </div>
    </div>
</div>

<!-- Every licence -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-pc-display"></i> Licences</h5>
        <span class="badge bg-primary"><?php echo $totalCount; ?></span>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Customer, Machine ID, PC name or contact" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">Any status</option>
                    <?php foreach (SimuLicense::STATUSES as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="simu_licenses.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Machine ID</th>
                        <th>PC</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Approved</th>
                        <th>Checks</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-muted"><?php echo (int)$r['id']; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($r['customer']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($r['contact'] ?? ''); ?>
                                <?php if ($r['company_name']): ?><span title="account"><i class="bi bi-building"></i> <?php echo htmlspecialchars($r['company_name']); ?></span><?php endif; ?></div>
                        </td>
                        <td class="font-monospace small"><a href="simu_licenses.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['machine_id']); ?></a>
                            <?php if ($r['source'] === 'manual'): ?><span class="badge bg-light text-dark border" title="Machine ID typed here">manual</span><?php endif; ?></td>
                        <td class="small"><?php echo htmlspecialchars($r['hostname'] ?? '—'); ?>
                            <?php if ($r['simu_version']): ?><div class="text-muted">Simu <?php echo htmlspecialchars($r['simu_version']); ?></div><?php endif; ?></td>
                        <td><?php echo statusBadge($r['status']); ?></td>
                        <td class="small text-muted"><?php echo $r['requested_at'] ? date('Y-m-d H:i', strtotime($r['requested_at'])) : '—'; ?></td>
                        <td class="small text-muted"><?php echo $r['approved_at'] ? date('Y-m-d H:i', strtotime($r['approved_at'])) : '—'; ?></td>
                        <td class="small text-muted" title="polls / re-asks"><?php echo (int)$r['poll_count']; ?><?php if ($r['reissue_count']): ?> <span class="text-secondary">+<?php echo (int)$r['reissue_count']; ?></span><?php endif; ?></td>
                        <td class="text-nowrap">
                            <?php if ($r['status'] === 'active'): ?>
                                <a href="simu_licenses.php?download=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary" title="Download license.json"><i class="bi bi-download"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Disable this licence?');">
                                    <input type="hidden" name="disable_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Disable"><i class="bi bi-slash-circle"></i></button>
                                </form>
                            <?php elseif ($r['status'] === 'disabled'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="restore_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            <?php else: ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Approve this PC?');">
                                    <input type="hidden" name="approve_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Approve" <?php echo $signerState === 'ok' ? '' : 'disabled'; ?>><i class="bi bi-check-lg"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No licences<?php echo ($search || $statusFilter !== '') ? ' match' : ' yet'; ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    <div class="card-footer text-muted small">
        One row per PC, for ever: Simu asking again returns the same row, and the same signed document once there is one.
        A new motherboard or a reinstalled Windows is a new Machine ID and turns up as a new request - approve it and disable
        the old row.
    </div>
</div>

<?php include 'includes/footer.php'; ?>
