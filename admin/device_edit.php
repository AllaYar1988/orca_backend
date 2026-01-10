<?php
$pageTitle = 'Edit Device';
$currentPage = 'devices';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Device.php';

$companyModel = new Company();
$deviceModel = new Device();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: devices.php');
    exit;
}

$device = $deviceModel->getById($id);
if (!$device) {
    header('Location: devices.php');
    exit;
}

$companies = $companyModel->getAll();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'company_id' => (int)($_POST['company_id'] ?? 0),
        'name' => trim($_POST['name'] ?? ''),
        'serial_number' => trim($_POST['serial_number'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'device_type' => trim($_POST['device_type'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    if (empty($data['name']) || empty($data['serial_number']) || !$data['company_id']) {
        $message = 'Company, Name, and Serial Number are required.';
        $messageType = 'danger';
    } else {
        try {
            if ($deviceModel->update($id, $data)) {
                $message = 'Device updated successfully!';
                $messageType = 'success';
                $device = $deviceModel->getById($id);
            } else {
                $message = 'No changes made.';
                $messageType = 'info';
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
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pencil me-2"></i>Edit Device
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="company_id" class="form-label">Company <span class="text-danger">*</span></label>
                        <select class="form-select" id="company_id" name="company_id" required>
                            <option value="">Select a company...</option>
                            <?php foreach ($companies as $company): ?>
                            <option value="<?php echo $company['id']; ?>"
                                <?php echo ($device['company_id'] == $company['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($company['name']); ?> (<?php echo htmlspecialchars($company['code']); ?>)
                                <?php if (!$company['is_active']): ?> - Inactive<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Device Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?php echo htmlspecialchars($device['name']); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="serial_number" class="form-label">Serial Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="serial_number" name="serial_number"
                                   value="<?php echo htmlspecialchars($device['serial_number']); ?>" required>
                            <div class="form-text">Unique identifier used by the device to send logs</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="device_type" class="form-label">Device Type</label>
                        <input type="text" class="form-control" id="device_type" name="device_type"
                               value="<?php echo htmlspecialchars($device['device_type'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($device['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $device['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-text">Inactive devices cannot send logs</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Update Device
                        </button>
                        <a href="devices.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">Device Info</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <p class="mb-1 text-muted">Created At</p>
                        <p class="fw-bold"><?php echo date('Y-m-d H:i:s', strtotime($device['created_at'])); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1 text-muted">Last Updated</p>
                        <p class="fw-bold"><?php echo date('Y-m-d H:i:s', strtotime($device['updated_at'])); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1 text-muted">Last Seen</p>
                        <p class="fw-bold">
                            <?php echo $device['last_seen_at'] ? date('Y-m-d H:i:s', strtotime($device['last_seen_at'])) : 'Never'; ?>
                        </p>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="logs.php?device_id=<?php echo $device['id']; ?>" class="btn btn-outline-info">
                        <i class="bi bi-journal-text me-2"></i>View Logs
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
