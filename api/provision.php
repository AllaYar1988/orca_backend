<?php
/**
 * API: Provision a device - issue the grant that lets it take a serial
 *
 * Endpoint: POST /api/provision.php
 * Authorization: Bearer <company provision_key>
 * Content-Type: application/json
 *
 * Request:
 * {
 *   "uid":          "203530473932501800370043",   required, 24 hex, as printed
 *   "serial":       "A2000137",                   optional - server allocates otherwise
 *   "tool":         "orca",                       optional, for the audit trail
 *   "tool_version": "1.4.0",
 *   "fw_version":   "V3.25.10"
 * }
 *
 * Response 200:
 * {
 *   "success":       true,
 *   "serial_number": "A2000137",
 *   "grant":         "<168 base64 characters>",   -> device: license <grant>
 *   "reissued":      false,                       true = this chip was known
 *   "company":       {"id": 3, "code": "POYAN", "name": "..."},
 *   "quota":         {"used": 41, "allowed": 100}  allowed null = unlimited
 * }
 *
 * WHAT THIS IS
 * The one place a device is counted. The firmware will not write a serial
 * without a grant, a grant can only come from here, and every one issued is
 * a row in `provisions`. It is called by Orca on the vendor's bench, not by
 * the device - most devices never reach this server, and they do not need
 * to.
 *
 * IDEMPOTENT ON UID
 * A chip this server has seen before gets the SAME serial and the SAME grant
 * bytes back, and the company's quota is untouched. Re-flashing, a wiped
 * config, an RMA: all replays of a decision already made. What is NOT free:
 * a different company asking about a known chip (409), or a chip whose grant
 * was retired because the MCU was replaced (410 - the admin issues for the
 * new chip).
 *
 * Errors carry a stable "code" so the tool can act on it rather than parse
 * the message:
 *   400 BAD_REQUEST     malformed uid / serial / JSON
 *   401 UNAUTHORIZED    no or unknown provision key
 *   403 FORBIDDEN       company inactive, or serial outside its prefix
 *   402 QUOTA_EXCEEDED  company at its limit
 *   409 CONFLICT        chip belongs to another company / serial in use
 *   410 RETIRED         chip replaced; grant retired
 *   500 SIGNER          key not loaded - see GRANT_PRIVATE_KEY_PATH
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../models/Company.php';
require_once __DIR__ . '/../models/Provision.php';
require_once __DIR__ . '/../services/GrantSigner.php';
require_once __DIR__ . '/provision_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed', 'code' => 'METHOD'], 405);
}

function provisionError($message, $code, $status) {
    jsonResponse(['success' => false, 'error' => $message, 'code' => $code], $status);
}

// ---- who is asking -------------------------------------------------------

$company = provisionAuthenticate();     // exits with 401/403 otherwise

// ---- what they are asking ------------------------------------------------

$data = getJsonInput();
$uid  = strtoupper(trim((string)($data['uid'] ?? '')));
$wantSerial = isset($data['serial']) ? trim((string)$data['serial']) : '';
$tool        = substr(trim((string)($data['tool'] ?? '')), 0, 50) ?: null;
$toolVersion = substr(trim((string)($data['tool_version'] ?? '')), 0, 50) ?: null;
$fwVersion   = substr(trim((string)($data['fw_version'] ?? '')), 0, 50) ?: null;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

if (!GrantSigner::isValidUid($uid)) {
    provisionError('uid must be 24 hexadecimal characters, as the tool prints it', 'BAD_REQUEST', 400);
}
if ($wantSerial !== '' && !GrantSigner::isValidSerial($wantSerial)) {
    provisionError('serial must be 1-31 printable ASCII characters', 'BAD_REQUEST', 400);
}

try {
    $signer = new GrantSigner();
} catch (Exception $e) {
    error_log('provision.php: ' . $e->getMessage());
    provisionError('Signing key is not available on this server', 'SIGNER', 500);
}

$prov = new Provision();
$db   = $prov->getConnection();

try {
    $db->beginTransaction();

    // Lock the vendor row: the quota check and the serial counter below are
    // atomic against any other bench provisioning for the same company.
    $company = $prov->lockCompany($company['id']);

    // ---- known chip: replay ------------------------------------------------
    $existing = $prov->findByUid($uid);
    if ($existing) {
        if ((int)$existing['company_id'] !== (int)$company['id']) {
            $db->rollBack();
            provisionError('This chip was provisioned by a different company', 'CONFLICT', 409);
        }
        if ($existing['retired_at'] !== null) {
            $db->rollBack();
            provisionError('This chip\'s grant was retired (MCU replaced). Ask the administrator.', 'RETIRED', 410);
        }
        if ($wantSerial !== '' && $wantSerial !== $existing['serial_number']) {
            $db->rollBack();
            provisionError('This chip already holds serial ' . $existing['serial_number'], 'CONFLICT', 409);
        }
        if (!$signer->verifyBase64($existing['grant_b64'])) {
            // The stored grant no longer verifies against the server's key -
            // the key was rotated. Say so loudly rather than hand out a grant
            // the firmware would refuse.
            $db->rollBack();
            error_log('provision.php: stored grant for ' . $uid . ' does not verify - key rotated?');
            provisionError('Stored grant does not verify against the current key', 'SIGNER', 500);
        }

        $prov->markReissued($existing['id'], $tool, $toolVersion, $fwVersion);
        $db->commit();

        jsonResponse([
            'success'       => true,
            'serial_number' => $existing['serial_number'],
            'grant'         => $existing['grant_b64'],
            'reissued'      => true,
            'issued_utc'    => (int)$existing['issued_utc'],
            'company'       => ['id' => (int)$company['id'], 'code' => $company['code'], 'name' => $company['name']],
            'quota'         => provisionQuota($prov, $company),
        ]);
    }

    // ---- new chip: count it ----------------------------------------------
    $used = $prov->countLive($company['id']);
    $allowed = $company['device_quota'] === null ? null : (int)$company['device_quota'];
    if ($allowed !== null && $used >= $allowed) {
        $db->rollBack();
        jsonResponse([
            'success' => false,
            'error'   => "Quota exhausted: $used of $allowed devices provisioned",
            'code'    => 'QUOTA_EXCEEDED',
            'quota'   => ['used' => $used, 'allowed' => $allowed],
        ], 402);
    }

    if ($wantSerial !== '') {
        $prefix = (string)($company['serial_prefix'] ?? '');
        if ($prefix !== '' && strpos($wantSerial, $prefix) !== 0) {
            $db->rollBack();
            provisionError("Serial must start with this company's prefix '$prefix'", 'FORBIDDEN', 403);
        }
        if ($prov->findLiveBySerial($wantSerial)) {
            $db->rollBack();
            provisionError('Serial ' . $wantSerial . ' is already on another chip', 'CONFLICT', 409);
        }
        $serial = $wantSerial;
    } else {
        try {
            $serial = $prov->allocateSerial($company);
        } catch (RuntimeException $e) {
            $db->rollBack();
            provisionError($e->getMessage(), 'BAD_REQUEST', 400);
        }
    }

    $issued = time();
    $grant  = $signer->signBase64($uid, $serial, (int)$company['id'], $issued);

    $prov->create([
        'company_id'    => $company['id'],
        'uid'           => $uid,
        'serial_number' => $serial,
        'grant_b64'     => $grant,
        'issued_utc'    => $issued,
        'tool'          => $tool,
        'tool_version'  => $toolVersion,
        'fw_version'    => $fwVersion,
        'ip_address'    => $ip,
    ]);

    $db->commit();

    jsonResponse([
        'success'       => true,
        'serial_number' => $serial,
        'grant'         => $grant,
        'reissued'      => false,
        'issued_utc'    => $issued,
        'company'       => ['id' => (int)$company['id'], 'code' => $company['code'], 'name' => $company['name']],
        'quota'         => ['used' => $used + 1, 'allowed' => $allowed],
    ], 201);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('provision.php: ' . $e->getMessage());
    provisionError('Provisioning failed: ' . (APP_DEBUG ? $e->getMessage() : 'internal error'), 'INTERNAL', 500);
}

// ---- helpers -------------------------------------------------------------

function provisionQuota(Provision $prov, array $company) {
    return [
        'used'    => $prov->countLive($company['id']),
        'allowed' => $company['device_quota'] === null ? null : (int)$company['device_quota'],
    ];
}
