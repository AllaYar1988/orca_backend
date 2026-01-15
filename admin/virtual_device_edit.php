<?php
$pageTitle = 'Edit Virtual Device';
$currentPage = 'virtual_devices';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/VirtualDevice.php';
require_once __DIR__ . '/../models/SensorConfig.php';

$companyModel = new Company();
$deviceModel = new Device();
$virtualDeviceModel = new VirtualDevice();
$sensorConfigModel = new SensorConfig();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    header('Location: virtual_devices.php');
    exit;
}

$virtualDevice = $virtualDeviceModel->getById($id);

if (!$virtualDevice) {
    header('Location: virtual_devices.php');
    exit;
}

$companies = $companyModel->getAll(['is_active' => 1]);

// Get all devices for the virtual device's company
$companyDevices = $deviceModel->getAll(['company_id' => $virtualDevice['company_id'], 'is_active' => 1]);

// Get current sensor mappings
$currentMappings = $virtualDeviceModel->getSensorMappings($id);
$mappedSensors = [];
foreach ($currentMappings as $mapping) {
    $key = $mapping['source_device_id'] . '_' . $mapping['source_log_key'];
    $mappedSensors[$key] = [
        'display_label' => $mapping['display_label'],
        'display_order' => $mapping['display_order']
    ];
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_info';

    if ($action === 'update_info') {
        // Update basic info
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        if (empty($data['name'])) {
            $message = 'Name is required.';
            $messageType = 'danger';
        } else {
            try {
                $virtualDeviceModel->update($id, $data);
                $message = 'Virtual device updated successfully.';
                $messageType = 'success';
                // Refresh data
                $virtualDevice = $virtualDeviceModel->getById($id);
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'update_sensors') {
        // Update sensor mappings
        $selectedSensors = $_POST['sensors'] ?? [];
        $labels = $_POST['labels'] ?? [];
        $orders = $_POST['orders'] ?? [];

        $sensors = [];
        foreach ($selectedSensors as $sensorKey) {
            list($deviceId, $logKey) = explode('_', $sensorKey, 2);
            $sensors[] = [
                'source_device_id' => (int)$deviceId,
                'source_log_key' => $logKey,
                'display_label' => isset($labels[$sensorKey]) ? trim($labels[$sensorKey]) : null,
                'display_order' => isset($orders[$sensorKey]) ? (int)$orders[$sensorKey] : 0
            ];
        }

        try {
            $virtualDeviceModel->setSensors($id, $sensors);
            $message = 'Sensor mappings updated successfully. ' . count($sensors) . ' sensors mapped.';
            $messageType = 'success';
            // Refresh mappings
            $currentMappings = $virtualDeviceModel->getSensorMappings($id);
            $mappedSensors = [];
            foreach ($currentMappings as $mapping) {
                $key = $mapping['source_device_id'] . '_' . $mapping['source_log_key'];
                $mappedSensors[$key] = [
                    'display_label' => $mapping['display_label'],
                    'display_order' => $mapping['display_order']
                ];
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Build available sensors list for each device
$deviceSensors = [];
foreach ($companyDevices as $device) {
    $sensors = $virtualDeviceModel->getAvailableSensors($device['id']);
    if (!empty($sensors)) {
        $deviceSensors[$device['id']] = [
            'device' => $device,
            'sensors' => $sensors
        ];
    }
}

include 'includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Virtual device created successfully. Now add sensors from physical devices below.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <!-- Left Column: Basic Info -->
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Virtual Device Info
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_info">

                    <div class="mb-3">
                        <label class="form-label">Company</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($virtualDevice['company_name']); ?>" readonly>
                        <div class="form-text">Company cannot be changed after creation</div>
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?php echo htmlspecialchars($virtualDevice['name']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($virtualDevice['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $virtualDevice['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Save Info
                    </button>
                </form>
            </div>
        </div>

        <!-- Current Status -->
        <?php
        $status = $virtualDeviceModel->getStatusSummary($id);
        ?>
        <div class="card mt-4">
            <div class="card-header">
                <i class="bi bi-activity me-2"></i>Current Status
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6 mb-3">
                        <div class="h4 mb-0">
                            <?php if ($status['is_online']): ?>
                            <span class="text-success"><i class="bi bi-circle-fill me-1"></i>Online</span>
                            <?php else: ?>
                            <span class="text-danger"><i class="bi bi-circle-fill me-1"></i>Offline</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">Status</small>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="h4 mb-0"><?php echo $status['live_count']; ?> / <?php echo $status['total_count']; ?></div>
                        <small class="text-muted">Sensors Online</small>
                    </div>
                </div>
                <?php if ($status['seconds_ago'] !== null): ?>
                <div class="text-center text-muted">
                    <small>Last update: <?php echo floor($status['seconds_ago'] / 60); ?> minutes ago</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Sensor Mapping -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-2 me-2"></i>Sensor Mapping</span>
                <span class="badge bg-primary"><?php echo count($mappedSensors); ?> sensors mapped</span>
            </div>
            <div class="card-body">
                <?php if (empty($deviceSensors)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    No devices with sensors found in this company. Add devices and wait for them to send data.
                </div>
                <?php else: ?>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_sensors">

                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Select sensors from physical devices to include in this virtual device.
                        Optionally set a custom label and display order for each sensor.
                    </div>

                    <div class="accordion" id="deviceAccordion">
                        <?php foreach ($deviceSensors as $deviceId => $data): ?>
                        <?php
                        $device = $data['device'];
                        $sensors = $data['sensors'];
                        $hasSelectedSensors = false;
                        foreach ($sensors as $sensor) {
                            $key = $deviceId . '_' . $sensor['log_key'];
                            if (isset($mappedSensors[$key])) {
                                $hasSelectedSensors = true;
                                break;
                            }
                        }
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button <?php echo $hasSelectedSensors ? '' : 'collapsed'; ?>" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#device_<?php echo $deviceId; ?>">
                                    <strong><?php echo htmlspecialchars($device['name']); ?></strong>
                                    <code class="ms-2"><?php echo htmlspecialchars($device['serial_number']); ?></code>
                                    <?php if ($hasSelectedSensors): ?>
                                    <span class="badge bg-success ms-2">Has mapped sensors</span>
                                    <?php endif; ?>
                                </button>
                            </h2>
                            <div id="device_<?php echo $deviceId; ?>" class="accordion-collapse collapse <?php echo $hasSelectedSensors ? 'show' : ''; ?>"
                                 data-bs-parent="#deviceAccordion">
                                <div class="accordion-body">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th style="width:40px;"></th>
                                                <th>Sensor</th>
                                                <th>Custom Label</th>
                                                <th style="width:80px;">Order</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sensors as $sensor): ?>
                                            <?php
                                            $key = $deviceId . '_' . $sensor['log_key'];
                                            $isSelected = isset($mappedSensors[$key]);
                                            $currentLabel = $isSelected ? ($mappedSensors[$key]['display_label'] ?? '') : '';
                                            $currentOrder = $isSelected ? ($mappedSensors[$key]['display_order'] ?? 0) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="sensors[]" value="<?php echo htmlspecialchars($key); ?>"
                                                               id="sensor_<?php echo htmlspecialchars($key); ?>"
                                                               <?php echo $isSelected ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td>
                                                    <label for="sensor_<?php echo htmlspecialchars($key); ?>">
                                                        <strong><?php echo htmlspecialchars($sensor['label'] ?: $sensor['log_key']); ?></strong>
                                                        <?php if ($sensor['unit']): ?>
                                                        <span class="text-muted">(<?php echo htmlspecialchars($sensor['unit']); ?>)</span>
                                                        <?php endif; ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <code><?php echo htmlspecialchars($sensor['log_key']); ?></code>
                                                            <?php if ($sensor['is_configured']): ?>
                                                            <span class="badge bg-success badge-sm">Configured</span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </label>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm"
                                                           name="labels[<?php echo htmlspecialchars($key); ?>]"
                                                           value="<?php echo htmlspecialchars($currentLabel); ?>"
                                                           placeholder="Optional">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm"
                                                           name="orders[<?php echo htmlspecialchars($key); ?>]"
                                                           value="<?php echo $currentOrder; ?>" min="0" max="999">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-diagram-2 me-2"></i>Save Sensor Mapping
                        </button>
                        <a href="virtual_devices.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
