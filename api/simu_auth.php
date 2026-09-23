<?php
/**
 * Simu licence authentication - the key Simu sends.
 *
 * Be clear about what this key is for. Simu ships with it built in, so it is
 * not a secret in any strong sense; anyone with the exe has it. What it does
 * is keep api/simu_request.php and api/simu_poll.php from answering the open
 * internet, so a scanner that finds the URL gets 401 instead of filling the
 * pending list with junk. What decides who gets a licence is an admin on
 * admin/simu_licenses.php, and what makes a licence real is the private key,
 * which is on this server and nowhere else.
 *
 * The value is SIMU_APP_KEY in .env and must equal license_app_key in
 * Simu-Backend/app/config.py.
 *
 * Usage:
 *   require_once __DIR__ . '/simu_auth.php';
 *   simuAuthenticate();   // exits 401/503 otherwise
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/provision_auth.php';    // for provisionBearer()

/**
 * The caller is Simu, or a 401/503 and exit.
 */
function simuAuthenticate() {
    $expected = trim((string)env('SIMU_APP_KEY', ''));

    if ($expected === '') {
        jsonResponse(['success' => false,
                      'error' => 'Simu licensing is not configured on this server - SIMU_APP_KEY is not set in .env',
                      'code' => 'NOT_CONFIGURED'], 503);
    }

    $given = provisionBearer();
    if ($given === null) {
        jsonResponse(['success' => false,
                      'error' => 'No app key. Send it as: Authorization: Bearer <key>',
                      'code' => 'UNAUTHORIZED'], 401);
    }

    if (!hash_equals($expected, $given)) {
        jsonResponse(['success' => false, 'error' => 'App key not recognised',
                      'code' => 'UNAUTHORIZED'], 401);
    }
    return true;
}
