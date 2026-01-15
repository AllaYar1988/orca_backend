<?php
$pageTitle = 'Create Virtual Device';
$currentPage = 'virtual_device_create';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/VirtualDevice.php';

$companyModel = new Company();
$virtualDeviceModel = new VirtualDevice();

$companies = $companyModel->getAll(['is_active' => 1]);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'company_id' => (int)($_POST['company_id'] ?? 0),
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    if (empty($data['name']) || !$data['company_id']) {
        $message = 'Company and Name are required.';
        $messageType = 'danger';
    } else {
        try {
            $id = $virtualDeviceModel->create($data);
            if ($id) {
                // Redirect to edit page to add sensors
                header('Location: virtual_device_edit.php?id=' . $id . '&created=1');
                exit;
            } else {
                $message = 'Failed to create virtual device.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
} else {
    $data = ['company_id' => $_GET['company_id'] ?? '', 'name' => '', 'description' => '', 'is_active' => 1];
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-diagram-3 me-2"></i>Create New Virtual Device
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (empty($companies)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    No active companies found. <a href="company_create.php">Create a company</a> first.
                </div>
                <?php else: ?>

                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Step 1:</strong> Create the virtual device. After creation, you'll be redirected to add sensors from physical devices.
                </div>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="company_id" class="form-label">Company <span class="text-danger">*</span></label>
                        <select class="form-select" id="company_id" name="company_id" required>
                            <option value="">Select a company...</option>
                            <?php foreach ($companies as $company): ?>
                            <option value="<?php echo $company['id']; ?>"
                                <?php echo ($data['company_id'] == $company['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($company['name']); ?> (<?php echo htmlspecialchars($company['code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Virtual device can only include sensors from devices in this company</div>
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label">Virtual Device Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?php echo htmlspecialchars($data['name']); ?>" required
                               placeholder="e.g., Building A Climate Sensors">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"
                                  placeholder="Optional description..."><?php echo htmlspecialchars($data['description']); ?></textarea>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $data['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-text">Inactive virtual devices won't be visible to users</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Create & Add Sensors
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
