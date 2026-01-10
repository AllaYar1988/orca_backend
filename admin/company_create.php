<?php
$pageTitle = 'Create Company';
$currentPage = 'company_create';

require_once __DIR__ . '/../models/Company.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyModel = new Company();

    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'code' => trim($_POST['code'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    if (empty($data['name']) || empty($data['code'])) {
        $message = 'Name and Code are required.';
        $messageType = 'danger';
    } else {
        try {
            $id = $companyModel->create($data);
            if ($id) {
                $message = 'Company created successfully!';
                $messageType = 'success';
                $data = ['name' => '', 'code' => '', 'email' => '', 'phone' => '', 'address' => '', 'is_active' => 1];
            } else {
                $message = 'Failed to create company.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $message = 'A company with this code already exists.';
            } else {
                $message = 'Database error: ' . $e->getMessage();
            }
            $messageType = 'danger';
        }
    }
} else {
    $data = ['name' => '', 'code' => '', 'email' => '', 'phone' => '', 'address' => '', 'is_active' => 1];
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-building me-2"></i>Create New Company
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?php echo htmlspecialchars($data['name']); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="code" class="form-label">Company Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="code" name="code"
                                   value="<?php echo htmlspecialchars($data['code']); ?>" required
                                   placeholder="e.g., ACME-001">
                            <div class="form-text">Unique identifier for this company</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo htmlspecialchars($data['email']); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($data['phone']); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($data['address']); ?></textarea>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $data['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-text">Inactive companies cannot send device logs</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Create Company
                        </button>
                        <a href="companies.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
