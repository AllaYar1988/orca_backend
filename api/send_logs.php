<?php
/**
 * API: Send Batch Log Data (Device -> Server)
 *
 * Endpoint: POST /api/send_logs.php
 * Content-Type: application/json
 *
 * Request Body:
 * {
 *   "serial_number": "DEVICE-001",
 *   "timestamp": "2026-01-10 09:45:00",  // optional, shared timestamp for all
 *   "logs": [
 *     { "key": "temperature", "value": "25.5" },
 *     { "key": "humidity", "value": "60" },
 *     { "key": "pressure", "value": "1013" },
 *     { "key": "co2", "value": "450" }
 *   ]
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

if (empty($data['logs']) || !is_array($data['logs'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Logs array is required']);
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
$timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

$savedCount = 0;
$logIds = [];

foreach ($data['logs'] as $log) {
    if (empty($log['key'])) {
        continue;
    }

    $logData = [
        'device_id' => $device['id'],
        'serial_number' => $data['serial_number'],
        'log_key' => $log['key'],
        'log_value' => $log['value'] ?? null,
        'log_data' => $log['data'] ?? null,
        'ip_address' => $ipAddress,
        'logged_at' => $log['timestamp'] ?? $timestamp
    ];

    $logId = $logModel->create($logData);

    if ($logId) {
        $savedCount++;
        $logIds[] = $logId;
    }
}

if ($savedCount > 0) {
    $deviceModel->updateLastSeen($device['id']);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => "Saved $savedCount logs",
        'count' => $savedCount,
        'log_ids' => $logIds
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save any logs']);
}
