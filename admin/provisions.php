<?php
$pageTitle = 'Provisioning';
$currentPage = 'provisions';

require_once __DIR__ . '/../models/Provision.php';
require_once __DIR__ . '/../services/GrantSigner.php';

$provModel    = new Provision();

// ---- actions ---------------------------------------------------------------

$flash = null;

// Retire a chip's grant (MCU replaced). Deliberate, admin-only: this is the
// one action that lets a serial move to another chip.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retire_id'])) {
    $row = $provModel->getById((int)$_POST['retire_id']);
    if ($row && $row['retired_at'] === null) {
        $provModel->retire($row['id']);
        header('Location: provisions.php?retired=' . urlencode($row['serial_number']));
        exit;
    }
}

// Issue a grant for a replacement chip, carrying an existing serial. Retires
// whatever chip held it before. Does not count against quota - the serial
// was already paid for.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['replace_serial'])) {
    $serial = trim($_POST['replace_serial']);
    $uid    = strtoupper(trim($_POST['replace_uid'] ?? ''));
    $old    = $provModel->findLiveBySerial($serial);
    if (!$old) {
        $flash = ['danger', "No live grant holds serial " . htmlspecialchars($serial)];
    } elseif (!GrantSigner::isValidUid($uid)) {
        $flash = ['danger', "New UID must be 24 hex characters"];
    } elseif ($provModel->findByUid($uid)) {
        $flash = ['danger', "Chip $uid is already provisioned"];
    } else {
        try {
            $signer = new GrantSigner();
            $db = $provModel->getConnection();
            $db->beginTransaction();
            $issued = time();
            $grant  = $signer->signBase64($uid, $serial, (int)$old['company_id'], $issued);
            $newId  = $provModel->create([
                'company_id'    => $old['company_id'],
                'uid'           => $uid,
                'serial_number' => $serial,
                'grant_b64'     => $grant,
                'issued_utc'    => $issued,
                'tool'          => 'admin',
                'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $provModel->retire($old['id'], $newId);
            $db->commit();
            header('Location: provisions.php?replaced=' . urlencode($serial) . '&search=' . urlencode($uid));
            exit;
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $flash = ['danger', 'Replacement failed: ' . htmlspecialchars($e->getMessage())];
        }
    }
}

// ---- listing ---------------------------------------------------------------

$search     = $_GET['search'] ?? '';
$liveFilter = $_GET['live'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$filters = ['limit' => $limit, 'offset' => $offset];
if ($search) {
    $filters['search'] = $search;
}
if ($liveFilter !== '') {
    $filters['live'] = (int)$liveFilter;
}

$rows       = $provModel->getAll($filters);
$totalCount = $provModel->count($filters);
$totalPages = ceil($totalCount / $limit);

$stats = $provModel->stats();

// The cross-check reads a VIEW the migration creates. If it is not there yet
// - migration not run, or a hosting DB user without CREATE VIEW - say so in
// the page rather than take the whole page down with it.
$unprovisioned = [];
$crossCheckError = null;
try {
    $unprovisioned = $provModel->unprovisionedDevices();
} catch (Exception $e) {
    $crossCheckError = $e->getMessage();
}

$signerState = 'ok';
$signerNote  = '';
try {
    $signer = new GrantSigner();
    $signerNote = $signer->keyPath();
} catch (Exception $e) {
    $signerState = 'missing';
    $signerNote  = $e->getMessage();
}

include 'includes/header.php';
?>

<?php if (isset($_GET['retired'])): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    Grant for serial <strong><?php echo htmlspecialchars($_GET['retired']); ?></strong> retired. That chip can no longer be re-provisioned; issue for the replacement below.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['replaced'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Serial <strong><?php echo htmlspecialchars($_GET['replaced']); ?></strong> moved to the new chip. Copy its grant from the row below and install it with <code>license &lt;grant&gt;</code>.
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
    <i class="bi bi-key"></i> <strong>Signing key not available</strong> — provisioning will fail until it is.
    <div class="small mt-1"><?php echo htmlspecialchars($signerNote); ?></div>
</div>
<?php endif; ?>

<!-- The production numbers -->
<div class="row mb-4">
  <?php
    $cards = [
      ['Devices provisioned', $stats['live'],       'bi-cpu',          'primary', 'live grants - one per chip'],
      ['This month',          $stats['this_month'], 'bi-calendar-plus','success', 'issued since the 1st'],
      ['Re-issues',           $stats['reissues'],   'bi-arrow-repeat', 'secondary','re-flashes and wiped configs - free'],
      ['Retired',             $stats['retired'],    'bi-x-circle',     'warning', 'chips replaced'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3">
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
    <span class="text-muted">Serial prefix and the provisioning key live in <code>.env</code>
      (<code>PROVISION_SERIAL_PREFIX</code>, <code>PROVISION_KEY</code>).</span>
    <?php if ($stats['last_issued_at']): ?>
      <span class="text-muted">Last issued <?php echo date('Y-m-d H:i', strtotime($stats['last_issued_at'])); ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- Built outside the process -->
<?php if ($crossCheckError !== null): ?>
<div class="alert alert-warning" role="alert">
    <i class="bi bi-exclamation-triangle"></i> Field cross-check unavailable — the <code>unprovisioned_devices</code> view is missing. Run <code>database/migration_provisioning.sql</code> (the DB user needs CREATE VIEW).
    <div class="small text-muted mt-1"><?php echo htmlspecialchars($crossCheckError); ?></div>
</div>
<?php endif; ?>
<?php if ($unprovisioned): ?>
<div class="card mb-4 border-warning">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-warning"></i> Seen in the field, never provisioned</h5>
        <span class="badge bg-warning text-dark"><?php echo count($unprovisioned); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Serial</th><th>Company</th><th>Last seen</th><th>Registered</th></tr>
                </thead>
                <tbody>
                <?php foreach ($unprovisioned as $d): ?>
                    <tr>
                        <td class="font-monospace"><a href="device_edit.php?id=<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['serial_number']); ?></a></td>
                        <td><?php echo htmlspecialchars($d['company_name'] ?? '—'); ?></td>
                        <td class="text-muted small"><?php echo $d['last_seen_at'] ? date('Y-m-d H:i', strtotime($d['last_seen_at'])) : 'never'; ?></td>
                        <td class="text-muted small"><?php echo date('Y-m-d', strtotime($d['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted small">
        Devices that connected to this server with a serial no grant was ever issued for. Existing units from before licensing appear here too — the list shrinks as they are re-provisioned, and what remains is worth a question.
    </div>
</div>
<?php endif; ?>

<!-- Replacement chip -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> Replace a chip, keep the serial</h5>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end" onsubmit="return confirm('Retire the current chip for this serial and issue its grant to the new UID?');">
            <div class="col-md-3">
                <label class="form-label small">Serial to keep</label>
                <input type="text" name="replace_serial" class="form-control font-monospace" placeholder="A2000137" required>
            </div>
            <div class="col-md-5">
                <label class="form-label small">New chip UID (24 hex)</label>
                <input type="text" name="replace_uid" class="form-control font-monospace" placeholder="203530473932501800370043" pattern="[0-9A-Fa-f]{24}" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-key"></i> Issue for replacement</button>
            </div>
        </form>
        <div class="form-text mt-2">
            For a repaired board with a new MCU. The old chip's grant is retired, the serial is signed to the new UID, and the company's quota is untouched. Deliberately not something Orca can do on its own.
        </div>
    </div>
</div>

<!-- Every grant -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-cpu"></i> Grants issued</h5>
        <span class="badge bg-primary"><?php echo $totalCount; ?></span>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="UID or serial" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select name="live" class="form-select">
                    <option value="">Live and retired</option>
                    <option value="1" <?php echo $liveFilter === '1' ? 'selected' : ''; ?>>Live only</option>
                    <option value="0" <?php echo $liveFilter === '0' ? 'selected' : ''; ?>>Retired only</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="provisions.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Serial</th>
                        <th>UID</th>
                        <th>Issued</th>
                        <th>Tool</th>
                        <th class="text-end">Re-issued</th>
                        <th>Grant</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No grants match.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?php echo $r['retired_at'] ? 'table-secondary text-muted' : ''; ?>">
                        <td class="font-monospace">
                            <?php echo htmlspecialchars($r['serial_number']); ?>
                            <?php if ($r['retired_at']): ?><span class="badge bg-secondary">retired</span><?php endif; ?>
                        </td>
                        <td class="font-monospace small"><?php echo htmlspecialchars($r['uid']); ?></td>
                        <td class="small"><?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?></td>
                        <td class="small text-muted">
                            <?php echo htmlspecialchars(trim(($r['tool'] ?? '') . ' ' . ($r['tool_version'] ?? ''))) ?: '—'; ?>
                            <?php if ($r['fw_version']): ?><br>fw <?php echo htmlspecialchars($r['fw_version']); ?><?php endif; ?>
                        </td>
                        <td class="text-end"><?php echo (int)$r['reissue_count']; ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyGrant(this, '<?php echo htmlspecialchars($r['grant_b64']); ?>')" title="Copy the grant - install with: license <grant>">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </td>
                        <td class="text-end">
                            <?php if (!$r['retired_at']): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Retire the grant for <?php echo htmlspecialchars($r['serial_number']); ?>? The chip <?php echo htmlspecialchars($r['uid']); ?> can then never be re-provisioned.');">
                                <input type="hidden" name="retire_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Retire (MCU replaced)"><i class="bi bi-x-circle"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&live=<?php echo urlencode($liveFilter); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
function copyGrant(btn, grant) {
    navigator.clipboard.writeText(grant).then(function () {
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
