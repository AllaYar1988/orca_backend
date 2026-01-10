<?php
$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/DeviceLog.php';

$companyModel = new Company();
$deviceModel = new Device();
$logModel = new DeviceLog();

$totalCompanies = $companyModel->count();
$activeCompanies = $companyModel->count(['is_active' => 1]);
$totalDevices = $deviceModel->count();
$activeDevices = $deviceModel->count(['is_active' => 1]);
$totalLogs = $logModel->count();
$todayLogs = $logModel->count(['date_from' => date('Y-m-d 00:00:00')]);

$recentLogs = $logModel->getAll(['limit' => 10]);

include 'includes/header.php';
?>

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="stats-card">
            <div class="d-flex align-items-center gap-3">
                <div class="icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-building"></i>
                </div>
                <div>
                    <div class="value"><?php echo $totalCompanies; ?></div>
                    <div class="label">Total Companies</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="stats-card">
            <div class="d-flex align-items-center gap-3">
                <div class="icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-hdd-stack"></i>
                </div>
                <div>
                    <div class="value"><?php echo $totalDevices; ?></div>
                    <div class="label">Total Devices</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="stats-card">
            <div class="d-flex align-items-center gap-3">
                <div class="icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-journal-text"></i>
                </div>
                <div>
                    <div class="value"><?php echo number_format($totalLogs); ?></div>
                    <div class="label">Total Logs</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="stats-card">
            <div class="d-flex align-items-center gap-3">
                <div class="icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-activity"></i>
                </div>
                <div>
                    <div class="value"><?php echo number_format($todayLogs); ?></div>
                    <div class="label">Logs Today</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Recent Logs</span>
                <a href="logs.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Device</th>
                                <th>Company</th>
                                <th>Key</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLogs)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No logs yet</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="text-nowrap"><?php echo date('Y-m-d H:i:s', strtotime($log['logged_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['device_name'] ?? $log['serial_number']); ?></td>
                                <td><?php echo htmlspecialchars($log['company_name'] ?? '-'); ?></td>
                                <td><code><?php echo htmlspecialchars($log['log_key'] ?? '-'); ?></code></td>
                                <td><?php echo htmlspecialchars(substr($log['log_value'] ?? '-', 0, 50)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Quick Actions</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="company_create.php" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>Create Company
                    </a>
                    <a href="device_create.php" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>Create Device
                    </a>
                    <a href="logs.php" class="btn btn-outline-primary">
                        <i class="bi bi-journal-text me-2"></i>View Logs
                    </a>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">System Status</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Active Companies</span>
                    <span class="fw-bold text-success"><?php echo $activeCompanies; ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Active Devices</span>
                    <span class="fw-bold text-success"><?php echo $activeDevices; ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Inactive Devices</span>
                    <span class="fw-bold text-danger"><?php echo $totalDevices - $activeDevices; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
