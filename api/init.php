<?php
/**
 * API Initialization
 * Include this at the top of all API files
 */

// Load database configuration (includes env loader)
require_once __DIR__ . '/../config/database.php';

// Set response headers
header('Content-Type: application/json; charset=utf-8');

// CORS Configuration
$allowedOrigins = env('CORS_ALLOWED_ORIGINS', '*');
if ($allowedOrigins === '*') {
    header('Access-Control-Allow-Origin: *');
} else {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = explode(',', $allowedOrigins);
    if (in_array($origin, array_map('trim', $allowed))) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Error handling for production
if (APP_ENV === 'production') {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 */
function errorResponse($message, $statusCode = 400) {
    jsonResponse(['success' => false, 'message' => $message], $statusCode);
}

/**
 * Get JSON input
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}
