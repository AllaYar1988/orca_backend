<?php
/**
 * API: Has this PC's licence request been decided yet?
 *
 * Endpoint: GET /api/simu_poll.php?machine_id=<32 hex>
 * Authorization: Bearer <SIMU_APP_KEY from .env>
 *
 * Response 200:
 * {
 *   "success":  true,
 *   "status":   "active",             pending | active | rejected
 *   "customer": "Acme Water Ltd",
 *   "license":  { ... },              only when active: the signed document
 *   "note":     "..."                 only when rejected: what the admin wrote
 * }
 *
 * The second half of the online path. Simu's activation page calls this
 * every 30 seconds while a request is pending, and once more at every start
 * until it has a file. The document it hands out is bound to the Machine ID
 * that asked for it and is worthless on any other PC, which is why a bare
 * GET with the app key is enough here.
 *
 * Errors:
 *   400 BAD_REQUEST     malformed machine_id
 *   401 UNAUTHORIZED    no or wrong app key
 *   403 DISABLED        this PC's licence was disabled by an admin
 *   404 UNKNOWN         this PC never asked - Simu shows the request form
 *   503 NOT_CONFIGURED  SIMU_APP_KEY is not set in .env
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../models/SimuLicense.php';
require_once __DIR__ . '/../services/SimuSigner.php';
require_once __DIR__ . '/simu_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed', 'code' => 'METHOD'], 405);
}

simuAuthenticate();      // exits with 401/503 otherwise

$mid = strtoupper(trim((string)($_GET['machine_id'] ?? '')));
if (!SimuSigner::isValidMachineId($mid)) {
    jsonResponse(['success' => false, 'error' => 'machine_id must be 32 hexadecimal characters',
                  'code' => 'BAD_REQUEST'], 400);
}

try {
    $model = new SimuLicense();
    $row   = $model->findByMachine($mid);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'No licence request on file for this computer',
                      'code' => 'UNKNOWN'], 404);
    }
    $model->markPolled($row['id']);

    if ($row['status'] === SimuLicense::DISABLED) {
        jsonResponse(['success' => false, 'code' => 'DISABLED',
                      'error' => 'The licence for this computer was disabled by Almas Electronic',
                      'customer' => $row['customer']], 403);
    }

    $out = ['success' => true, 'status' => $row['status'], 'customer' => $row['customer']];
    if ($row['status'] === SimuLicense::ACTIVE && $row['license_doc']) {
        $out['license'] = json_decode($row['license_doc'], true);
    } elseif ($row['status'] === SimuLicense::REJECTED) {
        $out['note'] = $model->lastNote($row['id'], 'rejected');
    }
    jsonResponse($out);
} catch (Exception $e) {
    error_log('simu_poll.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Could not read the licence', 'code' => 'SERVER'], 500);
}
