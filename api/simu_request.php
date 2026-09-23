<?php
/**
 * API: Simu asks for a licence for the PC it runs on
 *
 * Endpoint: POST /api/simu_request.php
 * Authorization: Bearer <SIMU_APP_KEY from .env>
 * Content-Type: application/json
 *
 * Request:
 * {
 *   "machine_id":   "C5902C7CE3377C21FF770464B37CF963",   required, 32 hex, as Simu shows it
 *   "customer":     "Acme Water Ltd",                     required, 1-100 characters
 *   "contact":      "name@example.com",                   optional, so we can reach them
 *   "hostname":     "SCADA-PC",                           optional, for the admin page
 *   "simu_version": "1.0.3"                               optional
 * }
 *
 * Response 201 (new request) or 200 (a PC we know):
 * {
 *   "success":  true,
 *   "status":   "pending",            pending | active
 *   "customer": "Acme Water Ltd",
 *   "license":  { ... },              only when active: the signed document,
 *                                     exactly what Simu writes as license.json
 *   "reissued": true                  only when active: this PC asked before
 * }
 *
 * WHAT THIS IS
 * The first half of the online path. A new PC becomes a PENDING row on
 * admin/simu_licenses.php; nothing is signed until an admin approves it
 * there, and Simu finds that out by asking simu_poll.php. A PC that was
 * approved already gets its document straight back - a reinstall is free.
 * A PC that was refused goes back in the queue with whatever name it typed
 * this time.
 *
 * The other path does not come through here at all: the customer emails the
 * Machine ID, the admin issues on the same page and downloads the file.
 *
 * Errors carry a stable "code" so Simu can act on it rather than parse the
 * message:
 *   400 BAD_REQUEST     malformed machine_id / customer / JSON
 *   401 UNAUTHORIZED    no or wrong app key
 *   403 DISABLED        this PC's licence was disabled by an admin
 *   503 NOT_CONFIGURED  SIMU_APP_KEY is not set in .env
 *   500 SERVER          the database said no
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../models/SimuLicense.php';
require_once __DIR__ . '/../services/SimuSigner.php';
require_once __DIR__ . '/simu_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed', 'code' => 'METHOD'], 405);
}

function simuError($message, $code, $status) {
    jsonResponse(['success' => false, 'error' => $message, 'code' => $code], $status);
}

simuAuthenticate();      // exits with 401/503 otherwise

$data     = getJsonInput();
$mid      = strtoupper(trim((string)($data['machine_id'] ?? '')));
$customer = trim((string)($data['customer'] ?? ''));
$contact  = substr(trim((string)($data['contact'] ?? '')), 0, 100) ?: null;
$hostname = substr(trim((string)($data['hostname'] ?? '')), 0, 100) ?: null;
$version  = substr(trim((string)($data['simu_version'] ?? '')), 0, 30) ?: null;
$ip       = $_SERVER['REMOTE_ADDR'] ?? null;

if (!SimuSigner::isValidMachineId($mid)) {
    simuError('machine_id must be 32 hexadecimal characters, as Simu shows it', 'BAD_REQUEST', 400);
}
if (!SimuSigner::isValidCustomer($customer)) {
    simuError('customer must be 1-100 printable characters', 'BAD_REQUEST', 400);
}

try {
    $model = new SimuLicense();
    $res   = $model->request($mid, $customer, $contact, $hostname, $version, $ip);
} catch (SimuLicenseRefused $e) {
    simuError($e->getMessage(), $e->apiCode, $e->http);
} catch (Exception $e) {
    error_log('simu_request.php: ' . $e->getMessage());
    simuError('Could not record the request', 'SERVER', 500);
}

$row = $res['row'];
$out = [
    'success'  => true,
    'status'   => $row['status'],
    'customer' => $row['customer'],
];
if ($row['status'] === SimuLicense::ACTIVE && $row['license_doc']) {
    $out['license']  = json_decode($row['license_doc'], true);
    $out['reissued'] = true;
}
jsonResponse($out, $res['created'] ? 201 : 200);
