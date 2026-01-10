<?php
/**
 * Device Last Update API
 * Returns the timestamp of the most recent log for a device
 *
 * GET /api/device_last_update.php?device_id=...
 * Headers: Authorization: Bearer <token>
 *
 * Parameters:
 *   - device_id: Required. Device ID to check
 *
 * Response:
 *   - success: boolean
 *   - last_update: datetime string of most recent log (or null if no logs)
 *   - has_data: boolean indicating if device has any logs
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

$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;

if (!$deviceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Device ID is required']);
    exit;
}

$userModel = new User();

// Check if user has access to this device
if (!$userModel->hasAccessToDevice($authUser['id'], $deviceId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to this device']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Get the most recent log timestamp for this device
$stmt = $db->prepare("
    SELECT MAX(logged_at) as last_update
    FROM device_logs
    WHERE device_id = :device_id
");
$stmt->execute([':device_id' => $deviceId]);
$result = $stmt->fetch();

$lastUpdate = $result['last_update'];

echo json_encode([
    'success' => true,
    'last_update' => $lastUpdate,
    'has_data' => $lastUpdate !== null
]);
