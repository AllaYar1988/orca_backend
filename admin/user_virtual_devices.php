<?php
$pageTitle = 'Manage Virtual Device Access';
$currentPage = 'users';

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/VirtualDevice.php';
require_once __DIR__ . '/../models/Company.php';

$userModel = new User();
$virtualDeviceModel = new VirtualDevice();
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

    if ($action === 'update_virtual_devices') {
        // Update virtual device assignments for a specific company
        $companyId = (int)(isset($_POST['company_id']) ? $_POST['company_id'] : 0);
        $selectedVirtualDevices = isset($_POST['virtual_devices']) ? $_POST['virtual_devices'] : [];

        if ($companyId) {
            // Remove all virtual devices for this company
            $companyVirtualDevices = $virtualDeviceModel->getAll(['company_id' => $companyId]);
            foreach ($companyVirtualDevices as $vd) {
                $userModel->removeVirtualDevice($id, $vd['id']);
            }

            // Add selected virtual devices
            foreach ($selectedVirtualDevices as $vdId) {
                $userModel->assignVirtualDevice($id, (int)$vdId);
            }

            $message = 'Virtual device access updated successfully!';
            $messageType = 'success';
        }
    }
}

// Get assigned company IDs (user must have company access to see virtual devices)
$assignedCompanyIds = $userModel->getAssignedCompanyIds($id);
$assignedVirtualDeviceIds = $userModel->getAssignedVirtualDeviceIds($id);

// Get assigned companies with their virtual devices
$companiesWithVirtualDevices = [];
foreach ($assignedCompanyIds as $companyId) {
    $company = $companyModel->getById($companyId);
    if ($company) {
        $virtualDevices = $virtualDeviceModel->getAll(['company_id' => $companyId, 'is_active' => 1]);
        if (!empty($virtualDevices)) {
            $company['virtual_devices'] = $virtualDevices;
            $companiesWithVirtualDevices[] = $company;
        }
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

        <!-- Info Alert -->
        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Virtual Devices</strong> group sensors from multiple physical devices.
            Users assigned to a virtual device will see aggregated sensor data as if it were a single device.
            <br><small>Note: User must have company access to be assigned virtual devices from that company.</small>
        </div>

        <!-- Virtual Device Assignment Cards (one per company with virtual devices) -->
        <?php if (!empty($companiesWithVirtualDevices)): ?>
        <?php foreach ($companiesWithVirtualDevices as $company): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-diagram-3 me-2"></i>Virtual Devices: <?php echo htmlspecialchars($company['name']); ?>
                </span>
                <span class="badge bg-secondary"><?php echo count($company['virtual_devices']); ?> virtual devices available</span>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_virtual_devices">
                    <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">

                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox"
                                   id="selectAllVD_<?php echo $company['id']; ?>"
                                   onchange="toggleAllVirtualDevices(this, <?php echo $company['id']; ?>)">
                            <label class="form-check-label fw-bold" for="selectAllVD_<?php echo $company['id']; ?>">
                                Select All / Deselect All
                            </label>
                        </div>
                        <hr>
                    </div>

                    <div class="row">
                        <?php foreach ($company['virtual_devices'] as $vd): ?>
                        <?php $isAssigned = in_array($vd['id'], $assignedVirtualDeviceIds); ?>
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input vd-checkbox-<?php echo $company['id']; ?>" type="checkbox"
                                       name="virtual_devices[]" value="<?php echo $vd['id']; ?>"
                                       id="vd_<?php echo $vd['id']; ?>"
                                       <?php echo $isAssigned ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="vd_<?php echo $vd['id']; ?>">
                                    <strong><?php echo htmlspecialchars($vd['name']); ?></strong>
                                    <span class="badge bg-secondary ms-1"><?php echo (int)$vd['sensor_count']; ?> sensors</span>
                                    <?php if ($vd['description']): ?>
                                    <br>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($vd['description'], 0, 60)); ?><?php echo strlen($vd['description']) > 60 ? '...' : ''; ?></small>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="my-3">

                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-check-lg me-2"></i>Save Virtual Device Access for <?php echo htmlspecialchars($company['name']); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php if (empty($assignedCompanyIds)): ?>
            User has no company access. <a href="user_devices.php?id=<?php echo $id; ?>">Assign companies first</a>.
            <?php else: ?>
            No virtual devices found in user's assigned companies.
            <a href="virtual_device_create.php">Create a virtual device</a>.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Navigation -->
        <div class="d-flex gap-2">
            <a href="user_devices.php?id=<?php echo $id; ?>" class="btn btn-outline-primary">
                <i class="bi bi-hdd-stack me-2"></i>Manage Device Access
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
function toggleAllVirtualDevices(checkbox, companyId) {
    var checkboxes = document.querySelectorAll('.vd-checkbox-' + companyId);
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}

// Update "Select All" state for each company
<?php foreach ($companiesWithVirtualDevices as $company): ?>
(function() {
    var companyId = <?php echo $company['id']; ?>;
    var checkboxes = document.querySelectorAll('.vd-checkbox-' + companyId);
    var selectAll = document.getElementById('selectAllVD_' + companyId);

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            var all = document.querySelectorAll('.vd-checkbox-' + companyId);
            var checked = document.querySelectorAll('.vd-checkbox-' + companyId + ':checked');
            selectAll.checked = (all.length === checked.length);
        });
    });

    // Initial state
    if (selectAll && checkboxes.length > 0) {
        var checked = document.querySelectorAll('.vd-checkbox-' + companyId + ':checked');
        selectAll.checked = (checkboxes.length === checked.length);
    }
})();
<?php endforeach; ?>
</script>

<?php include 'includes/footer.php'; ?>
