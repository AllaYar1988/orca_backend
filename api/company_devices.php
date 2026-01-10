<?php
/**
 * Company Devices API
 * Returns devices for a company that the authenticated user has access to
 *
 * GET /api/company_devices.php?company_id=...
 * Headers: Authorization: Bearer <token>
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Authenticate user via token
require_once __DIR__ . '/auth_middleware.php';
// $authUser is now available with user data

require_once __DIR__ . '/../config/database.php';

$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Company ID is required']);
    exit;
}

$userModel = new User();

// Check if user has access to this company
if (!$userModel->hasAccessToCompany($authUser['id'], $companyId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to this company']);
    exit;
}

// Get company info
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM companies WHERE id = :id AND is_active = 1");
$stmt->execute([':id' => $companyId]);
$company = $stmt->fetch();

if (!$company) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Company not found']);
    exit;
}

// Get devices for this company that the user has access to
$devices = $userModel->getCompanyDevices($authUser['id'], $companyId);

echo json_encode([
    'success' => true,
    'company' => $company,
    'devices' => $devices
]);
