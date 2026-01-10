<?php
/**
 * User Logs API
 * Returns logs for devices assigned to the authenticated user
 *
 * GET /api/user_logs.php?device_id=Y&limit=50&offset=0
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

require_once __DIR__ . '/../models/DeviceLog.php';

$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$logKey = isset($_GET['log_key']) ? $_GET['log_key'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$userModel = new User();
$logModel = new DeviceLog();

// Get assigned device IDs
$assignedDeviceIds = $userModel->getAssignedDeviceIds($authUser['id']);

if (empty($assignedDeviceIds)) {
    echo json_encode([
        'success' => true,
        'user_id' => $authUser['id'],
        'total_count' => 0,
        'logs' => []
    ]);
    exit;
}

// Build filters
$filters = [
    'limit' => $limit,
    'offset' => $offset
];

// If specific device requested, verify user has access
if ($deviceId) {
    if (!in_array($deviceId, $assignedDeviceIds)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have access to this device']);
        exit;
    }
    $filters['device_id'] = $deviceId;
} else {
    $filters['device_ids'] = $assignedDeviceIds;
}

if ($logKey) {
    $filters['log_key'] = $logKey;
}
if ($dateFrom) {
    $filters['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $filters['date_to'] = $dateTo . ' 23:59:59';
}

// Get logs
$logs = $logModel->getAll($filters);
$totalCount = $logModel->count($filters);

// Format response
$formattedLogs = [];
foreach ($logs as $log) {
    $formattedLogs[] = [
        'id' => $log['id'],
        'device_id' => $log['device_id'],
        'device_name' => $log['device_name'],
        'serial_number' => $log['serial_number'],
        'log_key' => $log['log_key'],
        'log_value' => $log['log_value'],
        'log_data' => $log['log_data'],
        'ip_address' => $log['ip_address'],
        'logged_at' => $log['logged_at']
    ];
}

echo json_encode([
    'success' => true,
    'user_id' => $authUser['id'],
    'total_count' => $totalCount,
    'limit' => $limit,
    'offset' => $offset,
    'logs' => $formattedLogs
]);
