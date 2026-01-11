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
require_once __DIR__ . '/../models/SensorConfig.php';

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
$sensorConfigModel = new SensorConfig();

$logKey = $data['key'] ?? null;
$rawValue = $data['value'] ?? null;

// Get sensor config for this log key
$sensorConfig = null;
$convertedValue = $rawValue;
$status = 'normal';

if ($logKey) {
    $sensorConfig = $sensorConfigModel->getConfig($device['id'], $logKey);

    // Apply zero-span conversion if sensor is 4-20mA type
    if ($sensorConfig && $sensorConfig['data_type'] === '4-20' && $rawValue !== null) {
        $convertedValue = $sensorConfigModel->convertValue($rawValue, $sensorConfig);
    }

    // Calculate status tag based on thresholds
    if ($sensorConfig && $convertedValue !== null) {
        $status = $sensorConfigModel->calculateStatus($convertedValue, $sensorConfig);
    }
}

$logData = [
    'device_id' => $device['id'],
    'serial_number' => $data['serial_number'],
    'log_key' => $logKey,
    'log_value' => $convertedValue,
    'status' => $status,
    'log_data' => $data['data'] ?? null,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'logged_at' => $data['timestamp'] ?? date('Y-m-d H:i:s')
];

$logId = $logModel->create($logData);

if ($logId) {
    $deviceModel->updateLastSeen($device['id']);

    $response = [
        'success' => true,
        'message' => 'Log recorded',
        'log_id' => $logId,
        'status' => $status
    ];

    // Include alarm info if critical
    if ($status === 'critical' && $sensorConfig) {
        $alarmResult = $sensorConfigModel->checkAlarm($convertedValue, $sensorConfig);
        if ($alarmResult['alarm']) {
            $response['alarm'] = $alarmResult;
        }
    }

    http_response_code(201);
    echo json_encode($response);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save log']);
}
