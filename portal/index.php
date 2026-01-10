<?php
$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

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

// Count stats
$totalDevices = count($devices);
$onlineDevices = 0;
$recentLogs = [];

// Check which devices are "online" (seen in last 5 minutes) and get recent logs
foreach ($devices as $device) {
    if ($device['last_seen_at']) {
        $lastSeen = strtotime($device['last_seen_at']);
        if (time() - $lastSeen < 300) { // 5 minutes
            $onlineDevices++;
        }
    }
}

// Get recent logs for user's devices
if (!empty($deviceIds)) {
    $recentLogs = $logModel->getAll([
        'device_ids' => $deviceIds,
        'limit' => 10
    ]);
}

include 'includes/header.php';
?>

<h4 class="mb-4">Welcome, <?php echo htmlspecialchars($portalUser['name'] ? $portalUser['name'] : $portalUser['username']); ?>!</h4>

<!-- Stats Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="device-icon me-3">
                    <i class="bi bi-hdd-stack"></i>
                </div>
                <div>
                    <div class="h3 mb-0"><?php echo $totalDevices; ?></div>
                    <div class="text-muted">My Devices</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="device-icon me-3" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <i class="bi bi-wifi"></i>
                </div>
                <div>
                    <div class="h3 mb-0"><?php echo $onlineDevices; ?></div>
                    <div class="text-muted">Online Now</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center">
                <div class="device-icon me-3" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                    <i class="bi bi-journal-text"></i>
                </div>
                <div>
                    <div class="h3 mb-0"><?php echo count($recentLogs); ?></div>
                    <div class="text-muted">Recent Logs</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- My Devices -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd-stack me-2"></i>My Devices</span>
        <a href="devices.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($devices)): ?>
        <p class="text-muted text-center py-4 mb-0">No devices assigned to your account.</p>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach (array_slice($devices, 0, 6) as $device): ?>
            <?php
            $isOnline = false;
            if ($device['last_seen_at']) {
                $lastSeen = strtotime($device['last_seen_at']);
                $isOnline = (time() - $lastSeen < 300);
            }
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card device-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="card-title mb-0"><?php echo htmlspecialchars($device['name']); ?></h6>
                            <span class="badge <?php echo $isOnline ? 'badge-online' : 'badge-offline'; ?>">
                                <?php echo $isOnline ? 'Online' : 'Offline'; ?>
                            </span>
                        </div>
                        <p class="card-text small text-muted mb-2">
                            <code><?php echo htmlspecialchars($device['serial_number']); ?></code>
                        </p>
                        <?php if ($device['device_type']): ?>
                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($device['device_type']); ?></span>
                        <?php endif; ?>
                        <div class="mt-2">
                            <a href="device_logs.php?id=<?php echo $device['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-journal-text me-1"></i>View Logs
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Logs -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text me-2"></i>Recent Logs</span>
        <a href="logs.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentLogs)): ?>
        <p class="text-muted text-center py-4 mb-0">No logs available yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Device</th>
                        <th>Key</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?php echo date('Y-m-d H:i:s', strtotime($log['logged_at'])); ?></td>
                        <td>
                            <a href="device_logs.php?id=<?php echo $log['device_id']; ?>">
                                <?php echo htmlspecialchars($log['device_name'] ? $log['device_name'] : $log['serial_number']); ?>
                            </a>
                        </td>
                        <td><code><?php echo htmlspecialchars($log['log_key'] ? $log['log_key'] : '-'); ?></code></td>
                        <td class="log-value" title="<?php echo htmlspecialchars($log['log_value']); ?>">
                            <?php echo htmlspecialchars($log['log_value'] ? $log['log_value'] : '-'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
