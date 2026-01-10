<?php
/**
 * API: Device Authentication - Get/Refresh API Key
 *
 * Endpoint: POST /api/device_auth.php
 * Content-Type: application/json
 *
 * Request Body:
 * {
 *   "serial_number": "DEVICE-001",
 *   "device_secret": "mysecretpassword123"
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "api_key": "abc123...",
 *   "expires_in": 86400  // seconds (optional, for info)
 * }
 *
 * Usage:
 * - Device calls this once on boot or periodically (e.g., daily)
 * - Stores api_key in memory/flash
 * - Uses api_key for HMAC signing in send_logs.php
 *
 * STM32F407 Example:
 *   // On boot or every 24 hours:
 *   POST /api/device_auth.php
 *   {"serial_number":"UA022","device_secret":"mypassword"}
 *   -> store response.api_key in RAM/Flash
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

if (empty($data['device_secret'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Device secret is required']);
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

// Verify device secret (stored as hash in database)
if (empty($device['device_secret'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Device secret not configured']);
    exit;
}

if (!password_verify($data['device_secret'], $device['device_secret'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid device secret']);
    exit;
}

// Option 1: Return existing API key
// $apiKey = $device['api_key'];

// Option 2: Regenerate API key on each auth (more secure, like token refresh)
$apiKey = $deviceModel->regenerateApiKey($device['id']);

// Update last seen
$deviceModel->updateLastSeen($device['id']);

http_response_code(200);
echo json_encode([
    'success' => true,
    'api_key' => $apiKey,
    'message' => 'Authentication successful'
]);
