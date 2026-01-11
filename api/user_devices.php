<?php
/**
 * User Devices API
 * Returns devices assigned to the authenticated user
 *
 * GET /api/user_devices.php
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

$userModel = new User();

// Get assigned devices
$devices = $userModel->getAssignedDevices($authUser['id']);

// Format response - is_online is now calculated in SQL query
$formattedDevices = [];
foreach ($devices as $device) {
    $formattedDevices[] = [
        'id' => $device['id'],
        'name' => $device['name'],
        'serial_number' => $device['serial_number'],
        'device_type' => $device['device_type'],
        'description' => $device['description'],
        'is_online' => (bool)($device['is_online'] ?? false),
        'last_seen_at' => $device['last_seen_at']
    ];
}

echo json_encode([
    'success' => true,
    'user_id' => $authUser['id'],
    'device_count' => count($formattedDevices),
    'devices' => $formattedDevices
]);
