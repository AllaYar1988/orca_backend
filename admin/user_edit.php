<?php
$pageTitle = 'Edit User';
$currentPage = 'users';

require_once __DIR__ . '/../models/User.php';

$userModel = new User();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'username' => trim(isset($_POST['username']) ? $_POST['username'] : ''),
        'name' => trim(isset($_POST['name']) ? $_POST['name'] : ''),
        'email' => trim(isset($_POST['email']) ? $_POST['email'] : ''),
        'phone' => trim(isset($_POST['phone']) ? $_POST['phone'] : ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    // Only update password if provided
    if (!empty($_POST['password'])) {
        if (strlen($_POST['password']) < 6) {
            $message = 'Password must be at least 6 characters.';
            $messageType = 'danger';
        } else {
            $data['password'] = $_POST['password'];
        }
    }

    if (empty($message)) {
        if (empty($data['username'])) {
            $message = 'Username is required.';
            $messageType = 'danger';
        } else {
            try {
                if ($userModel->update($id, $data)) {
                    $message = 'User updated successfully!';
                    $messageType = 'success';
                    $user = $userModel->getById($id);
                } else {
                    $message = 'No changes made.';
                    $messageType = 'info';
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $message = 'A user with this username already exists.';
                } else {
                    $message = 'Database error: ' . $e->getMessage();
                }
                $messageType = 'danger';
            }
        }
    }
}

$assignedDevices = $userModel->getAssignedDevices($id);
$assignedCompanies = $userModel->getAssignedCompanies($id);

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pencil me-2"></i>Edit User
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
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Leave blank to keep current">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?php echo htmlspecialchars($user['name'] ? $user['name'] : ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo htmlspecialchars($user['email'] ? $user['email'] : ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($user['phone'] ? $user['phone'] : ''); ?>">
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-text">Inactive users cannot log in</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Update User
                        </button>
                        <a href="user_devices.php?id=<?php echo $id; ?>" class="btn btn-info">
                            <i class="bi bi-building me-2"></i>Manage Access
                        </a>
                        <a href="users.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <span>Assigned Companies (<?php echo count($assignedCompanies); ?>)</span>
            </div>
            <div class="card-body">
                <?php if (empty($assignedCompanies)): ?>
                <p class="text-muted mb-0">No companies assigned to this user.</p>
                <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($assignedCompanies as $company): ?>
                    <span class="badge bg-primary">
                        <i class="bi bi-building me-1"></i>
                        <?php echo htmlspecialchars($company['name']); ?>
                        (<?php echo $company['device_count']; ?> devices)
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Assigned Devices (<?php echo count($assignedDevices); ?>)</span>
                <a href="user_devices.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">Manage</a>
            </div>
            <div class="card-body">
                <?php if (empty($assignedDevices)): ?>
                <p class="text-muted mb-0">No devices assigned to this user.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Device Name</th>
                                <th>Serial Number</th>
                                <th>Company</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignedDevices as $device): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($device['name']); ?></td>
                                <td><code><?php echo htmlspecialchars($device['serial_number']); ?></code></td>
                                <td><?php echo htmlspecialchars($device['company_name'] ? $device['company_name'] : '-'); ?></td>
                                <td><?php echo htmlspecialchars($device['device_type'] ? $device['device_type'] : '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">User Info</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <p class="mb-1 text-muted">Created At</p>
                        <p class="fw-bold"><?php echo date('Y-m-d H:i:s', strtotime($user['created_at'])); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1 text-muted">Last Updated</p>
                        <p class="fw-bold"><?php echo date('Y-m-d H:i:s', strtotime($user['updated_at'])); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1 text-muted">Last Login</p>
                        <p class="fw-bold">
                            <?php echo $user['last_login_at'] ? date('Y-m-d H:i:s', strtotime($user['last_login_at'])) : 'Never'; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
