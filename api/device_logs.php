<?php
/**
 * Device Logs API
 * Returns logs for a specific device
 *
 * GET /api/device_logs.php?device_id=...&limit=...&offset=...
 * GET /api/device_logs.php?device_id=...&from=...&to=...  (date range)
 * Headers: Authorization: Bearer <token>
 *
 * Parameters:
 *   - device_id: Required. Device ID to fetch logs for
 *   - limit: Optional. Max number of logs (default 50, max 500)
 *   - offset: Optional. Pagination offset
 *   - from: Optional. Start datetime (ISO format: YYYY-MM-DDTHH:mm:ss or YYYY-MM-DD HH:mm:ss)
 *   - to: Optional. End datetime (ISO format: YYYY-MM-DDTHH:mm:ss or YYYY-MM-DD HH:mm:ss)
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
$from = isset($_GET['from']) ? $_GET['from'] : null;
$to = isset($_GET['to']) ? $_GET['to'] : null;

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

// Build query based on whether date range is provided
if ($from && $to) {
    // Convert ISO format (with T) to MySQL format if needed
    $fromDate = str_replace('T', ' ', $from);
    $toDate = str_replace('T', ' ', $to);
    // Remove timezone info if present (Z or +00:00)
    $fromDate = preg_replace('/(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', '', $fromDate);
    $toDate = preg_replace('/(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', '', $toDate);

    // Get total count for date range
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM device_logs
        WHERE device_id = :device_id
        AND logged_at >= :from_date
        AND logged_at <= :to_date
    ");
    $stmt->execute([
        ':device_id' => $deviceId,
        ':from_date' => $fromDate,
        ':to_date' => $toDate
    ]);
    $total = (int)$stmt->fetchColumn();

    // Get logs within date range (ordered ascending for time series)
    // If limit is provided, use pagination; otherwise return all
    if (isset($_GET['limit'])) {
        $stmt = $db->prepare("
            SELECT * FROM device_logs
            WHERE device_id = :device_id
            AND logged_at >= :from_date
            AND logged_at <= :to_date
            ORDER BY logged_at ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':device_id', $deviceId, PDO::PARAM_INT);
        $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
        $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM device_logs
            WHERE device_id = :device_id
            AND logged_at >= :from_date
            AND logged_at <= :to_date
            ORDER BY logged_at ASC
        ");
        $stmt->bindValue(':device_id', $deviceId, PDO::PARAM_INT);
        $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
        $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll();

    $response = [
        'success' => true,
        'logs' => $logs,
        'total' => $total,
        'from' => $fromDate,
        'to' => $toDate
    ];

    // Include pagination info if limit was provided
    if (isset($_GET['limit'])) {
        $response['limit'] = $limit;
        $response['offset'] = $offset;
        $response['has_more'] = ($offset + count($logs)) < $total;
    }

    echo json_encode($response);
} else {
    // Original behavior with limit/offset
    // Get total count
    $stmt = $db->prepare("SELECT COUNT(*) FROM device_logs WHERE device_id = :device_id");
    $stmt->execute([':device_id' => $deviceId]);
    $total = (int)$stmt->fetchColumn();

    // Get logs
    $stmt = $db->prepare("
        SELECT * FROM device_logs
        WHERE device_id = :device_id
        ORDER BY logged_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':device_id', $deviceId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}
