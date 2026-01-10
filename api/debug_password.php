<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$testPassword = '123456';

// Generate a fresh hash on this server
$freshHash = password_hash($testPassword, PASSWORD_DEFAULT);

// Update the user with the fresh hash
$stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE username = 'myco'");
$stmt->execute([':hash' => $freshHash]);

// Now verify it works
$stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = 'myco'");
$stmt->execute();
$user = $stmt->fetch();

echo json_encode([
    'user_found' => $user ? true : false,
    'username' => $user ? $user['username'] : null,
    'new_hash_generated' => $freshHash,
    'password_verify_result' => $user ? password_verify($testPassword, $user['password_hash']) : false,
    'php_version' => PHP_VERSION
], JSON_PRETTY_PRINT);
