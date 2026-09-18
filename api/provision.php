<?php
/**
 * API: Provision a device - issue the grant that lets it take a serial
 *
 * Endpoint: POST /api/provision.php
 * Authorization: Bearer <PROVISION_KEY from .env>
 * Content-Type: application/json
 *
 * Request:
 * {
 *   "uid":          "203530473932501800370043",   required, 24 hex, as printed
 *   "serial":       "A0020707",                   optional - server allocates otherwise
 *   "tool":         "orca",                       optional, for the audit trail
 *   "tool_version": "1.4.0",
 *   "fw_version":   "V3.25.10"
 * }
 *
 * Response 201 (or 200 for a chip already known):
 * {
 *   "success":       true,
 *   "serial_number": "A0020707",
 *   "grant":         "<168 base64 characters>",   -> device: license <grant>
 *   "reissued":      false,                       true = this chip was known
 *   "changed_from":  "A0000001",                  present when the serial was rewritten
 *   "vendor":        "Almas Electronic",
 *   "provisioned":   137                          live devices in total
 * }
 *
 * WHAT THIS IS
 * The one place a device is counted. The firmware will not write a serial
 * without a grant, a grant can only come from here, and every one issued is
 * a row in `provisions`. It is called by Orca on the bench, not by the
 * device - most devices never reach this server and do not need to.
 *
 * ONE VENDOR
 * There is no vendor table and no per-vendor row: the key is PROVISION_KEY in
 * .env and every row here is ours. `companies` are CUSTOMERS - the people who
 * own devices and log in to see their data - which is a different question,
 * and hanging provisioning off them said something untrue about the data.
 * provisions.company_id survives, nullable and unwritten, for the question it
 * can answer later: which customer a device was sold to.
 *
 * ONE ROW PER CHIP
 * Ask about a chip we know, naming no serial, and you get the SAME serial and
 * the SAME grant bytes back: re-flashing, a wiped config and an RMA are all
 * replays of a decision already made, and none is a new device.
 *
 * Name a DIFFERENT serial and the chip takes it. The chip is the device; the
 * serial is a label on it, and labels get mistyped or repurposed before a
 * board ships. Refusing would only push the correction into hand-edited SQL,
 * which is the one path that leaves no trace at all. So the rewrite is
 * allowed, the change goes to provision_history first, and the reply carries
 * "changed_from" so the bench sees what it just overwrote. The device count
 * does not move: same chip, same row.
 *
 * A chip whose grant was RETIRED (the MCU was replaced) is still 410 - that
 * is a different physical board, and the admin issues for it deliberately.
 *
 * Errors carry a stable "code" so the tool can act on it rather than parse
 * the message:
 *   400 BAD_REQUEST     malformed uid / serial / JSON
 *   401 UNAUTHORIZED    no or wrong provision key
 *   503 NOT_CONFIGURED  PROVISION_KEY is not set in .env
 *   409 CONFLICT        the serial asked for is live on a different chip
 *   410 RETIRED         chip replaced; grant retired
 *   500 SIGNER          key not loaded - see GRANT_PRIVATE_KEY_PATH
 */

require_once __DIR__ . '/init.php';
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

$vendor = provisionAuthenticate();      // exits with 401/503 otherwise

// ---- what they are asking ------------------------------------------------

$data = getJsonInput();
$uid  = strtoupper(trim((string)($data['uid'] ?? '')));
$wantSerial  = isset($data['serial']) ? strtoupper(trim((string)$data['serial'])) : '';
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

    // ---- a chip we have seen: replay ---------------------------------------
    $existing = $prov->findByUid($uid);
    if ($existing) {
        if ($existing['retired_at'] !== null) {
            $db->rollBack();
            provisionError("This chip's grant was retired (MCU replaced). Issue one for the new chip from the Provisioning page.",
                           'RETIRED', 410);
        }
        // A different serial: rewrite it, and keep a record of what it was.
        if ($wantSerial !== '' && $wantSerial !== $existing['serial_number']) {
            $onOther = $prov->findLiveBySerial($wantSerial);
            if ($onOther && (int)$onOther['id'] !== (int)$existing['id']) {
                $db->rollBack();
                provisionError('Serial ' . $wantSerial . ' is already on chip ' . $onOther['uid'],
                               'CONFLICT', 409);
            }

            $issued = time();
            $grant  = $signer->signBase64($uid, $wantSerial, 0, $issued);
            $prov->changeSerial($existing['id'], $wantSerial, $grant, $issued, $tool, $ip);
            $db->commit();

            jsonResponse([
                'success'       => true,
                'serial_number' => $wantSerial,
                'grant'         => $grant,
                'reissued'      => true,
                'changed_from'  => $existing['serial_number'],
                'issued_utc'    => $issued,
                'vendor'        => $vendor['name'],
                'provisioned'   => $prov->countLive(),
            ]);
        }
        if (!$signer->verifyBase64($existing['grant_b64'])) {
            // The stored grant no longer verifies against this server's key -
            // the key was rotated. Say so loudly rather than hand out a grant
            // the firmware is bound to refuse.
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
            'vendor'        => $vendor['name'],
            'provisioned'   => $prov->countLive(),
        ]);
    }

    // ---- a new chip --------------------------------------------------------
    if ($wantSerial !== '') {
        if ($prov->findLiveBySerial($wantSerial)) {
            $db->rollBack();
            provisionError('Serial ' . $wantSerial . ' is already on another chip', 'CONFLICT', 409);
        }
        $serial = $wantSerial;
    } else {
        try {
            $serial = $prov->allocateSerial($vendor['serial_prefix']);
        } catch (RuntimeException $e) {
            $db->rollBack();
            provisionError($e->getMessage(), 'BAD_REQUEST', 400);
        }
    }

    $issued = time();
    // company_id inside the signed payload is 0: one vendor, nothing to tell
    // apart. The firmware carries the field but does not act on it.
    $grant = $signer->signBase64($uid, $serial, 0, $issued);

    $id = $prov->create([
        'uid'           => $uid,
        'serial_number' => $serial,
        'grant_b64'     => $grant,
        'issued_utc'    => $issued,
        'tool'          => $tool,
        'tool_version'  => $toolVersion,
        'fw_version'    => $fwVersion,
        'ip_address'    => $ip,
    ]);

    $prov->logHistory([
        'provision_id'  => $id,
        'uid'           => $uid,
        'serial_number' => $serial,
        'grant_b64'     => $grant,
        'issued_utc'    => $issued,
        'event'         => 'issued',
        'tool'          => $tool,
        'ip_address'    => $ip,
    ]);

    $db->commit();

    jsonResponse([
        'success'       => true,
        'serial_number' => $serial,
        'grant'         => $grant,
        'reissued'      => false,
        'issued_utc'    => $issued,
        'vendor'        => $vendor['name'],
        'provisioned'   => $prov->countLive(),
    ], 201);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('provision.php: ' . $e->getMessage());
    provisionError('Provisioning failed: ' . (APP_DEBUG ? $e->getMessage() : 'internal error'),
                   'INTERNAL', 500);
}
