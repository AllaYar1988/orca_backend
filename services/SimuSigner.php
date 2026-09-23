<?php
/**
 * SimuSigner
 *
 * Mints Simu licences: the signed statement that one PC (its Machine ID) is
 * licensed to one customer. Simu checks these with app/licensing.py, and this
 * class is the other half of that file.
 *
 *   license.json  {"format":"simu-license/2","payload":"<base64>","signature":"<hex>"}
 *   payload       the exact bytes signed, a JSON object:
 *                 {"v":2,"license_id":17,"machine_id":"C590...","customer":"Acme",
 *                  "issued_utc":1758540000}
 *   signature     ECDSA P-256 over SHA-256, DER as openssl_sign emits it, hex.
 *
 * The payload is signed as BYTES and carried as base64, so PHP and Python
 * never have to agree on how to serialise JSON: Simu decodes the base64,
 * verifies those bytes, and only then reads them. Fields Simu does not know
 * are ignored on its side, so an expiry or a device limit can be added here
 * later without breaking a licence that is already installed.
 *
 * Environment:
 *   SIMU_LICENSE_KEY_PATH  the P-256 private key, PEM. Defaults to the
 *                          directory above public_html, beside grant_key.pem:
 *                          /home/<user>/simu_license_key.pem, OUTSIDE the web
 *                          root. A separate key from the device grants on
 *                          purpose - the firmware's key can never change; this
 *                          one could, at the price of one exe rebuild.
 */

require_once __DIR__ . '/../config/env.php';

class SimuSigner {
    const FORMAT          = 'simu-license/2';
    const PAYLOAD_VERSION = 2;
    const CUSTOMER_MAX    = 100;

    /** @var resource|OpenSSLAsymmetricKey */
    private $key;
    /** @var resource|OpenSSLAsymmetricKey  the public half, for verifying */
    private $pub;
    private $keyPath;

    public function __construct() {
        // __DIR__ is <root>/services; one up is the backend, two up is
        // public_html, three up is the account home - outside the web root.
        $default = dirname(__DIR__, 3) . '/simu_license_key.pem';
        $this->keyPath = env('SIMU_LICENSE_KEY_PATH', $default);

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('SimuSigner: the openssl extension is not loaded');
        }
        if (!is_readable($this->keyPath)) {
            throw new RuntimeException('SimuSigner: private key not readable at ' . $this->keyPath);
        }

        $pem = file_get_contents($this->keyPath);
        $this->key = openssl_pkey_get_private($pem);
        if ($this->key === false) {
            throw new RuntimeException('SimuSigner: could not load private key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($this->key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new RuntimeException('SimuSigner: key is not a NIST P-256 (prime256v1) EC key');
        }

        // openssl_verify() will not take an EC private key and work the
        // public half out for itself. Derive it once, here.
        $this->pub = openssl_pkey_get_public($details['key']);
        if ($this->pub === false) {
            throw new RuntimeException('SimuSigner: could not derive public key: ' . openssl_error_string());
        }
    }

    /**
     * Where the key was loaded from - for the admin page and the tools.
     */
    public function keyPath() {
        return $this->keyPath;
    }

    /**
     * Is this a Machine ID the way Simu prints it: 32 hex characters.
     */
    public static function isValidMachineId($mid) {
        return is_string($mid) && preg_match('/^[0-9A-Fa-f]{32}$/', $mid) === 1;
    }

    /**
     * Is this a customer name we will sign: 1..100 characters, no control
     * characters, valid UTF-8 (json_encode refuses anything else).
     */
    public static function isValidCustomer($customer) {
        return is_string($customer)
            && strlen($customer) >= 1
            && strlen($customer) <= self::CUSTOMER_MAX
            && preg_match('/^[^\x00-\x1F\x7F]+$/u', $customer) === 1;
    }

    /**
     * Mint a licence. Returns the document as the JSON string Simu stores.
     */
    public function issue($licenseId, $machineId, $customer, $issuedUtc) {
        if (!self::isValidMachineId($machineId)) {
            throw new InvalidArgumentException('machine_id must be 32 hex characters');
        }
        if (!self::isValidCustomer($customer)) {
            throw new InvalidArgumentException('customer must be 1-100 printable characters');
        }

        $payload = [
            'v'          => self::PAYLOAD_VERSION,
            'license_id' => (int)$licenseId,
            'machine_id' => strtoupper($machineId),
            'customer'   => (string)$customer,
            'issued_utc' => (int)$issuedUtc,
        ];
        $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($bytes === false) {
            throw new RuntimeException('SimuSigner: could not encode payload: ' . json_last_error_msg());
        }

        $der = '';
        if (!openssl_sign($bytes, $der, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('SimuSigner: openssl_sign failed: ' . openssl_error_string());
        }
        // Never hand out a signature this key cannot itself verify. Cheap, and
        // it turns a corrupt key file into an error here rather than a refusal
        // on a customer's PC.
        if (openssl_verify($bytes, $der, $this->pub, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('SimuSigner: signature did not verify against its own key');
        }

        $doc = [
            'format'    => self::FORMAT,
            'payload'   => base64_encode($bytes),
            'signature' => bin2hex($der),
        ];
        return json_encode($doc, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Does a document we issued earlier still verify against the current
     * key? Used before handing a stored document out again, so a rotated key
     * is noticed at the first re-request rather than by a customer.
     */
    public function verifyDocument($docJson) {
        $parts = self::unpack($docJson);
        if ($parts === null) {
            return false;
        }
        return openssl_verify($parts['bytes'], $parts['sig'], $this->pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The signed fields of a document, for display. Does not verify.
     */
    public static function decodeDocument($docJson) {
        $parts = self::unpack($docJson);
        if ($parts === null) {
            return null;
        }
        $fields = json_decode($parts['bytes'], true);
        return is_array($fields) ? $fields : null;
    }

    /**
     * The public half as PEM - what app/licensing.py carries as
     * PUBLIC_KEY_PEM. tools/simu_license_pubkey.php prints it to compare.
     */
    public function publicKeyPem() {
        $d = openssl_pkey_get_details($this->key);
        return $d['key'];
    }

    private static function unpack($docJson) {
        $doc = is_array($docJson) ? $docJson : json_decode((string)$docJson, true);
        if (!is_array($doc) || ($doc['format'] ?? null) !== self::FORMAT) {
            return null;
        }
        if (!is_string($doc['payload'] ?? null) || !is_string($doc['signature'] ?? null)) {
            return null;
        }
        $bytes = base64_decode($doc['payload'], true);
        $sig   = @hex2bin($doc['signature']);
        if ($bytes === false || $sig === false) {
            return null;
        }
        return ['bytes' => $bytes, 'sig' => $sig];
    }
}
