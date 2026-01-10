<?php
$pageTitle = 'Create User';
$currentPage = 'user_create';

require_once __DIR__ . '/../models/User.php';

$userModel = new User();

$message = '';
$messageType = '';
$newUserId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'username' => trim(isset($_POST['username']) ? $_POST['username'] : ''),
        'password' => isset($_POST['password']) ? $_POST['password'] : '',
        'name' => trim(isset($_POST['name']) ? $_POST['name'] : ''),
        'email' => trim(isset($_POST['email']) ? $_POST['email'] : ''),
        'phone' => trim(isset($_POST['phone']) ? $_POST['phone'] : ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'role' => isset($_POST['role']) && in_array($_POST['role'], User::$validRoles) ? $_POST['role'] : User::ROLE_USER
    ];

    if (empty($data['username']) || empty($data['password'])) {
        $message = 'Username and Password are required.';
        $messageType = 'danger';
    } elseif (strlen($data['password']) < 6) {
        $message = 'Password must be at least 6 characters.';
        $messageType = 'danger';
    } else {
        try {
            $id = $userModel->create($data);
            if ($id) {
                $newUserId = $id;
                $message = 'User created successfully! You can now assign companies and devices.';
                $messageType = 'success';
                $data = ['username' => '', 'name' => '', 'email' => '', 'phone' => '', 'is_active' => 1, 'role' => User::ROLE_USER];
            } else {
                $message = 'Failed to create user.';
                $messageType = 'danger';
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
} else {
    $data = [
        'username' => '',
        'name' => '',
        'email' => '',
        'phone' => '',
        'is_active' => 1,
        'role' => User::ROLE_USER
    ];
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-plus me-2"></i>Create New User
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <?php if ($newUserId): ?>
                    <a href="user_devices.php?id=<?php echo $newUserId; ?>" class="alert-link">Assign Companies & Devices</a>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?php echo htmlspecialchars($data['username']); ?>" required
                                   placeholder="e.g., john.doe">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required
                                   placeholder="Min 6 characters">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="user" <?php echo ($data['role'] === 'user') ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo ($data['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="viewer" <?php echo ($data['role'] === 'viewer') ? 'selected' : ''; ?>>Viewer</option>
                            </select>
                            <div class="form-text">Admin: Full access | User: Edit access | Viewer: Read-only</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?php echo htmlspecialchars($data['name']); ?>"
                                   placeholder="e.g., John Doe">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?php echo htmlspecialchars($data['email']); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($data['phone']); ?>">
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?php echo $data['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-text">Inactive users cannot log in</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Create User
                        </button>
                        <a href="users.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </form>

                <div class="alert alert-info mt-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> After creating a user, use "Manage Access" to assign companies and devices to the user.
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
