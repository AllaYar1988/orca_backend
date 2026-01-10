<?php
/**
 * API: Send Log Data (Device -> Server)
 *
 * Endpoint: POST /api/send_log.php
 * Content-Type: application/json
 *
 * Request Body:
 * {
 *   "serial_number": "DEVICE-001",
 *   "key": "temperature",
 *   "value": "25.5",
 *   "data": { ... }  // optional additional data
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../models/Device.php';
require_once __DIR__ . '/../models/DeviceLog.php';
require_once __DIR__ . '/../src/WebSocket/WebSocketNotifier.php';



$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (empty($data['serial_number'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Serial number is required']);
    exit;
}

$deviceModel = new Device();
$device = $deviceModel->getBySerialNumber($data['serial_number']);

if (!$device) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Device not found']);
    exit;
}

if (!$device['is_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Device is inactive']);
    exit;
}

if (!$device['company_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Company is inactive']);
    exit;
}

$logModel = new DeviceLog();

$logData = [
    'device_id' => $device['id'],
    'serial_number' => $data['serial_number'],
    'log_key' => $data['key'] ?? null,
    'log_value' => $data['value'] ?? null,
    'log_data' => $data['data'] ?? null,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'logged_at' => $data['timestamp'] ?? date('Y-m-d H:i:s')
];

$logId = $logModel->create($logData);

if ($logId) {
    $deviceModel->updateLastSeen($device['id']);

    // Broadcast to WebSocket clients
    $logData['id'] = $logId;
    WebSocketNotifier::broadcast((int)$device['id'], $logData);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Log recorded',
        'log_id' => $logId
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save log']);
}
