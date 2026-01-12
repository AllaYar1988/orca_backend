<?php
$pageTitle = 'Logs';
$currentPage = 'logs';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/DeviceLog.php';

$companyModel = new Company();
$deviceModel = new Device();
$logModel = new DeviceLog();

$companies = $companyModel->getAll();
$devices = $deviceModel->getAll();

// Get filter values
$search = $_GET['search'] ?? '';
$companyFilter = $_GET['company_id'] ?? '';
$deviceFilter = $_GET['device_id'] ?? '';
$serialFilter = $_GET['serial_number'] ?? '';
$keyFilter = $_GET['key'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$filters = [
    'limit' => $limit,
    'offset' => $offset
];

if ($search) {
    $filters['search'] = $search;
}

if ($companyFilter) {
    $filters['company_id'] = (int)$companyFilter;
}

if ($deviceFilter) {
    $filters['device_id'] = (int)$deviceFilter;
}

if ($serialFilter) {
    $filters['serial_number'] = $serialFilter;
}

if ($keyFilter) {
    $filters['log_key'] = $keyFilter;
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

include 'includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-journal-text me-2"></i>Device Logs
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="mb-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Search</label>
                    <input type="text" class="form-control" name="search" placeholder="Search key, value, serial..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Company</label>
                    <select class="form-select" name="company_id">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['id']; ?>"
                            <?php echo $companyFilter == $company['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($company['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Device</label>
                    <select class="form-select" name="device_id">
                        <option value="">All Devices</option>
                        <?php foreach ($devices as $device): ?>
                        <option value="<?php echo $device['id']; ?>"
                            <?php echo $deviceFilter == $device['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($device['name']); ?> (<?php echo htmlspecialchars($device['serial_number']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Serial Number</label>
                    <input type="text" class="form-control" name="serial_number" placeholder="Filter by serial..."
                           value="<?php echo htmlspecialchars($serialFilter); ?>">
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Key/Variable</label>
                    <input type="text" class="form-control" name="key" placeholder="Filter by key..."
                           value="<?php echo htmlspecialchars($keyFilter); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Date From</label>
                    <input type="date" class="form-control" name="date_from"
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Date To</label>
                    <input type="date" class="form-control" name="date_to"
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                    <?php if ($search || $companyFilter || $deviceFilter || $serialFilter || $keyFilter || $dateFrom || $dateTo): ?>
                    <a href="logs.php" class="btn btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Stats -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-muted">
                Found <?php echo number_format($totalCount); ?> logs
            </span>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th style="width: 140px;">Logged</th>
                        <th style="width: 140px;">Received</th>
                        <th>Device</th>
                        <th>Serial</th>
                        <th>Company</th>
                        <th>Key</th>
                        <th>Value</th>
                        <th>Data</th>
                        <th style="width: 100px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No logs found</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap small" title="Device time">
                            <?php echo date('Y-m-d H:i:s', strtotime($log['logged_at'])); ?>
                        </td>
                        <td class="text-nowrap small text-muted" title="Server received time">
                            <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                        </td>
                        <td>
                            <?php if ($log['device_name']): ?>
                            <a href="device_edit.php?id=<?php echo $log['device_id']; ?>">
                                <?php echo htmlspecialchars($log['device_name']); ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?php echo htmlspecialchars($log['serial_number']); ?></code></td>
                        <td><?php echo htmlspecialchars($log['company_name'] ?? '-'); ?></td>
                        <td><code class="small"><?php echo htmlspecialchars($log['log_key'] ?? '-'); ?></code></td>
                        <td>
                            <?php
                            $value = $log['log_value'] ?? '';
                            if (strlen($value) > 50) {
                                echo htmlspecialchars(substr($value, 0, 50)) . '...';
                            } else {
                                echo htmlspecialchars($value ?: '-');
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($log['log_data']): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                    onclick="showJsonData(<?php echo htmlspecialchars(json_encode($log['log_data'])); ?>)">
                                <i class="bi bi-braces"></i> View
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                        Previous
                    </a>
                </li>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                        Next
                    </a>
                </li>
            </ul>
        </nav>
        <p class="text-center text-muted mt-2">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalCount); ?> total logs)
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- JSON Data Modal -->
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
function showJsonData(data) {
    document.getElementById('jsonContent').textContent = JSON.stringify(data, null, 2);
    new bootstrap.Modal(document.getElementById('jsonModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>
