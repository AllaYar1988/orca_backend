<?php
$pageTitle = 'Manage Sensor Access';
$currentPage = 'users';

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/SensorConfig.php';

$userModel = new User();
$deviceModel = new Device();
$sensorConfigModel = new SensorConfig();

$userId = (int)($_GET['user_id'] ?? 0);
$deviceId = (int)($_GET['device_id'] ?? 0);

if (!$userId || !$deviceId) {
    header('Location: users.php');
    exit;
}

$user = $userModel->getById($userId);
$device = $deviceModel->getById($deviceId);

if (!$user || !$device) {
    header('Location: users.php');
    exit;
}

// Verify user has access to this device
if (!$userModel->hasAccessToDevice($userId, $deviceId)) {
    header('Location: user_devices.php?id=' . $userId);
    exit;
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restrictSensors = isset($_POST['restrict_sensors']) && $_POST['restrict_sensors'] === '1';
    $selectedSensors = isset($_POST['sensors']) ? $_POST['sensors'] : [];

    if ($restrictSensors && !empty($selectedSensors)) {
        // Set specific sensor restrictions
        $userModel->setSensorAccess($userId, $deviceId, $selectedSensors);
        $message = 'Sensor access updated. User can only see selected sensors.';
    } else {
        // Remove all restrictions (grant access to all sensors)
        $userModel->removeAllSensorRestrictions($userId, $deviceId);
        $message = 'Sensor restrictions removed. User can see all sensors.';
    }
    $messageType = 'success';
}

// Get available sensors for this device
// First from sensor_configs, then from actual log data
$configuredSensors = $sensorConfigModel->getByDevice($deviceId);
$logKeysFromData = $sensorConfigModel->getDeviceLogKeys($deviceId);

// Merge to get all available sensors
$allSensors = [];
foreach ($configuredSensors as $config) {
    $allSensors[$config['log_key']] = [
        'log_key' => $config['log_key'],
        'label' => $config['label'] ?: $config['log_key'],
        'unit' => $config['unit'],
        'sensor_type' => $config['sensor_type'],
        'configured' => true
    ];
}
foreach ($logKeysFromData as $logKey) {
    if (!isset($allSensors[$logKey])) {
        $allSensors[$logKey] = [
            'log_key' => $logKey,
            'label' => $logKey,
            'unit' => '',
            'sensor_type' => 'GEN',
            'configured' => false
        ];
    }
}
ksort($allSensors);

// Get current sensor restrictions
$allowedSensors = $userModel->getAllowedSensors($userId, $deviceId);
$hasRestrictions = !empty($allowedSensors);

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-sliders me-2"></i>Sensor Access for: <?php echo htmlspecialchars($user['username']); ?>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>User:</strong> <?php echo htmlspecialchars($user['username']); ?>
                        <?php if ($user['name']): ?>
                        (<?php echo htmlspecialchars($user['name']); ?>)
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Device:</strong> <?php echo htmlspecialchars($device['name']); ?>
                        (<code><?php echo htmlspecialchars($device['serial_number']); ?></code>)
                    </div>
                </div>

                <hr>

                <?php if (empty($allSensors)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    No sensors found for this device. Sensors will appear after the device sends data.
                </div>
                <?php else: ?>

                <form method="POST" action="">
                    <div class="mb-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="restrict_sensors" name="restrict_sensors" value="1"
                                   <?php echo $hasRestrictions ? 'checked' : ''; ?>
                                   onchange="toggleSensorSelection(this)">
                            <label class="form-check-label fw-bold" for="restrict_sensors">
                                Restrict to specific sensors
                            </label>
                        </div>
                        <div class="form-text">
                            When disabled, user can see ALL sensors. When enabled, user can only see selected sensors.
                        </div>
                    </div>

                    <div id="sensorSelection" style="<?php echo $hasRestrictions ? '' : 'display:none;'; ?>">
                        <div class="mb-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="selectAllSensors" onchange="toggleAllSensors(this)">
                                <label class="form-check-label fw-bold" for="selectAllSensors">
                                    Select All / Deselect All
                                </label>
                            </div>
                            <hr>
                        </div>

                        <div class="row">
                            <?php foreach ($allSensors as $sensor): ?>
                            <div class="col-md-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input sensor-checkbox" type="checkbox"
                                           name="sensors[]" value="<?php echo htmlspecialchars($sensor['log_key']); ?>"
                                           id="sensor_<?php echo htmlspecialchars($sensor['log_key']); ?>"
                                           <?php echo in_array($sensor['log_key'], $allowedSensors) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sensor_<?php echo htmlspecialchars($sensor['log_key']); ?>">
                                        <strong><?php echo htmlspecialchars($sensor['label']); ?></strong>
                                        <?php if ($sensor['unit']): ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($sensor['unit']); ?>)</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            <code><?php echo htmlspecialchars($sensor['log_key']); ?></code>
                                            <?php if ($sensor['configured']): ?>
                                            <span class="badge bg-success badge-sm">Configured</span>
                                            <?php endif; ?>
                                        </small>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Save Sensor Access
                        </button>
                        <a href="user_devices.php?id=<?php echo $userId; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to User Access
                        </a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Note:</strong> By default, users can see all sensors for devices they have access to.
            Use sensor restrictions to limit visibility to specific sensors only.
        </div>
    </div>
</div>

<script>
function toggleSensorSelection(checkbox) {
    var selection = document.getElementById('sensorSelection');
    selection.style.display = checkbox.checked ? 'block' : 'none';
}

function toggleAllSensors(checkbox) {
    var checkboxes = document.querySelectorAll('.sensor-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}

// Update "Select All" state
document.querySelectorAll('.sensor-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var all = document.querySelectorAll('.sensor-checkbox');
        var checked = document.querySelectorAll('.sensor-checkbox:checked');
        document.getElementById('selectAllSensors').checked = (all.length === checked.length);
    });
});

// Initial state for select all
(function() {
    var all = document.querySelectorAll('.sensor-checkbox');
    var checked = document.querySelectorAll('.sensor-checkbox:checked');
    var selectAll = document.getElementById('selectAllSensors');
    if (selectAll && all.length > 0) {
        selectAll.checked = (all.length === checked.length);
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
