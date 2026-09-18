<?php
/**
 * Tool: Show the server's grant public key, the way the firmware holds it
 *
 * Prints the P-256 public half from GRANT_PRIVATE_KEY_PATH as the same C
 * array license_key.h carries. When grants start being refused, "is the
 * server signing with the key the firmware checks against" is the first
 * question, and this answers it in one diff.
 *
 * Usage from command line:
 *   php grant_pubkey.php                         print the array
 *   php grant_pubkey.php /path/to/license_key.h  compare with the firmware's
 *
 * Also mints and self-verifies one throwaway grant, so a broken key file or
 * a missing openssl extension shows up here and not on a vendor's bench.
 */

require_once __DIR__ . '/../services/GrantSigner.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Command line only']);
    exit(1);
}

try {
    $signer = new GrantSigner();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

$xy = $signer->publicKeyXY();
echo "Key file: " . $signer->keyPath() . "\n\n";
echo "static const uint8_t gArLicensePubKey[LICENSE_KEY_LEN] =\n{\n";
for ($i = 0; $i < 64; $i += 8) {
    $row = [];
    for ($j = 0; $j < 8; $j++) {
        $row[] = sprintf('0x%02XU,', ord($xy[$i + $j]));
    }
    echo "    " . implode(' ', $row) . "\n";
}
echo "};\n\n";

// A throwaway grant, verified with the same key: proves signing works here.
$uid = 'A5A5A5A5A5A5A5A5A5A5A5A5';
try {
    $b64 = $signer->signBase64($uid, 'SELFTEST', 0, time());
    $ok  = $signer->verifyBase64($b64);
    echo "Self-test: signed " . strlen($b64) . " chars, verify " . ($ok ? "OK" : "FAILED") . "\n";
    if (!$ok) {
        exit(1);
    }
} catch (Exception $e) {
    echo "Self-test: FAILED - " . $e->getMessage() . "\n";
    exit(1);
}

// Compare with the firmware's copy, if asked.
if ($argc >= 2) {
    $path = $argv[1];
    if (!is_readable($path)) {
        echo "Cannot read $path\n";
        exit(1);
    }
    $src = file_get_contents($path);
    if (!preg_match_all('/0x([0-9A-Fa-f]{2})U/', $src, $m) || count($m[1]) !== 64) {
        echo "Could not find 64 key bytes in $path\n";
        exit(1);
    }
    $fw = hex2bin(implode('', $m[1]));
    if (hash_equals($xy, $fw)) {
        echo "MATCH: $path holds this server's public key\n";
    } else {
        echo "MISMATCH: $path holds a DIFFERENT key - every grant from here will be refused\n";
        exit(1);
    }
}
