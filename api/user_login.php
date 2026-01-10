<?php
/**
 * User Login API
 * Authenticates front-end users and returns a token
 *
 * POST /api/user_login.php
 * Body: { "username": "...", "password": "..." }
 */

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Custom error handler to return JSON
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../models/User.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $input = $_POST;
}

$username = isset($input['username']) ? trim($input['username']) : '';
$password = isset($input['password']) ? $input['password'] : '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password are required']);
    exit;
}

$userModel = new User();
$user = $userModel->verifyPassword($username, $password);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
    exit;
}

if (!$user['is_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account is inactive']);
    exit;
}

if (!$user['company_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your company account is inactive']);
    exit;
}

// Get client info for token tracking
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

// Generate and store token in database (24-hour expiry)
$token = $userModel->createToken($user['id'], 24, $ipAddress, $userAgent);

// Update last login
$userModel->updateLastLogin($user['id']);

// Calculate token expiry for frontend
$expiresAt = date('c', strtotime('+24 hours')); // ISO 8601 format

echo json_encode([
    'success' => true,
    'token' => $token,
    'expires_at' => $expiresAt,
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => isset($user['role']) ? $user['role'] : 'user'
    ]
]);
