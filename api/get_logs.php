<?php
/**
 * API: Get Log Data (Admin/App -> Server)
 *
 * Endpoint: GET /api/get_logs.php
 *
 * Query Parameters:
 * - serial_number: Filter by device serial number
 * - company_id: Filter by company
 * - device_id: Filter by device
 * - key: Filter by log key
 * - date_from: Start date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
 * - date_to: End date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
 * - search: Search in key, value, serial number
 * - page: Page number (default: 1)
 * - limit: Items per page (default: 50, max: 500)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../models/DeviceLog.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(500, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$filters = [
    'limit' => $limit,
    'offset' => $offset
];

if (!empty($_GET['serial_number'])) {
    $filters['serial_number'] = $_GET['serial_number'];
}

if (!empty($_GET['company_id'])) {
    $filters['company_id'] = (int)$_GET['company_id'];
}

if (!empty($_GET['device_id'])) {
    $filters['device_id'] = (int)$_GET['device_id'];
}

if (!empty($_GET['key'])) {
    $filters['log_key'] = $_GET['key'];
}

if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}

$logModel = new DeviceLog();

$logs = $logModel->getAll($filters);
$totalCount = $logModel->count($filters);
$totalPages = ceil($totalCount / $limit);

echo json_encode([
    'success' => true,
    'data' => $logs,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total_items' => $totalCount,
        'total_pages' => $totalPages,
        'has_next' => $page < $totalPages,
        'has_prev' => $page > 1
    ]
]);
