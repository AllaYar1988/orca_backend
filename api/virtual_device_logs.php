<?php
/**
 * Virtual Device Logs API
 * Returns logs for all sensors in a virtual device
 *
 * GET /api/virtual_device_logs.php?id=1
 * Optional params:
 *   - from: Start datetime (YYYY-MM-DD HH:mm:ss)
 *   - to: End datetime (YYYY-MM-DD HH:mm:ss)
 *   - limit: Max records per sensor (default: no limit)
 *   - offset: Offset for pagination
 *
 * Headers: Authorization: Bearer <token>
 *
 * Response format:
 * {
 *   "success": true,
 *   "logs": [
 *     {
 *       "id": 123,
 *       "log_key": "TMP_001",
 *       "log_value": "25.5",
 *       "logged_at": "2024-01-15 10:30:00",
 *       "source_device_id": 1,
 *       "source_device_name": "Device A",
 *       "sensor_label": "Temperature"
 *     }
 *   ],
 *   "total": 1500,
 *   "from": "2024-01-15 00:00:00",
 *   "to": "2024-01-15 23:59:59"
 * }
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
require_once __DIR__ . '/../models/VirtualDevice.php';

$userModel = new User();

$virtualDeviceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$virtualDeviceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Virtual device ID is required']);
    exit;
}

// Check access
if (!$userModel->hasAccessToVirtualDevice($authUser['id'], $virtualDeviceId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to this virtual device']);
    exit;
}

// Get date range parameters
$from = isset($_GET['from']) ? $_GET['from'] : null;
$to = isset($_GET['to']) ? $_GET['to'] : null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Default to today if no date range specified
if (!$from || !$to) {
    $today = date('Y-m-d');
    $from = $today . ' 00:00:00';
    $to = $today . ' 23:59:59';
}

$virtualDeviceModel = new VirtualDevice();
$db = Database::getInstance()->getConnection();

// Get virtual device info and sensors
$vd = $virtualDeviceModel->getById($virtualDeviceId);
if (!$vd) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Virtual device not found']);
    exit;
}

// Get sensor mappings
$sensorStmt = $db->prepare("
    SELECT vds.*, d.name as source_device_name
    FROM virtual_device_sensors vds
    JOIN devices d ON vds.source_device_id = d.id
    WHERE vds.virtual_device_id = :vd_id
    ORDER BY vds.display_order, vds.id
");
$sensorStmt->execute([':vd_id' => $virtualDeviceId]);
$sensors = $sensorStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($sensors)) {
    echo json_encode([
        'success' => true,
        'logs' => [],
        'total' => 0,
        'from' => $from,
        'to' => $to
    ]);
    exit;
}

// Build query for logs from all mapped sensors
// We need to get logs for each (device_id, log_key) pair
$whereClauses = [];
$params = [':from' => $from, ':to' => $to];

foreach ($sensors as $i => $sensor) {
    $deviceParam = ":dev_$i";
    $keyParam = ":key_$i";
    $whereClauses[] = "(dl.device_id = $deviceParam AND dl.log_key = $keyParam)";
    $params[$deviceParam] = $sensor['source_device_id'];
    $params[$keyParam] = $sensor['source_log_key'];
}

$whereClause = '(' . implode(' OR ', $whereClauses) . ')';

// Count total logs
$countSql = "
    SELECT COUNT(*) as total
    FROM device_logs dl
    WHERE $whereClause
    AND dl.logged_at BETWEEN :from AND :to
";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

// Fetch logs
$sql = "
    SELECT
        dl.id,
        dl.device_id as source_device_id,
        dl.log_key,
        dl.log_value,
        dl.logged_at,
        dl.status,
        d.name as source_device_name
    FROM device_logs dl
    JOIN devices d ON dl.device_id = d.id
    WHERE $whereClause
    AND dl.logged_at BETWEEN :from AND :to
    ORDER BY dl.logged_at ASC
";

if ($limit !== null) {
    $sql .= " LIMIT $limit OFFSET $offset";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create sensor label lookup
$sensorLabels = [];
foreach ($sensors as $sensor) {
    $key = $sensor['source_device_id'] . '_' . $sensor['source_log_key'];
    $sensorLabels[$key] = $sensor['label'];
}

// Add sensor label to each log
$formattedLogs = [];
foreach ($logs as $log) {
    $key = $log['source_device_id'] . '_' . $log['log_key'];
    $formattedLogs[] = [
        'id' => (int)$log['id'],
        'log_key' => $log['log_key'],
        'log_value' => $log['log_value'],
        'logged_at' => $log['logged_at'],
        'status' => $log['status'],
        'source_device_id' => (int)$log['source_device_id'],
        'source_device_name' => $log['source_device_name'],
        'sensor_label' => $sensorLabels[$key] ?? $log['log_key']
    ];
}

echo json_encode([
    'success' => true,
    'logs' => $formattedLogs,
    'total' => (int)$totalCount,
    'from' => $from,
    'to' => $to,
    'has_more' => $limit !== null && ($offset + count($formattedLogs)) < $totalCount
]);
