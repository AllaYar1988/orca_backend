<?php
/**
 * Tool: Show the server's Simu licence public key, the way Simu holds it
 *
 * Prints the P-256 public half from SIMU_LICENSE_KEY_PATH as the same PEM
 * app/licensing.py carries in PUBLIC_KEY_PEM. When Simu starts refusing the
 * licences this server issues, "is the server signing with the key Simu
 * checks against" is the first question, and this answers it in one diff.
 *
 * Usage from command line:
 *   php simu_license_pubkey.php                              print the PEM
 *   php simu_license_pubkey.php /path/to/app/licensing.py   compare with Simu's copy
 *
 * Also mints and self-verifies one throwaway licence, so a broken key file
 * or a missing openssl extension shows up here and not on a customer's PC.
 */

require_once __DIR__ . '/../services/SimuSigner.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Command line only']);
    exit(1);
}

try {
    $signer = new SimuSigner();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

$pem = $signer->publicKeyPem();
echo "Key file: " . $signer->keyPath() . "\n\n";
echo $pem . "\n";

// A throwaway licence, verified with the same key: proves signing works here.
try {
    $doc = $signer->issue(0, 'A5A5A5A5A5A5A5A5A5A5A5A5A5A5A5A5', 'SELFTEST', time());
    $ok  = $signer->verifyDocument($doc);
    echo "Self-test: signed " . strlen($doc) . " chars, verify " . ($ok ? "OK" : "FAILED") . "\n";
    if (!$ok) {
        exit(1);
    }
} catch (Exception $e) {
    echo "Self-test: FAILED - " . $e->getMessage() . "\n";
    exit(1);
}

// Compare with Simu's copy, if asked.
if ($argc >= 2) {
    $path = $argv[1];
    if (!is_readable($path)) {
        echo "Cannot read $path\n";
        exit(1);
    }
    $src = file_get_contents($path);
    if (!preg_match('/-----BEGIN PUBLIC KEY-----(.*?)-----END PUBLIC KEY-----/s', $src, $m)) {
        echo "Could not find a PUBLIC KEY block in $path\n";
        exit(1);
    }
    $theirs = preg_replace('/\s+/', '', $m[1]);
    preg_match('/-----BEGIN PUBLIC KEY-----(.*?)-----END PUBLIC KEY-----/s', $pem, $o);
    $ours = preg_replace('/\s+/', '', $o[1]);
    if (hash_equals($ours, $theirs)) {
        echo "MATCH: $path holds this server's public key\n";
    } else {
        echo "MISMATCH: $path holds a DIFFERENT key - every licence from here will be refused\n";
        exit(1);
    }
}
