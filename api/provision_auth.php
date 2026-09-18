<?php
/**
 * Provisioning authentication - the Bearer key a vendor's Orca sends.
 *
 * Separate from auth_middleware.php on purpose. That file validates USER
 * tokens from user_login.php and exits when it finds none; this one looks a
 * COMPANY up by its provision_key. A vendor's Orca holds exactly one secret,
 * tied to one company row - leak it and one company's key is rotated, not a
 * user's password.
 *
 * Usage:
 *   require_once __DIR__ . '/provision_auth.php';
 *   $company = provisionAuthenticate();   // exits 401/403 otherwise
 */

require_once __DIR__ . '/../models/Company.php';

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
 * The company behind the key, or a 401/403 and exit.
 */
function provisionAuthenticate() {
    $key = provisionBearer();
    if ($key === null) {
        jsonResponse(['success' => false,
                      'error' => 'No provision key. Send it as: Authorization: Bearer <key>',
                      'code' => 'UNAUTHORIZED'], 401);
    }

    $companyModel = new Company();
    $company = $companyModel->getByProvisionKey($key);
    if (!$company) {
        jsonResponse(['success' => false, 'error' => 'Provision key not recognised',
                      'code' => 'UNAUTHORIZED'], 401);
    }
    if (!$company['is_active']) {
        jsonResponse(['success' => false, 'error' => 'Company is inactive',
                      'code' => 'FORBIDDEN'], 403);
    }
    return $company;
}
