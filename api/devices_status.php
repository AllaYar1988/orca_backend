<?php
/**
 * Devices Status API
 * Returns online/offline status for multiple devices
 *
 * GET /api/devices_status.php?device_ids=1,2,3
 * GET /api/devices_status.php?company_id=1
 * Headers: Authorization: Bearer <token>
 *
 * Response:
 *   - success: boolean
 *   - devices: array of { id, is_online, last_seen_at }
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

$userModel = new User();
$db = Database::getInstance()->getConnection();

// Get device IDs either from comma-separated list or from company_id
$deviceIds = [];

if (isset($_GET['device_ids']) && !empty($_GET['device_ids'])) {
    // Parse comma-separated device IDs
    $deviceIds = array_map('intval', explode(',', $_GET['device_ids']));
    $deviceIds = array_filter($deviceIds, fn($id) => $id > 0);
} elseif (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
    // Get all devices for a company that user has access to
    $companyId = (int)$_GET['company_id'];

    // Check if user has access to this company
    if (!$userModel->hasAccessToCompany($authUser['id'], $companyId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this company']);
        exit;
    }

    // Get device IDs for this company that user has access to
    $devices = $userModel->getCompanyDevices($authUser['id'], $companyId);
    $deviceIds = array_column($devices, 'id');
}

if (empty($deviceIds)) {
    echo json_encode([
        'success' => true,
        'devices' => []
    ]);
    exit;
}

// Filter to only devices the user has access to
$accessibleDeviceIds = [];
foreach ($deviceIds as $deviceId) {
    if ($userModel->hasAccessToDevice($authUser['id'], $deviceId)) {
        $accessibleDeviceIds[] = $deviceId;
    }
}

if (empty($accessibleDeviceIds)) {
    echo json_encode([
        'success' => true,
        'devices' => []
    ]);
    exit;
}

// Get status for all accessible devices
$placeholders = implode(',', array_fill(0, count($accessibleDeviceIds), '?'));
$stmt = $db->prepare("
    SELECT
        id,
        last_seen_at,
        CASE
            WHEN last_seen_at IS NOT NULL
            AND last_seen_at >= UTC_TIMESTAMP() - INTERVAL 60 MINUTE
            THEN 1
            ELSE 0
        END as is_online
    FROM devices
    WHERE id IN ($placeholders)
");
$stmt->execute($accessibleDeviceIds);
$results = $stmt->fetchAll();

// Format response
$devices = [];
foreach ($results as $row) {
    $devices[] = [
        'id' => (int)$row['id'],
        'is_online' => (bool)$row['is_online'],
        'last_seen_at' => $row['last_seen_at']
    ];
}

echo json_encode([
    'success' => true,
    'devices' => $devices
]);
