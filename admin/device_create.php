<?php
$pageTitle = 'Create Device';
$currentPage = 'device_create';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Device.php';

$companyModel = new Company();
$deviceModel = new Device();

$companies = $companyModel->getAll(['is_active' => 1]);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'company_id' => (int)($_POST['company_id'] ?? 0),
        'name' => trim($_POST['name'] ?? ''),
        'serial_number' => trim($_POST['serial_number'] ?? ''),
        'device_secret' => $_POST['device_secret'] ?? '',
        'description' => trim($_POST['description'] ?? ''),
        'device_type' => trim($_POST['device_type'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    if (empty($data['name']) || empty($data['serial_number']) || !$data['company_id']) {
        $message = 'Company, Name, and Serial Number are required.';
        $messageType = 'danger';
    } elseif (empty($data['device_secret']) || strlen($data['device_secret']) < 6) {
        $message = 'Device Password is required and must be at least 6 characters.';
        $messageType = 'danger';
    } else {
        try {
            $id = $deviceModel->create($data);
            if ($id) {
                $message = 'Device created successfully!';
                $messageType = 'success';
                $data = ['company_id' => '', 'name' => '', 'serial_number' => '', 'device_secret' => '', 'description' => '', 'device_type' => '', 'is_active' => 1];
            } else {
                $message = 'Failed to create device.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $message = 'A device with this serial number already exists.';
            } else {
                $message = 'Database error: ' . $e->getMessage();
            }
            $messageType = 'danger';
        }
    }
} else {
    $data = ['company_id' => $_GET['company_id'] ?? '', 'name' => '', 'serial_number' => '', 'device_secret' => '', 'description' => '', 'device_type' => '', 'is_active' => 1];
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-hdd-stack me-2"></i>Create New Device
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
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="name" class="form-label">Device Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?php echo htmlspecialchars($data['name']); ?>" required
                                   placeholder="e.g., Temperature Sensor 1">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="serial_number" class="form-label">Serial Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="serial_number" name="serial_number"
                                   value="<?php echo htmlspecialchars($data['serial_number']); ?>" required
                                   placeholder="e.g., DEV-2024-001">
                            <div class="form-text">Unique identifier for the device</div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="device_secret" class="form-label">Device Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="device_secret" name="device_secret" required
                                   placeholder="Min 6 characters">
                            <div class="form-text">Used by device to request API token</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="device_type" class="form-label">Device Type</label>
                        <input type="text" class="form-control" id="device_type" name="device_type"
                               value="<?php echo htmlspecialchars($data['device_type']); ?>"
                               placeholder="e.g., Sensor, Gateway, Controller">
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
                        <div class="form-text">Inactive devices cannot send logs</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Create Device
                        </button>
                        <a href="devices.php" class="btn btn-outline-secondary">
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
