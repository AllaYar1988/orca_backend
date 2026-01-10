<?php
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$newHash = password_hash('admin123', PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE admin_users SET password_hash = :hash WHERE username = 'admin'");
$stmt->execute([':hash' => $newHash]);

echo "Password updated! New hash: " . $newHash;
echo "\n\nYou can now login with:\nUsername: admin\nPassword: admin123";
