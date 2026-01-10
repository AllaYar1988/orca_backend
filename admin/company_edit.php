<?php
$pageTitle = 'Edit Company';
$currentPage = 'companies';

require_once __DIR__ . '/../models/Company.php';

$companyModel = new Company();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: companies.php');
    exit;
}

$company = $companyModel->getById($id);
if (!$company) {
    header('Location: companies.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            if ($companyModel->update($id, $data)) {
                $message = 'Company updated successfully!';
                $messageType = 'success';
                $company = $companyModel->getById($id);
            } else {
                $message = 'No changes made.';
                $messageType = 'info';
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
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pencil me-2"></i>Edit Company
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
                                   value="<?php echo htmlspecialchars($company['name']); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="code" class="form-label">Company Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="code" name="code"
                                   value="<?php echo htmlspecialchars($company['code']); ?>" required>
                            <div class="form-text">Unique identifier for this company</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo htmlspecialchars($company['email'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($company['phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($company['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $company['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-text">Inactive companies cannot send device logs</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Update Company
                        </button>
                        <a href="companies.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">Company Info</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1 text-muted">Created At</p>
                        <p class="fw-bold"><?php echo date('Y-m-d H:i:s', strtotime($company['created_at'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1 text-muted">Last Updated</p>
                        <p class="fw-bold"><?php echo date('Y-m-d H:i:s', strtotime($company['updated_at'])); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
