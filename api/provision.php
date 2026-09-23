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
 *   "test":         false,                        optional - a test board: give it a test serial
 *   "replace":      false,                        optional - the serial is on another chip: move it here
 *   "tool":         "orca",                       optional, for the audit trail
 *   "tool_version": "1.4.0",
 *   "fw_version":   "V3.25.10",                   what the board runs, as Orca read it
 *   "boot_version": "V1.1.37",                    its bootloader, as the firmware reports it
 *   "hw_version":   "1.1"                         its board revision, as stored on it
 * }
 *
 * Response 201 (or 200 for a chip already known):
 * {
 *   "success":       true,
 *   "serial_number": "A0020707",
 *   "grant":         "<168 base64 characters>",   -> device: license <grant>
 *   "reissued":      false,                       true = this chip was known
 *   "changed_from":  "A0000001",                  present when the serial was rewritten
 *   "test":          false,                       true = a test serial, not a device
 *   "substituted_for": "A0090907",                present when "test" was asked for but a
 *                                                 real serial was typed: what was typed
 *   "replaced_chip": "2035...0043",               present when the serial was moved here from
 *                                                 that chip, which is now retired
 *   "vendor":        "Almas Electronic",
 *   "provisioned":   137                          live devices in total, test boards excluded
 * }
 *
 * A 409 CONFLICT carries what the bench needs to decide whether to move the
 * serial: "on_chip" (the chip holding it), "on_chip_since" (when it got it)
 * and "last_seen_at" / "last_seen_ago_s" (when a device with that serial last
 * reported to this server, null if never). A dead board does not phone home.
 *
 * MOVING A SERIAL
 * Ask again with "replace": true and the serial moves: this chip gets a
 * grant for it - a fresh row, or its existing row renamed - and the chip
 * that held it is retired. It is the one action here that retires a chip,
 * so it is never the default, and the tool asks the operator first, naming
 * both chips. A retire by mistake is undone on the admin page (Restore).
 *
 * TEST BOARDS
 * Ten serials are reserved for development and test (TestSerials). They are
 * not devices: a row carrying one is is_test, left out of "provisioned", and
 * free to share its serial with any number of other chips - so the CONFLICT
 * rule below does not apply to them. Whether a row is a test row follows
 * from the serial alone, never from the "test" flag in the request. The flag
 * only means "pick one for me": when it is set and the serial typed is not
 * one of the ten (or is empty), the reply carries the reserved serial that
 * has gone longest unused, and "substituted_for" says what was typed. A
 * known chip already holding a test serial keeps it.
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
 * is a different physical board, and only an admin can bring it back.
 *
 * Errors carry a stable "code" so the tool can act on it rather than parse
 * the message:
 *   400 BAD_REQUEST     malformed uid / serial / JSON
 *   401 UNAUTHORIZED    no or wrong provision key
 *   503 NOT_CONFIGURED  PROVISION_KEY is not set in .env
 *   409 CONFLICT        the serial asked for is live on a different chip
 *                       (ask again with "replace": true to move it here)
 *   409 TEST_SERIAL     "replace" named a test serial - nothing to move
 *   410 RETIRED         chip replaced; grant retired
 *   500 SIGNER          key not loaded - see GRANT_PRIVATE_KEY_PATH
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../models/Provision.php';
require_once __DIR__ . '/../services/GrantSigner.php';
require_once __DIR__ . '/../services/TestSerials.php';
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
$testBoard   = !empty($data['test']);        // "pick a test serial for me"
$replace     = !empty($data['replace']);     // "the serial is on another chip: move it here"
$tool        = substr(trim((string)($data['tool'] ?? '')), 0, 50) ?: null;
$toolVersion = substr(trim((string)($data['tool_version'] ?? '')), 0, 50) ?: null;
$fwVersion   = substr(trim((string)($data['fw_version'] ?? '')), 0, 50) ?: null;
$bootVersion = substr(trim((string)($data['boot_version'] ?? '')), 0, 50) ?: null;
$hwVersion   = substr(trim((string)($data['hw_version'] ?? '')), 0, 20) ?: null;
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

// What the reply says about the serial it carries. Filled in once the serial
// is settled; the same two keys on every success path.
$substitutedFor = null;
function testFields($serial, $substitutedFor) {
    $f = ['test' => TestSerials::isTest($serial)];
    if ($substitutedFor !== null && $substitutedFor !== '') {
        $f['substituted_for'] = $substitutedFor;
    }
    return $f;
}

// The serial is live on another chip. Say which, and when a device with
// that serial was last heard from - the bench decides from that whether
// the other board is dead, and asks again with "replace" if it is.
function conflictReply(Provision $prov, $serial, array $holder) {
    $seen = $prov->lastSeen($serial);
    jsonResponse([
        'success'         => false,
        'code'            => 'CONFLICT',
        'error'           => 'Serial ' . $serial . ' is already on chip ' . $holder['uid'],
        'on_chip'         => $holder['uid'],
        'on_chip_since'   => (int)$holder['issued_utc'],
        'last_seen_at'    => $seen,
        'last_seen_ago_s' => $seen ? max(0, time() - strtotime($seen)) : null,
    ], 409);
}

try {
    $db->beginTransaction();

    $existing = $prov->findByUid($uid);

    // ---- moving a serial here from another chip: only when asked ----------
    if ($replace && $wantSerial !== '' && !$testBoard) {
        $holder = $prov->findLiveBySerial($wantSerial);
        if ($holder && $holder['uid'] !== $uid) {
            try {
                $moved = $prov->replaceChip($wantSerial, $uid, $signer, $tool, $ip);
            } catch (ProvisionRefused $e) {
                $db->rollBack();
                provisionError($e->getMessage(), $e->apiCode, $e->http);
            }
            $prov->recordVersions($moved['id'], $toolVersion, $fwVersion, $bootVersion, $hwVersion);
            $db->commit();
            $out = [
                'success'       => true,
                'serial_number' => $wantSerial,
                'grant'         => $moved['grant'],
                'reissued'      => $existing ? true : false,
                'replaced_chip' => $moved['old_uid'],
                'issued_utc'    => $moved['issued'],
                'vendor'        => $vendor['name'],
                'provisioned'   => $prov->countLive(),
            ];
            if ($moved['previous_serial'] !== null) {
                $out['changed_from'] = $moved['previous_serial'];
            }
            jsonResponse($out + testFields($wantSerial, null), $existing ? 200 : 201);
        }
        // Nobody else holds it (or this chip does): the ordinary path applies.
    }

    // ---- a test board: the serial is ours to choose ------------------------
    // The flag never marks a row; the serial does (TestSerials). So when the
    // flag is set and what was typed is not a reserved serial, swap it for
    // one: the chip's own if it already holds a test serial, otherwise the
    // reserved one that has waited longest.
    if ($testBoard && !TestSerials::isTest($wantSerial)) {
        $substitutedFor = $wantSerial;
        if ($existing && $existing['retired_at'] === null
                && TestSerials::isTest($existing['serial_number'])) {
            $wantSerial = $existing['serial_number'];
        } else {
            $wantSerial = $prov->leastRecentlyUsedTestSerial();
            if ($wantSerial === null) {
                $db->rollBack();
                provisionError('No test serials are configured on this server (PROVISION_TEST_SERIALS)',
                               'NOT_CONFIGURED', 503);
            }
        }
    }
    // A reserved serial may be live on any number of chips at once.
    $shared = TestSerials::isTest($wantSerial);

    // ---- a chip we have seen: replay ---------------------------------------
    if ($existing) {
        if ($existing['retired_at'] !== null) {
            $db->rollBack();
            provisionError("This chip's grant was retired (MCU replaced). Issue one for the new chip from the Provisioning page.",
                           'RETIRED', 410);
        }
        // A different serial: rewrite it, and keep a record of what it was.
        if ($wantSerial !== '' && $wantSerial !== $existing['serial_number']) {
            $onOther = $shared ? false : $prov->findLiveBySerial($wantSerial);
            if ($onOther && (int)$onOther['id'] !== (int)$existing['id']) {
                $db->rollBack();
                conflictReply($prov, $wantSerial, $onOther);
            }

            $issued = time();
            $grant  = $signer->signBase64($uid, $wantSerial, 0, $issued);
            $prov->changeSerial($existing['id'], $wantSerial, $grant, $issued, $tool, $ip);
            $prov->recordVersions($existing['id'], $toolVersion, $fwVersion, $bootVersion, $hwVersion);
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
            ] + testFields($wantSerial, $substitutedFor));
        }
        if (!$signer->verifyBase64($existing['grant_b64'])) {
            // The stored grant no longer verifies against this server's key -
            // the key was rotated. Say so loudly rather than hand out a grant
            // the firmware is bound to refuse.
            $db->rollBack();
            error_log('provision.php: stored grant for ' . $uid . ' does not verify - key rotated?');
            provisionError('Stored grant does not verify against the current key', 'SIGNER', 500);
        }

        $prov->markReissued($existing['id'], $tool, $toolVersion, $fwVersion, $bootVersion, $hwVersion);
        $db->commit();

        jsonResponse([
            'success'       => true,
            'serial_number' => $existing['serial_number'],
            'grant'         => $existing['grant_b64'],
            'reissued'      => true,
            'issued_utc'    => (int)$existing['issued_utc'],
            'vendor'        => $vendor['name'],
            'provisioned'   => $prov->countLive(),
        ] + testFields($existing['serial_number'], $substitutedFor));
    }

    // ---- a new chip --------------------------------------------------------
    if ($wantSerial !== '') {
        $onOther = $shared ? false : $prov->findLiveBySerial($wantSerial);
        if ($onOther) {
            $db->rollBack();
            conflictReply($prov, $wantSerial, $onOther);
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
        'boot_version'  => $bootVersion,
        'hw_version'    => $hwVersion,
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
    ] + testFields($serial, $substitutedFor), 201);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('provision.php: ' . $e->getMessage());
    provisionError('Provisioning failed: ' . (APP_DEBUG ? $e->getMessage() : 'internal error'),
                   'INTERNAL', 500);
}
