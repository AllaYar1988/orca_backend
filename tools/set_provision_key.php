<?php
/**
 * Tool: Set (or rotate) a company's provisioning key
 *
 * The key a vendor's Orca sends as "Authorization: Bearer <key>" to
 * api/provision.php. Generated here, shown ONCE, stored as-is (it is a
 * random 256-bit token, not a password - there is nothing to hash).
 *
 * Usage from command line:
 *   php set_provision_key.php POYAN                  set / rotate the key
 *   php set_provision_key.php POYAN --quota 100      ... and set a quota
 *   php set_provision_key.php POYAN --prefix A2      ... and a serial prefix
 *   php set_provision_key.php POYAN --revoke         no more provisioning
 *   php set_provision_key.php POYAN --key <64 hex>   use THIS key, not a new one
 *                                                    (the one built into Orca)
 *
 * Rotating invalidates the old key immediately; the vendor's Orca needs the
 * new one before its next provision. Quota and prefix can be changed without
 * touching the key by passing --keep.
 */

require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Command line only - this prints a secret']);
    exit(1);
}

if ($argc < 2) {
    echo "Usage: php set_provision_key.php <company_code> [--quota N] [--prefix P] [--keep] [--revoke]\n";
    exit(1);
}

$code   = $argv[1];
$quota  = null; $setQuota = false;
$prefix = null; $setPrefix = false;
$keep   = false;
$revoke = false;
$given  = null;

for ($i = 2; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--quota':  $quota = (int)$argv[++$i]; $setQuota = true; break;
        case '--prefix': $prefix = strtoupper(trim($argv[++$i])); $setPrefix = true; break;
        case '--keep':   $keep = true; break;
        case '--revoke': $revoke = true; break;
        case '--key':    $given = strtolower(trim($argv[++$i])); break;
        default:
            echo "Unknown option {$argv[$i]}\n";
            exit(1);
    }
}

if ($given !== null && !preg_match('/^[0-9a-f]{64}$/', $given)) {
    echo "Error: --key must be 64 hex characters\n";
    exit(1);
}
if ($setPrefix && !preg_match('/^[A-Z0-9]{1,7}$/', $prefix)) {
    echo "Error: prefix must be 1-7 letters/digits (serials are 8 characters in all)\n";
    exit(1);
}

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT * FROM companies WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $company = $stmt->fetch();
    if (!$company) {
        echo "Error: no company with code $code\n";
        exit(1);
    }

    $sets = [];
    $params = [':id' => $company['id']];
    $newKey = null;

    if ($revoke) {
        $sets[] = "provision_key = NULL";
    } elseif (!$keep) {
        $newKey = $given !== null ? $given : bin2hex(random_bytes(32));
        $sets[] = "provision_key = :key";
        $params[':key'] = $newKey;
    }
    if ($setQuota) {
        $sets[] = "device_quota = :quota";
        $params[':quota'] = $quota > 0 ? $quota : null;
    }
    if ($setPrefix) {
        $sets[] = "serial_prefix = :prefix";
        $params[':prefix'] = $prefix;
    }

    if (!$sets) {
        echo "Nothing to do (--keep with no --quota/--prefix)\n";
        exit(1);
    }

    $stmt = $db->prepare("UPDATE companies SET " . implode(', ', $sets) . " WHERE id = :id");
    $stmt->execute($params);

    $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
    $stmt->execute([':id' => $company['id']]);
    $c = $stmt->fetch();

    echo "Company:  {$c['name']} ({$c['code']})\n";
    echo "Quota:    " . ($c['device_quota'] === null ? 'unlimited' : $c['device_quota']) . "\n";
    echo "Prefix:   " . ($c['serial_prefix'] ?? '(none - vendor supplies serials)') . "\n";
    echo "Next:     " . $c['serial_next'] . "\n";
    if ($revoke) {
        echo "Key:      REVOKED - this company can no longer provision\n";
    } elseif ($newKey !== null) {
        echo "\nProvision key (shown once - put it in the vendor's Orca):\n";
        echo "  $newKey\n";
    } else {
        echo "Key:      unchanged\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
