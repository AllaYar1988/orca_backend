<?php
$pageTitle = 'All Logs';
$currentPage = 'logs';

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/DeviceLog.php';

$userModel = new User();
$logModel = new DeviceLog();

// Get current user from session
require_once __DIR__ . '/includes/auth.php';
requireUserLogin();
$portalUser = getCurrentPortalUser();

// Get user's assigned devices
$devices = $userModel->getAssignedDevices($portalUser['id']);
$deviceIds = array_column($devices, 'id');

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$deviceFilter = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$logKey = isset($_GET['log_key']) ? $_GET['log_key'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$filters = [
    'device_ids' => $deviceIds,
    'limit' => $limit,
    'offset' => $offset
];

if ($deviceFilter && in_array($deviceFilter, $deviceIds)) {
    $filters['device_id'] = $deviceFilter;
}
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

$logs = [];
$totalCount = 0;
$totalPages = 0;

if (!empty($deviceIds)) {
    $logs = $logModel->getAll($filters);
    $totalCount = $logModel->count($filters);
    $totalPages = ceil($totalCount / $limit);
}

include 'includes/header.php';
?>

<h4 class="mb-4"><i class="bi bi-journal-text me-2"></i>All Logs</h4>

<?php if (empty($devices)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-journal-text display-1 text-muted"></i>
        <h5 class="mt-3">No Devices Assigned</h5>
        <p class="text-muted">You don't have any devices assigned to your account yet.</p>
    </div>
</div>
<?php else: ?>

<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-filter me-2"></i>Filter Logs
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <select class="form-select" name="device_id">
                    <option value="">All Devices</option>
                    <?php foreach ($devices as $device): ?>
                    <option value="<?php echo $device['id']; ?>"
                        <?php echo $deviceFilter == $device['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($device['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control" name="search" placeholder="Search value..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control" name="log_key" placeholder="Log key..."
                       value="<?php echo htmlspecialchars($logKey); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_from" placeholder="From"
                       value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control" name="date_to" placeholder="To"
                       value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <?php if ($search || $deviceFilter || $logKey || $dateFrom || $dateTo): ?>
            <div class="col-md-1">
                <a href="logs.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
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
                        <th>Device</th>
                        <th>Key</th>
                        <th>Value</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td nowrap><?php echo date('Y-m-d H:i:s', strtotime($log['logged_at'])); ?></td>
                        <td>
                            <a href="device_logs.php?id=<?php echo $log['device_id']; ?>">
                                <?php echo htmlspecialchars($log['device_name'] ? $log['device_name'] : $log['serial_number']); ?>
                            </a>
                        </td>
                        <td><code><?php echo htmlspecialchars($log['log_key'] ? $log['log_key'] : '-'); ?></code></td>
                        <td class="log-value" title="<?php echo htmlspecialchars($log['log_value']); ?>">
                            <?php echo htmlspecialchars($log['log_value'] ? $log['log_value'] : '-'); ?>
                        </td>
                        <td>
                            <?php if ($log['log_data']): ?>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="showJson(<?php echo htmlspecialchars(json_encode($log['log_data'])); ?>)">
                                <i class="bi bi-code-slash"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
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
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&device_id=<?php echo $deviceFilter; ?>&search=<?php echo urlencode($search); ?>&log_key=<?php echo urlencode($logKey); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        Previous
                    </a>
                </li>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&device_id=<?php echo $deviceFilter; ?>&search=<?php echo urlencode($search); ?>&log_key=<?php echo urlencode($logKey); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&device_id=<?php echo $deviceFilter; ?>&search=<?php echo urlencode($search); ?>&log_key=<?php echo urlencode($logKey); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
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

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
