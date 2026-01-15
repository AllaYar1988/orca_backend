<?php
/**
 * Virtual Devices API
 * Returns virtual devices assigned to the authenticated user
 *
 * GET /api/virtual_devices.php
 * Headers: Authorization: Bearer <token>
 *
 * GET /api/virtual_devices.php?id=1
 * Returns single virtual device with full sensor data
 *
 * Response format:
 * {
 *   "success": true,
 *   "virtual_device": {
 *     "id": 1,
 *     "name": "Building A Climate",
 *     "is_online": true,
 *     "all_online": false,
 *     "live_count": 3,
 *     "total_count": 5,
 *     "seconds_ago": 45,
 *     "sensors": [
 *       {
 *         "label": "Temperature",
 *         "value": 25.5,
 *         "unit": "°C",
 *         "seconds_ago": 45,
 *         "is_online": true,
 *         "status": "live"
 *       }
 *     ]
 *   }
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

require_once __DIR__ . '/../models/VirtualDevice.php';

$userModel = new User();
$virtualDeviceModel = new VirtualDevice();

// Check if requesting a specific virtual device
$virtualDeviceId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($virtualDeviceId) {
    // Return single virtual device with full data
    if (!$userModel->hasAccessToVirtualDevice($authUser['id'], $virtualDeviceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this virtual device']);
        exit;
    }

    $data = $virtualDeviceModel->getFullData($virtualDeviceId);

    if (!$data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Virtual device not found']);
        exit;
    }

    // Format sensors for response
    $formattedSensors = [];
    foreach ($data['sensors'] as $sensor) {
        $formattedSensors[] = [
            'label' => $sensor['label'],
            'source_device_id' => (int)$sensor['source_device_id'],
            'source_device_name' => $sensor['source_device_name'],
            'source_log_key' => $sensor['source_log_key'],
            'value' => $sensor['value'],
            'unit' => $sensor['unit'],
            'seconds_ago' => $sensor['seconds_ago'],
            'is_online' => $sensor['is_online'],
            'status' => $sensor['status']
        ];
    }

    echo json_encode([
        'success' => true,
        'virtual_device' => [
            'id' => $data['id'],
            'name' => $data['name'],
            'description' => $data['description'],
            'company_id' => $data['company_id'],
            'company_name' => $data['company_name'],
            'is_online' => $data['is_online'],
            'all_online' => $data['all_online'],
            'live_count' => $data['live_count'],
            'total_count' => $data['total_count'],
            'seconds_ago' => $data['seconds_ago'],
            'last_seen_at' => $data['last_seen_at'],
            'sensors' => $formattedSensors
        ]
    ]);
} else {
    // Return list of assigned virtual devices with status summary
    $virtualDevices = $userModel->getAssignedVirtualDevices($authUser['id']);

    $formattedDevices = [];
    foreach ($virtualDevices as $vd) {
        $status = $virtualDeviceModel->getStatusSummary($vd['id']);

        $formattedDevices[] = [
            'id' => (int)$vd['id'],
            'name' => $vd['name'],
            'description' => $vd['description'],
            'company_id' => (int)$vd['company_id'],
            'company_name' => $vd['company_name'],
            'sensor_count' => (int)$vd['sensor_count'],
            'is_online' => $status['is_online'],
            'all_online' => $status['all_online'],
            'live_count' => $status['live_count'],
            'total_count' => $status['total_count'],
            'seconds_ago' => $status['seconds_ago'],
            'last_seen_at' => $status['last_seen_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'user_id' => $authUser['id'],
        'virtual_device_count' => count($formattedDevices),
        'virtual_devices' => $formattedDevices
    ]);
}
