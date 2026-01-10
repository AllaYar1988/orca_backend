<?php
/**
 * Debug script to diagnose token storage/validation issues
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$userModel = new User();

// Get the token from query param or POST
$testToken = $_GET['token'] ?? $_POST['token'] ?? null;

$results = [
    'test_time' => date('Y-m-d H:i:s'),
    'server_now' => null,
    'php_now' => date('Y-m-d H:i:s'),
    'token_provided' => $testToken ? substr($testToken, 0, 20) . '...' : null,
];

// Test 1: Check server time
try {
    $stmt = $db->query("SELECT NOW() as server_time");
    $row = $stmt->fetch();
    $results['server_now'] = $row['server_time'];
} catch (Exception $e) {
    $results['server_time_error'] = $e->getMessage();
}

// Test 2: List all tokens in database (limited info)
try {
    $stmt = $db->query("SELECT id, user_id, LEFT(token, 20) as token_prefix, expires_at, created_at, last_used_at FROM user_tokens ORDER BY created_at DESC LIMIT 10");
    $results['recent_tokens'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $results['token_list_error'] = $e->getMessage();
}

// Test 3: If token provided, try to find it
if ($testToken) {
    try {
        // Direct database lookup
        $stmt = $db->prepare("SELECT * FROM user_tokens WHERE token = :token");
        $stmt->execute([':token' => $testToken]);
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tokenRow) {
            $results['token_found_in_db'] = true;
            $results['token_details'] = [
                'id' => $tokenRow['id'],
                'user_id' => $tokenRow['user_id'],
                'expires_at' => $tokenRow['expires_at'],
                'created_at' => $tokenRow['created_at'],
                'is_expired' => strtotime($tokenRow['expires_at']) < time()
            ];

            // Check with NOW()
            $stmt = $db->prepare("SELECT * FROM user_tokens WHERE token = :token AND expires_at > NOW()");
            $stmt->execute([':token' => $testToken]);
            $validRow = $stmt->fetch();
            $results['token_valid_with_now'] = $validRow ? true : false;
        } else {
            $results['token_found_in_db'] = false;
        }

        // Test validateToken method
        $validationResult = $userModel->validateToken($testToken);
        $results['validateToken_result'] = $validationResult ? 'valid' : 'invalid';
        if ($validationResult) {
            $results['validateToken_user_id'] = $validationResult['user_id'];
        }

    } catch (Exception $e) {
        $results['token_lookup_error'] = $e->getMessage();
    }
}

// Test 4: Test INTERVAL SQL
try {
    $stmt = $db->query("SELECT NOW() as now_time, NOW() + INTERVAL 24 HOUR as plus_24");
    $row = $stmt->fetch();
    $results['sql_now'] = $row['now_time'];
    $results['sql_now_plus_24'] = $row['plus_24'];
} catch (Exception $e) {
    $results['sql_interval_error'] = $e->getMessage();
}

// Test 5: Create a new test token for user 1 and verify it works
try {
    $testUser = $userModel->getByUsername('myco');
    if ($testUser) {
        $results['test_user_found'] = true;
        $results['test_user_id'] = $testUser['id'];

        // Create a fresh token
        $newToken = $userModel->createToken($testUser['id'], 24, '127.0.0.1', 'Debug Script');
        $results['new_token_created'] = substr($newToken, 0, 20) . '...';

        // Immediately validate it
        $validation = $userModel->validateToken($newToken);
        $results['new_token_validates'] = $validation ? true : false;

        // Check it's in the database with full details
        $stmt = $db->prepare("SELECT * FROM user_tokens WHERE token = :token");
        $stmt->execute([':token' => $newToken]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        $results['new_token_in_db'] = $found ? true : false;
        if ($found) {
            $results['new_token_details'] = [
                'created_at' => $found['created_at'],
                'expires_at' => $found['expires_at']
            ];
        }
    } else {
        $results['test_user_found'] = false;
    }
} catch (Exception $e) {
    $results['token_creation_test_error'] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
