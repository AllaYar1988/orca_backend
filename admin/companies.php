<?php
$pageTitle = 'Company List';
$currentPage = 'companies';

require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Device.php';

$companyModel = new Company();
$deviceModel = new Device();

$search = $_GET['search'] ?? '';
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

if ($statusFilter !== '') {
    $filters['is_active'] = (int)$statusFilter;
}

$companies = $companyModel->getAll($filters);
$totalCount = $companyModel->count($filters);
$totalPages = ceil($totalCount / $limit);

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int)$_POST['delete_id'];
    if ($companyModel->delete($deleteId)) {
        header('Location: companies.php?deleted=1');
        exit;
    }
}

include 'includes/header.php';
?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    Company deleted successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building me-2"></i>Companies</span>
        <a href="company_create.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Company
        </a>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search by name or code..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
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
            <?php if ($search || $statusFilter !== ''): ?>
            <div class="col-md-2">
                <a href="companies.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Devices</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No companies found</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($companies as $company): ?>
                    <?php $deviceCount = $deviceModel->count(['company_id' => $company['id']]); ?>
                    <tr>
                        <td><?php echo $company['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($company['name']); ?></strong></td>
                        <td><code><?php echo htmlspecialchars($company['code']); ?></code></td>
                        <td><?php echo htmlspecialchars($company['email'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($company['phone'] ?? '-'); ?></td>
                        <td>
                            <a href="devices.php?company_id=<?php echo $company['id']; ?>" class="text-decoration-none">
                                <?php echo $deviceCount; ?> device<?php echo $deviceCount !== 1 ? 's' : ''; ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($company['is_active']): ?>
                            <span class="badge badge-active">Active</span>
                            <?php else: ?>
                            <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('Y-m-d', strtotime($company['created_at'])); ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="company_edit.php?id=<?php echo $company['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" title="Delete"
                                        onclick="confirmDelete(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars(addslashes($company['name'])); ?>')">
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
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $statusFilter; ?>">
                        Previous
                    </a>
                </li>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $statusFilter; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>

                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $statusFilter; ?>">
                        Next
                    </a>
                </li>
            </ul>
        </nav>
        <p class="text-center text-muted mt-2">
            Showing <?php echo count($companies); ?> of <?php echo $totalCount; ?> companies
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
                <p>Are you sure you want to delete <strong id="deleteCompanyName"></strong>?</p>
                <p class="text-danger mb-0">This will also delete all devices and logs associated with this company.</p>
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
    document.getElementById('deleteCompanyName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>
