<?php
/**
 * API: Send Batch Log Data (Device -> Server) with HMAC Authentication
 *
 * Endpoint: POST /api/send_logs.php
 * Content-Type: application/json
 *
 * Request Body:
 * {
 *   "serial_number": "DEVICE-001",
 *   "timestamp": 1736505600,           // Unix timestamp (required for auth)
 *   "signature": "abc123...",          // SHA256(api_key + timestamp)
 *   "logs": [
 *     { "key": "temperature", "value": "25.5" },
 *     { "key": "humidity", "value": "60" },
 *     { "key": "pressure", "value": "1013" },
 *     { "key": "co2", "value": "450" }
 *   ]
 * }
 *
 * STM32F407 Example (using mbedTLS or hardware SHA256):
 *   char message[128];
 *   sprintf(message, "%s%ld", api_key, timestamp);
 *   sha256(message, strlen(message), signature_hex);
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

// Validate required fields
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

if (empty($data['timestamp']) || empty($data['signature'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Timestamp and signature are required']);
    exit;
}

// Verify HMAC authentication
$deviceModel = new Device();
$authResult = $deviceModel->verifyHmac(
    $data['serial_number'],
    (int)$data['timestamp'],
    $data['signature']
);

if (!$authResult['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $authResult['error']]);
    exit;
}

$device = $authResult['device'];

// Save logs
$logModel = new DeviceLog();
$sensorConfigModel = new SensorConfig();
$logTimestamp = date('Y-m-d H:i:s', (int)$data['timestamp']);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

$savedCount = 0;
$logIds = [];
$alarms = [];

foreach ($data['logs'] as $log) {
    if (empty($log['key'])) {
        continue;
    }

    $logKey = $log['key'];
    $rawValue = $log['value'] ?? null;

    // Get sensor config for this log key
    $sensorConfig = $sensorConfigModel->getConfig($device['id'], $logKey);

    // Apply zero-span conversion if sensor is 4-20mA type
    $convertedValue = $rawValue;
    if ($sensorConfig && $sensorConfig['data_type'] === '4-20' && $rawValue !== null) {
        $convertedValue = $sensorConfigModel->convertValue($rawValue, $sensorConfig);
    }

    // Check for alarms
    if ($sensorConfig && $convertedValue !== null) {
        $alarmResult = $sensorConfigModel->checkAlarm($convertedValue, $sensorConfig);
        if ($alarmResult['alarm']) {
            $alarms[] = [
                'key' => $logKey,
                'type' => $alarmResult['type'],
                'value' => $convertedValue,
                'message' => $alarmResult['message']
            ];
        }
    }

    $logData = [
        'device_id' => $device['id'],
        'serial_number' => $data['serial_number'],
        'log_key' => $logKey,
        'log_value' => $convertedValue,  // Store converted value
        'log_data' => $log['data'] ?? null,
        'ip_address' => $ipAddress,
        'logged_at' => $log['timestamp'] ?? $logTimestamp
    ];

    $logId = $logModel->create($logData);

    if ($logId) {
        $savedCount++;
        $logIds[] = $logId;
    }
}

if ($savedCount > 0) {
    $deviceModel->updateLastSeen($device['id'], (int)$data['timestamp']);

    $response = [
        'success' => true,
        'message' => "Saved $savedCount logs",
        'count' => $savedCount,
        'log_ids' => $logIds
    ];

    // Include alarms if any
    if (!empty($alarms)) {
        $response['alarms'] = $alarms;
    }

    http_response_code(201);
    echo json_encode($response);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save any logs']);
}
