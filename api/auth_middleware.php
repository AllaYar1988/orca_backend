<?php
/**
 * API Authentication Middleware
 *
 * Include this file at the top of protected API endpoints.
 * It validates the Authorization header token and provides $authUser.
 *
 * Usage:
 *   require_once __DIR__ . '/auth_middleware.php';
 *   // $authUser is now available with user data
 *   // $authToken contains the validated token
 */

require_once __DIR__ . '/../models/User.php';

/**
 * Get Bearer token from Authorization header or query parameter
 * @return string|null
 */
function getBearerToken() {
    $headers = null;

    // Try to get Authorization header from various sources
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if ($requestHeaders) {
            $requestHeaders = array_combine(
                array_map('ucwords', array_keys($requestHeaders)),
                array_values($requestHeaders)
            );
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
    } elseif (function_exists('getallheaders')) {
        $requestHeaders = getallheaders();
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    // Extract token from "Bearer <token>"
    if (!empty($headers) && preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
        return trim($matches[1]);
    }

    // Fallback: check query parameter (for environments where headers are stripped)
    if (isset($_GET['token'])) {
        return trim($_GET['token']);
    }

    return null;
}

/**
 * Send 401 Unauthorized response and exit
 * @param string $message
 */
function unauthorized($message = 'Unauthorized') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

/**
 * Send 403 Forbidden response and exit
 * @param string $message
 */
function forbidden($message = 'Access denied') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => 'FORBIDDEN'
    ]);
    exit;
}

// Extract token from header
$authToken = getBearerToken();

if (!$authToken) {
    unauthorized('No authentication token provided');
}

// Validate token
$userModel = new User();
$tokenData = $userModel->validateToken($authToken);

if (!$tokenData) {
    unauthorized('Invalid or expired token');
}

// Make authenticated user data available
$authUser = [
    'id' => $tokenData['user_id'],
    'username' => $tokenData['username'],
    'name' => $tokenData['name'],
    'email' => $tokenData['email']
];

// Clean up expired tokens occasionally (1% chance per request)
if (rand(1, 100) === 1) {
    $userModel->cleanupExpiredTokens();
}
