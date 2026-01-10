<?php
$pageTitle = 'My Devices';
$currentPage = 'devices';

require_once __DIR__ . '/../models/User.php';

$userModel = new User();

// Get current user from session
require_once __DIR__ . '/includes/auth.php';
requireUserLogin();
$portalUser = getCurrentPortalUser();

// Get user's assigned devices
$devices = $userModel->getAssignedDevices($portalUser['id']);

include 'includes/header.php';
?>

<h4 class="mb-4"><i class="bi bi-hdd-stack me-2"></i>My Devices</h4>

<?php if (empty($devices)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-hdd-stack display-1 text-muted"></i>
        <h5 class="mt-3">No Devices Assigned</h5>
        <p class="text-muted">You don't have any devices assigned to your account yet.<br>Please contact your administrator.</p>
    </div>
</div>
<?php else: ?>
<div class="row g-4">
    <?php foreach ($devices as $device): ?>
    <?php
    $isOnline = false;
    $lastSeenText = 'Never';
    if ($device['last_seen_at']) {
        $lastSeen = strtotime($device['last_seen_at']);
        $isOnline = (time() - $lastSeen < 300);
        $lastSeenText = date('Y-m-d H:i:s', $lastSeen);
    }
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card device-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="device-icon">
                        <i class="bi bi-hdd"></i>
                    </div>
                    <span class="badge <?php echo $isOnline ? 'badge-online' : 'badge-offline'; ?>">
                        <?php echo $isOnline ? 'Online' : 'Offline'; ?>
                    </span>
                </div>
                <h5 class="card-title"><?php echo htmlspecialchars($device['name']); ?></h5>
                <p class="card-text">
                    <code><?php echo htmlspecialchars($device['serial_number']); ?></code>
                </p>
                <?php if ($device['description']): ?>
                <p class="text-muted small"><?php echo htmlspecialchars($device['description']); ?></p>
                <?php endif; ?>
                <div class="mb-3">
                    <?php if ($device['device_type']): ?>
                    <span class="badge bg-light text-dark"><?php echo htmlspecialchars($device['device_type']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mb-3">
                    <i class="bi bi-clock me-1"></i>Last seen: <?php echo $lastSeenText; ?>
                </div>
                <a href="device_logs.php?id=<?php echo $device['id']; ?>" class="btn btn-primary w-100">
                    <i class="bi bi-journal-text me-2"></i>View Logs
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
