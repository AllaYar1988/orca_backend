<?php
/**
 * API: Provisioning status - "is this server ready, and does it know this chip"
 *
 * Endpoint: GET /api/provision_status.php
 * Authorization: Bearer <PROVISION_KEY from .env>
 *
 * Response 200:
 * {
 *   "success": true,
 *   "vendor": "Almas Electronic",
 *   "serial_prefix": "A",
 *   "provisioned": 137,                          live devices, test boards excluded
 *   "test_serials": ["A0010707", ...],           the reserved serials that are not devices
 *   "signer_ready": true
 * }
 *
 * What Orca's "Test connection" button calls: proves the URL, the key and the
 * server's signing key are all in order without issuing anything. Optional
 * ?uid=<24 hex> also says whether that chip is already known.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../models/Provision.php';
require_once __DIR__ . '/../services/GrantSigner.php';
require_once __DIR__ . '/../services/TestSerials.php';
require_once __DIR__ . '/provision_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed', 'code' => 'METHOD'], 405);
}

$vendor = provisionAuthenticate();     // exits with 401/503 otherwise
$prov = new Provision();

$signerReady = true;
$signerError = null;
try {
    new GrantSigner();
} catch (Exception $e) {
    $signerReady = false;
    $signerError = APP_DEBUG ? $e->getMessage() : 'signing key not available';
}

$out = [
    'success'       => true,
    'vendor'        => $vendor['name'],
    'serial_prefix' => $vendor['serial_prefix'],
    'provisioned'   => $prov->countLive(),
    'test_serials'  => TestSerials::all(),     // the reserved ten, for the tool to show
    'signer_ready'  => $signerReady,
];
if ($signerError !== null) {
    $out['signer_error'] = $signerError;
}

$uid = strtoupper(trim((string)($_GET['uid'] ?? '')));
if ($uid !== '') {
    if (!GrantSigner::isValidUid($uid)) {
        jsonResponse(['success' => false, 'error' => 'uid must be 24 hex characters',
                      'code' => 'BAD_REQUEST'], 400);
    }
    $row = $prov->findByUid($uid);
    $out['chip'] = $row ? [
        'known'         => true,
        'serial_number' => $row['serial_number'],
        'retired'       => $row['retired_at'] !== null,
        'test'          => (bool)$row['is_test'],
    ] : ['known' => false];
}

jsonResponse($out);
