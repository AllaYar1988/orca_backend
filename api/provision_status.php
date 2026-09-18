<?php
/**
 * API: Provisioning status - "who am I, and how much quota is left"
 *
 * Endpoint: GET /api/provision_status.php
 * Authorization: Bearer <company provision_key>
 *
 * Response 200:
 * {
 *   "success": true,
 *   "company": {"id": 3, "code": "POYAN", "name": "..."},
 *   "quota":   {"used": 41, "allowed": 100},
 *   "serial_prefix": "A2",
 *   "signer_ready": true
 * }
 *
 * What Orca's "Test connection" button calls: proves the URL, the key and
 * the server's signing key are all in order without issuing anything.
 * Optional ?uid=<24 hex> also says whether that chip is already known.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Provision.php';
require_once __DIR__ . '/../services/GrantSigner.php';
require_once __DIR__ . '/provision_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed', 'code' => 'METHOD'], 405);
}

$company = provisionAuthenticate();     // exits with 401/403 otherwise
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
    'company'       => ['id' => (int)$company['id'], 'code' => $company['code'], 'name' => $company['name']],
    'quota'         => [
        'used'    => $prov->countLive($company['id']),
        'allowed' => $company['device_quota'] === null ? null : (int)$company['device_quota'],
    ],
    'serial_prefix' => $company['serial_prefix'],
    'signer_ready'  => $signerReady,
];
if ($signerError !== null) {
    $out['signer_error'] = $signerError;
}

$uid = strtoupper(trim((string)($_GET['uid'] ?? '')));
if ($uid !== '') {
    if (!GrantSigner::isValidUid($uid)) {
        jsonResponse(['success' => false, 'error' => 'uid must be 24 hex characters', 'code' => 'BAD_REQUEST'], 400);
    }
    $row = $prov->findByUid($uid);
    $out['chip'] = $row ? [
        'known'         => true,
        'mine'          => (int)$row['company_id'] === (int)$company['id'],
        'serial_number' => (int)$row['company_id'] === (int)$company['id'] ? $row['serial_number'] : null,
        'retired'       => $row['retired_at'] !== null,
    ] : ['known' => false];
}

jsonResponse($out);
