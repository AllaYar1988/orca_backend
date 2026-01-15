<?php
$pageTitle = 'Manage User Access';
$currentPage = 'users';

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/Company.php';

$userModel = new User();
$deviceModel = new Device();
$companyModel = new Company();

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
if (!$id) {
    header('Location: users.php');
    exit;
}

$user = $userModel->getById($id);
if (!$user) {
    header('Location: users.php');
    exit;
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'update_companies') {
        // Update company assignments
        $selectedCompanies = isset($_POST['companies']) ? $_POST['companies'] : [];

        // Get current company assignments to know which to remove
        $currentCompanyIds = $userModel->getAssignedCompanyIds($id);

        // Remove all company assignments
        $userModel->removeAllCompanies($id);

        // Remove device assignments for companies that are being removed
        $removedCompanies = array_diff($currentCompanyIds, $selectedCompanies);
        foreach ($removedCompanies as $companyId) {
            // Get devices for this company and remove them
            $companyDevices = $deviceModel->getAll(['company_id' => $companyId]);
            foreach ($companyDevices as $device) {
                $userModel->removeDevice($id, $device['id']);
            }
        }

        // Add new company assignments
        foreach ($selectedCompanies as $companyId) {
            $userModel->assignCompany($id, (int)$companyId);
        }

        $message = 'Company access updated successfully!';
        $messageType = 'success';
    }
    elseif ($action === 'update_devices') {
        // Update device assignments for a specific company
        $companyId = (int)(isset($_POST['company_id']) ? $_POST['company_id'] : 0);
        $selectedDevices = isset($_POST['devices']) ? $_POST['devices'] : [];

        if ($companyId) {
            // Remove all devices for this company
            $companyDevices = $deviceModel->getAll(['company_id' => $companyId]);
            foreach ($companyDevices as $device) {
                $userModel->removeDevice($id, $device['id']);
            }

            // Add selected devices
            foreach ($selectedDevices as $deviceId) {
                $userModel->assignDevice($id, (int)$deviceId);
            }

            $message = 'Device access updated successfully!';
            $messageType = 'success';
        }
    }
}

// Get all companies and assigned company IDs
$allCompanies = $companyModel->getAll(['is_active' => 1]);
$assignedCompanyIds = $userModel->getAssignedCompanyIds($id);
$assignedDeviceIds = $userModel->getAssignedDeviceIds($id);

// Get assigned companies with their devices
$assignedCompanies = [];
foreach ($assignedCompanyIds as $companyId) {
    $company = $companyModel->getById($companyId);
    if ($company) {
        $company['devices'] = $deviceModel->getAll(['company_id' => $companyId, 'is_active' => 1]);
        $assignedCompanies[] = $company;
    }
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <!-- User Info Card -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-person me-2"></i>User: <?php echo htmlspecialchars($user['username']); ?>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3">
                        <strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Name:</strong> <?php echo htmlspecialchars($user['name'] ?: '-'); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?: '-'); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Status:</strong>
                        <?php if ($user['is_active']): ?>
                        <span class="badge badge-active">Active</span>
                        <?php else: ?>
                        <span class="badge badge-inactive">Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Company Assignment Card -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-building me-2"></i>Company Access
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_companies">

                    <p class="text-muted mb-3">Select which companies this user can access:</p>

                    <?php if (empty($allCompanies)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        No active companies found. <a href="company_create.php">Create a company</a> first.
                    </div>
                    <?php else: ?>

                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="selectAllCompanies" onchange="toggleAllCompanies(this)">
                            <label class="form-check-label fw-bold" for="selectAllCompanies">
                                Select All / Deselect All
                            </label>
                        </div>
                        <hr>
                    </div>

                    <div class="row">
                        <?php foreach ($allCompanies as $company): ?>
                        <div class="col-md-4 mb-2">
                            <div class="form-check">
                                <input class="form-check-input company-checkbox" type="checkbox"
                                       name="companies[]" value="<?php echo $company['id']; ?>"
                                       id="company_<?php echo $company['id']; ?>"
                                       <?php echo in_array($company['id'], $assignedCompanyIds) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="company_<?php echo $company['id']; ?>">
                                    <strong><?php echo htmlspecialchars($company['name']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars($company['code']); ?></small>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="my-3">

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Save Company Access
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Device Assignment Cards (one per assigned company) -->
        <?php if (!empty($assignedCompanies)): ?>
        <?php foreach ($assignedCompanies as $company): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-hdd-stack me-2"></i>Devices for: <?php echo htmlspecialchars($company['name']); ?>
                </span>
                <span class="badge bg-secondary"><?php echo count($company['devices']); ?> devices available</span>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_devices">
                    <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">

                    <?php if (empty($company['devices'])): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        No active devices in this company. <a href="device_create.php?company_id=<?php echo $company['id']; ?>">Create a device</a>.
                    </div>
                    <?php else: ?>

                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox"
                                   id="selectAllDevices_<?php echo $company['id']; ?>"
                                   onchange="toggleAllDevices(this, <?php echo $company['id']; ?>)">
                            <label class="form-check-label fw-bold" for="selectAllDevices_<?php echo $company['id']; ?>">
                                Select All / Deselect All
                            </label>
                        </div>
                        <hr>
                    </div>

                    <div class="row">
                        <?php foreach ($company['devices'] as $device): ?>
                        <?php $isAssigned = in_array($device['id'], $assignedDeviceIds); ?>
                        <?php $hasSensorRestrictions = $isAssigned && $userModel->hasSensorRestrictions($id, $device['id']); ?>
                        <div class="col-md-6 mb-2">
                            <div class="form-check">
                                <input class="form-check-input device-checkbox-<?php echo $company['id']; ?>" type="checkbox"
                                       name="devices[]" value="<?php echo $device['id']; ?>"
                                       id="device_<?php echo $device['id']; ?>"
                                       <?php echo $isAssigned ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="device_<?php echo $device['id']; ?>">
                                    <strong><?php echo htmlspecialchars($device['name']); ?></strong>
                                    <?php if ($hasSensorRestrictions): ?>
                                    <span class="badge bg-warning text-dark badge-sm">Sensor restricted</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted">
                                        <code><?php echo htmlspecialchars($device['serial_number']); ?></code>
                                        <?php if ($device['device_type']): ?>
                                        - <?php echo htmlspecialchars($device['device_type']); ?>
                                        <?php endif; ?>
                                    </small>
                                    <?php if ($isAssigned): ?>
                                    <br>
                                    <a href="user_device_sensors.php?user_id=<?php echo $id; ?>&device_id=<?php echo $device['id']; ?>" class="btn btn-outline-secondary btn-sm mt-1">
                                        <i class="bi bi-sliders me-1"></i>Manage Sensors
                                    </a>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="my-3">

                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-check-lg me-2"></i>Save Device Access for <?php echo htmlspecialchars($company['name']); ?>
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Select at least one company above to assign device access.
        </div>
        <?php endif; ?>

        <!-- Navigation -->
        <div class="d-flex gap-2">
            <a href="user_virtual_devices.php?id=<?php echo $id; ?>" class="btn btn-outline-primary">
                <i class="bi bi-diagram-3 me-2"></i>Manage Virtual Devices
            </a>
            <a href="user_edit.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil me-2"></i>Edit User
            </a>
            <a href="users.php" class="btn btn-outline-secondary">
                <i class="bi bi-people me-2"></i>All Users
            </a>
        </div>
    </div>
</div>

<script>
function toggleAllCompanies(checkbox) {
    var checkboxes = document.querySelectorAll('.company-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}

function toggleAllDevices(checkbox, companyId) {
    var checkboxes = document.querySelectorAll('.device-checkbox-' + companyId);
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}

// Update "Select All" state for companies
document.querySelectorAll('.company-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var all = document.querySelectorAll('.company-checkbox');
        var checked = document.querySelectorAll('.company-checkbox:checked');
        document.getElementById('selectAllCompanies').checked = (all.length === checked.length);
    });
});

// Initial state for company select all
(function() {
    var all = document.querySelectorAll('.company-checkbox');
    var checked = document.querySelectorAll('.company-checkbox:checked');
    var selectAll = document.getElementById('selectAllCompanies');
    if (selectAll && all.length > 0) {
        selectAll.checked = (all.length === checked.length);
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
