<?php
$pageTitle = 'Virtual Devices';
$currentPage = 'virtual_devices';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/VirtualDevice.php';

$companyModel = new Company();
$virtualDeviceModel = new VirtualDevice();

$companies = $companyModel->getAll();

$search = $_GET['search'] ?? '';
$companyFilter = $_GET['company_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$filters = [
    'limit' => $limit,
    'offset' => $offset
];

if ($search) {
    $filters['search'] = $search;
}

if ($companyFilter) {
    $filters['company_id'] = (int)$companyFilter;
}

if ($statusFilter !== '') {
    $filters['is_active'] = (int)$statusFilter;
}

$virtualDevices = $virtualDeviceModel->getAll($filters);
$totalCount = $virtualDeviceModel->count($filters);
$totalPages = ceil($totalCount / $limit);

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int)$_POST['delete_id'];
    if ($virtualDeviceModel->delete($deleteId)) {
        header('Location: virtual_devices.php?deleted=1');
        exit;
    }
}

include 'includes/header.php';
?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Virtual device deleted successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Virtual device created successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Virtual device updated successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-2"></i>Virtual Devices</span>
        <a href="virtual_device_create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Virtual Device
        </a>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Search name or description..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="company_id">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $company): ?>
                    <option value="<?php echo $company['id']; ?>"
                        <?php echo $companyFilter == $company['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="1" <?php echo $statusFilter === '1' ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo $statusFilter === '0' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
            </div>
            <?php if ($search || $companyFilter || $statusFilter !== ''): ?>
            <div class="col-md-2">
                <a href="virtual_devices.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
            <?php endif; ?>
        </form>

        <!-- Info Alert -->
        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Virtual Devices</strong> group sensors from multiple physical devices of the same company.
            Users assigned to a virtual device see aggregated sensor data as if it were a single device.
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Sensors</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($virtualDevices)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No virtual devices found</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($virtualDevices as $vd): ?>
                    <tr>
                        <td><?php echo $vd['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($vd['name']); ?></strong></td>
                        <td>
                            <a href="companies.php?search=<?php echo urlencode($vd['company_code']); ?>">
                                <?php echo htmlspecialchars($vd['company_name'] ?? '-'); ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo (int)$vd['sensor_count']; ?> sensors</span>
                        </td>
                        <td>
                            <?php if ($vd['description']): ?>
                            <span title="<?php echo htmlspecialchars($vd['description']); ?>">
                                <?php echo htmlspecialchars(substr($vd['description'], 0, 50)); ?>
                                <?php echo strlen($vd['description']) > 50 ? '...' : ''; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($vd['is_active']): ?>
                            <span class="badge badge-active">Active</span>
                            <?php else: ?>
                            <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span title="<?php echo $vd['created_at']; ?>">
                                <?php echo date('Y-m-d', strtotime($vd['created_at'])); ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="virtual_device_edit.php?id=<?php echo $vd['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" title="Delete"
                                        onclick="confirmDelete(<?php echo $vd['id']; ?>, '<?php echo htmlspecialchars(addslashes($vd['name'])); ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&company_id=<?php echo $companyFilter; ?>&status=<?php echo $statusFilter; ?>">
                        Previous
                    </a>
                </li>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&company_id=<?php echo $companyFilter; ?>&status=<?php echo $statusFilter; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&company_id=<?php echo $companyFilter; ?>&status=<?php echo $statusFilter; ?>">
                        Next
                    </a>
                </li>
            </ul>
        </nav>
        <p class="text-center text-muted mt-2">
            Showing <?php echo count($virtualDevices); ?> of <?php echo $totalCount; ?> virtual devices
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteDeviceName"></strong>?</p>
                <p class="text-danger mb-0">This will also remove all user assignments for this virtual device.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="delete_id" id="deleteId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteDeviceName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>
