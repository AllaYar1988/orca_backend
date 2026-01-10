<?php
/**
 * Device Events API
 * Returns events for a specific device
 *
 * GET /api/device_events.php?device_id=...&limit=...&offset=...&severity=...
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

$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$severity = isset($_GET['severity']) ? $_GET['severity'] : null;

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

// Build query with optional severity filter
$whereClause = "WHERE device_id = :device_id";
$params = [':device_id' => $deviceId];

if ($severity && in_array($severity, ['info', 'warning', 'error', 'critical'])) {
    $whereClause .= " AND severity = :severity";
    $params[':severity'] = $severity;
}

// Get total count
$stmt = $db->prepare("SELECT COUNT(*) FROM device_events $whereClause");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Get events
$sql = "SELECT * FROM device_events $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'events' => $events,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset
]);
