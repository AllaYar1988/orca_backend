<?php
$pageTitle = 'Device Logs';
$currentPage = 'devices';

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/DeviceLog.php';

$userModel = new User();
$deviceModel = new Device();
$logModel = new DeviceLog();

// Get current user from session
require_once __DIR__ . '/includes/auth.php';
requireUserLogin();
$portalUser = getCurrentPortalUser();

// Get device ID
$deviceId = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
if (!$deviceId) {
    header('Location: devices.php');
    exit;
}

// Check if user has access to this device
if (!$userModel->hasAccessToDevice($portalUser['id'], $deviceId)) {
    header('Location: devices.php');
    exit;
}

// Get device info
$device = $deviceModel->getById($deviceId);
if (!$device) {
    header('Location: devices.php');
    exit;
}

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$logKey = isset($_GET['log_key']) ? $_GET['log_key'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$filters = [
    'device_id' => $deviceId,
    'limit' => $limit,
    'offset' => $offset
];

if ($search) {
    $filters['search'] = $search;
}
if ($logKey) {
    $filters['log_key'] = $logKey;
}
if ($dateFrom) {
    $filters['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $filters['date_to'] = $dateTo . ' 23:59:59';
}

$logs = $logModel->getAll($filters);
$totalCount = $logModel->count($filters);
$totalPages = ceil($totalCount / $limit);

// Get unique log keys for filter dropdown
$logKeys = $logModel->getUniqueKeys($deviceId);

$pageTitle = 'Logs: ' . $device['name'];

include 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-journal-text me-2"></i><?php echo htmlspecialchars($device['name']); ?></h4>
        <p class="text-muted mb-0">
            <code><?php echo htmlspecialchars($device['serial_number']); ?></code>
            <?php if ($device['device_type']): ?>
            <span class="badge bg-light text-dark ms-2"><?php echo htmlspecialchars($device['device_type']); ?></span>
            <?php endif; ?>
        </p>
    </div>
    <a href="devices.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Back to Devices
    </a>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-filter me-2"></i>Filter Logs
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="id" value="<?php echo $deviceId; ?>">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search value..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="log_key">
                    <option value="">All Keys</option>
                    <?php foreach ($logKeys as $key): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"
                        <?php echo $logKey === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($key); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" placeholder="From"
                       value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" placeholder="To"
                       value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
            </div>
            <?php if ($search || $logKey || $dateFrom || $dateTo): ?>
            <div class="col-md-1">
                <a href="device_logs.php?id=<?php echo $deviceId; ?>" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Log Entries</span>
        <span class="badge bg-primary"><?php echo number_format($totalCount); ?> total</span>
    </div>
    <div class="card-body">
        <?php if (empty($logs)): ?>
        <p class="text-muted text-center py-4 mb-0">No logs found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Key</th>
                        <th>Value</th>
                        <th>Data</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td nowrap><?php echo date('Y-m-d H:i:s', strtotime($log['logged_at'])); ?></td>
                        <td><code><?php echo htmlspecialchars($log['log_key'] ? $log['log_key'] : '-'); ?></code></td>
                        <td class="log-value" title="<?php echo htmlspecialchars($log['log_value']); ?>">
                            <?php echo htmlspecialchars($log['log_value'] ? $log['log_value'] : '-'); ?>
                        </td>
                        <td>
                            <?php if ($log['log_data']): ?>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="showJson(<?php echo htmlspecialchars(json_encode($log['log_data'])); ?>)">
                                <i class="bi bi-code-slash"></i> View
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ? $log['ip_address'] : '-'); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?id=<?php echo $deviceId; ?>&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&log_key=<?php echo urlencode($logKey); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        Previous
                    </a>
                </li>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?id=<?php echo $deviceId; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&log_key=<?php echo urlencode($logKey); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?id=<?php echo $deviceId; ?>&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&log_key=<?php echo urlencode($logKey); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        Next
                    </a>
                </li>
            </ul>
        </nav>
        <p class="text-center text-muted mt-2">
            Showing <?php echo count($logs); ?> of <?php echo number_format($totalCount); ?> logs
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- JSON Modal -->
<div class="modal fade" id="jsonModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Log Data (JSON)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="jsonContent" class="bg-light p-3 rounded" style="max-height: 400px; overflow: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
function showJson(data) {
    document.getElementById('jsonContent').textContent = JSON.stringify(data, null, 2);
    new bootstrap.Modal(document.getElementById('jsonModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>
