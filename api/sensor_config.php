<?php
/**
 * API: Sensor Configuration Management
 *
 * Endpoints:
 *   GET  /api/sensor_config.php?device_id=1           - Get all configs for device
 *   GET  /api/sensor_config.php?device_id=1&key=temperature - Get config for specific sensor
 *   POST /api/sensor_config.php                       - Create/update sensor config
 *   DELETE /api/sensor_config.php?device_id=1&key=temperature - Delete sensor config
 *
 * POST Body Example:
 * {
 *   "device_id": 1,
 *   "log_key": "temperature",
 *   "data_type": "4-20",       // "4-20" or "real"
 *   "zero_value": 0,           // Value at 4mA (for 4-20 type)
 *   "span_value": 100,         // Range (for 4-20 type)
 *   "unit": "°C",
 *   "decimals": 2,
 *   "sensor_type": "TMP",      // Sensor type code
 *   "min_alarm": 5,
 *   "max_alarm": 35,
 *   "alarm_enabled": true,
 *   "label": "Room Temperature" // Optional custom label
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
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authenticate user via token
require_once __DIR__ . '/auth_middleware.php';
// $authUser is now available with user data

require_once __DIR__ . '/../models/SensorConfig.php';
require_once __DIR__ . '/../models/Device.php';

$sensorConfigModel = new SensorConfig();
$deviceModel = new Device();
$userModel = new User();

// GET - Retrieve configs
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $deviceId = $_GET['device_id'] ?? null;
    $logKey = $_GET['key'] ?? null;

    if (!$deviceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'device_id is required']);
        exit;
    }

    // Verify device exists
    $device = $deviceModel->getById($deviceId);
    if (!$device) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Device not found']);
        exit;
    }

    // Verify user has access to this device
    if (!$userModel->hasAccessToDevice($authUser['id'], $deviceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this device']);
        exit;
    }

    // Check for sensor-level restrictions
    $allowedSensors = $userModel->getAllowedSensors($authUser['id'], $deviceId);
    $hasSensorRestrictions = !empty($allowedSensors);

    if ($logKey) {
        // Check sensor access if restrictions exist
        if ($hasSensorRestrictions && !in_array($logKey, $allowedSensors)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied to this sensor']);
            exit;
        }

        // Get specific sensor config
        $config = $sensorConfigModel->getConfig($deviceId, $logKey);
        if ($config) {
            echo json_encode(['success' => true, 'config' => $config]);
        } else {
            // Return default config if none exists
            echo json_encode([
                'success' => true,
                'config' => null,
                'default' => [
                    'device_id' => (int)$deviceId,
                    'log_key' => $logKey,
                    'data_type' => 'real',
                    'zero_value' => 0,
                    'span_value' => 100,
                    'unit' => '',
                    'decimals' => 2,
                    'sensor_type' => 'GEN',
                    'min_alarm' => null,
                    'max_alarm' => null,
                    'alarm_enabled' => false,
                    'label' => null
                ]
            ]);
        }
    } else {
        // Get all configs for device
        $configs = $sensorConfigModel->getByDevice($deviceId);

        // Also get all unique log keys from device_logs
        $logKeys = $sensorConfigModel->getDeviceLogKeys($deviceId);

        // Filter by allowed sensors if restrictions exist
        if ($hasSensorRestrictions) {
            $configs = array_filter($configs, function($c) use ($allowedSensors) {
                return in_array($c['log_key'], $allowedSensors);
            });
            $configs = array_values($configs); // Re-index array
            $logKeys = array_filter($logKeys, function($k) use ($allowedSensors) {
                return in_array($k, $allowedSensors);
            });
            $logKeys = array_values($logKeys); // Re-index array
        }

        echo json_encode([
            'success' => true,
            'configs' => $configs,
            'available_keys' => $logKeys
        ]);
    }
    exit;
}

// POST - Create/Update config
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // Validate required fields
    if (empty($data['device_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'device_id is required']);
        exit;
    }

    if (empty($data['log_key'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'log_key is required']);
        exit;
    }

    // Verify device exists
    $device = $deviceModel->getById($data['device_id']);
    if (!$device) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Device not found']);
        exit;
    }

    // Verify user has access to this device
    if (!$userModel->hasAccessToDevice($authUser['id'], $data['device_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this device']);
        exit;
    }

    // Validate data_type
    if (!empty($data['data_type']) && !in_array($data['data_type'], ['4-20', 'real'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'data_type must be "4-20" or "real"']);
        exit;
    }

    // Save config
    $result = $sensorConfigModel->upsert($data);

    if ($result) {
        $config = $sensorConfigModel->getConfig($data['device_id'], $data['log_key']);
        echo json_encode([
            'success' => true,
            'message' => 'Sensor config saved',
            'config' => $config
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save config']);
    }
    exit;
}

// DELETE - Remove config
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $deviceId = $_GET['device_id'] ?? null;
    $logKey = $_GET['key'] ?? null;

    if (!$deviceId || !$logKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'device_id and key are required']);
        exit;
    }

    // Verify user has access to this device
    if (!$userModel->hasAccessToDevice($authUser['id'], $deviceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this device']);
        exit;
    }

    $result = $sensorConfigModel->delete($deviceId, $logKey);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Config deleted']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete config']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
