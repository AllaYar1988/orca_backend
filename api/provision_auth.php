<?php
/**
 * Provisioning authentication - the key the bench tool sends.
 *
 * One vendor: us. So there is no vendor table and no per-vendor row - the key
 * is PROVISION_KEY in .env, and that is the whole of it.
 *
 * Be clear about what this key is for. Orca ships with it built in, so it is
 * not a secret in any strong sense; anyone with the tool has it. What it does
 * is keep api/provision.php from answering the open internet, so a scanner
 * that finds the URL gets 401 instead of a signed grant. The real limit on
 * who can make a device is the private signing key, which is on this server
 * and nowhere else.
 *
 * When there is a second vendor this becomes a lookup in a `vendors` table
 * and the key stops being shipped in the tool. The call sites do not change:
 * they ask provisionAuthenticate() who is calling and get an array back.
 *
 * Usage:
 *   require_once __DIR__ . '/provision_auth.php';
 *   $vendor = provisionAuthenticate();   // exits 401/503 otherwise
 */

require_once __DIR__ . '/../config/env.php';

/** @brief Serial numbers are prefix + digits, eight characters in all. */
function provisionSerialPrefix() {
    $p = strtoupper(trim((string)env('PROVISION_SERIAL_PREFIX', 'A')));
    return preg_match('/^[A-Z0-9]{1,7}$/', $p) ? $p : 'A';
}

/**
 * The Bearer token, from wherever this host lets it through.
 */
function provisionBearer() {
    $h = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = trim($v);
                break;
            }
        }
    }
    if ($h !== null && preg_match('/Bearer\s+(.+)$/i', $h, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Who is calling, or a 401/503 and exit.
 *
 * @return array{name:string, serial_prefix:string}
 */
function provisionAuthenticate() {
    $expected = trim((string)env('PROVISION_KEY', ''));

    if ($expected === '') {
        // Not configured is not the same as wrong, and saying so saves an
        // afternoon: the tool reports it and the admin page shows it.
        jsonResponse(['success' => false,
                      'error' => 'Provisioning is not configured on this server - PROVISION_KEY is not set in .env',
                      'code' => 'NOT_CONFIGURED'], 503);
    }

    $given = provisionBearer();
    if ($given === null) {
        jsonResponse(['success' => false,
                      'error' => 'No provision key. Send it as: Authorization: Bearer <key>',
                      'code' => 'UNAUTHORIZED'], 401);
    }

    // hash_equals, not ==: the comparison should not leak the key one
    // character at a time to something that can time it.
    if (!hash_equals($expected, $given)) {
        jsonResponse(['success' => false, 'error' => 'Provision key not recognised',
                      'code' => 'UNAUTHORIZED'], 401);
    }

    return ['name' => (string)env('PROVISION_VENDOR_NAME', 'Almas Electronic'),
            'serial_prefix' => provisionSerialPrefix()];
}
