<?php
/**
 * Tool: Set Device Secret
 *
 * Usage from command line:
 *   php set_device_secret.php UA022 mypassword123
 *
 * Or via browser (for testing):
 *   set_device_secret.php?serial=UA022&secret=mypassword123
 */

require_once __DIR__ . '/../config/database.php';

// Get parameters from CLI or query string
if (php_sapi_name() === 'cli') {
    if ($argc < 3) {
        echo "Usage: php set_device_secret.php <serial_number> <secret>\n";
        echo "Example: php set_device_secret.php UA022 mypassword123\n";
        exit(1);
    }
    $serialNumber = $argv[1];
    $secret = $argv[2];
} else {
    header('Content-Type: application/json');
    $serialNumber = $_GET['serial'] ?? null;
    $secret = $_GET['secret'] ?? null;

    if (!$serialNumber || !$secret) {
        echo json_encode(['error' => 'Missing serial or secret parameter']);
        exit(1);
    }
}

try {
    $db = Database::getInstance()->getConnection();

    // Hash the secret
    $hashedSecret = password_hash($secret, PASSWORD_DEFAULT);

    // Update device
    $sql = "UPDATE devices SET device_secret = :secret WHERE serial_number = :serial";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':secret' => $hashedSecret,
        ':serial' => $serialNumber
    ]);

    if ($stmt->rowCount() > 0) {
        $message = "Device secret set successfully for: $serialNumber";
        if (php_sapi_name() === 'cli') {
            echo "$message\n";
            echo "Hash: $hashedSecret\n";
        } else {
            echo json_encode(['success' => true, 'message' => $message]);
        }
    } else {
        $message = "Device not found: $serialNumber";
        if (php_sapi_name() === 'cli') {
            echo "Error: $message\n";
            exit(1);
        } else {
            echo json_encode(['success' => false, 'error' => $message]);
        }
    }
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
