<?php
/**
 * GrantSigner
 *
 * Mints device grants: the signed statement that one serial number belongs
 * on one chip. The firmware checks these with device_grant.c, and this class
 * is the other half of that file - the layout below must match it BYTE FOR
 * BYTE or every grant is refused on the vendor's bench.
 *
 *     offset  size  field
 *          0     4  magic       'DLLC' little-endian
 *          4     4  layout_ver  1
 *          8    12  uid         the chip, as printed (high word first)
 *         20    32  serial      NUL-padded across ALL 32 bytes
 *         52     4  company_id
 *         56     4  issued_utc
 *         ------------  60 bytes signed, up to here
 *         60    64  sig         ECDSA P-256 over SHA-256, r then s, raw
 *
 * The private key never leaves the machine that signs. It is a PEM file the
 * bench tool (DL_Main_CCode/tools/grant_sign.py keygen) writes, and the same
 * file works here - one key, one public half in the firmware.
 *
 * Environment:
 *   GRANT_PRIVATE_KEY_PATH  where the PEM lives. Defaults to the directory
 *                           above public_html - on this hosting layout
 *                           (/home/<user>/public_html/orca_backend) that is
 *                           /home/<user>/grant_key.pem, OUTSIDE the web root.
 *                           Keep it there: a .pem inside public_html is one
 *                           misconfiguration away from being served as text.
 */

require_once __DIR__ . '/../config/env.php';

class GrantSigner {
    const MAGIC       = 0x434C4C44;   // 'DLLC'
    const LAYOUT_VER  = 1;
    const UID_LEN     = 12;
    const SERIAL_LEN  = 32;
    const SIG_LEN     = 64;
    const SIGNED_LEN  = 60;
    const TOTAL_LEN   = 124;
    const B64_LEN     = 168;

    /** @var resource|OpenSSLAsymmetricKey */
    private $key;
    /** @var resource|OpenSSLAsymmetricKey  the public half, for verifying */
    private $pub;
    private $keyPath;

    public function __construct() {
        // __DIR__ is <root>/services; one up is the backend, two up is
        // public_html, three up is the account home - outside the web root.
        $default = dirname(__DIR__, 3) . '/grant_key.pem';
        $this->keyPath = env('GRANT_PRIVATE_KEY_PATH', $default);

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('GrantSigner: the openssl extension is not loaded');
        }
        if (!is_readable($this->keyPath)) {
            throw new RuntimeException('GrantSigner: private key not readable at ' . $this->keyPath);
        }

        $pem = file_get_contents($this->keyPath);
        $this->key = openssl_pkey_get_private($pem);
        if ($this->key === false) {
            throw new RuntimeException('GrantSigner: could not load private key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($this->key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new RuntimeException('GrantSigner: key is not a NIST P-256 (prime256v1) EC key');
        }

        // openssl_verify() will not take an EC private key and work the
        // public half out for itself (OpenSSL 3: "Don't know how to get public
        // key from this private key"). Derive it once, here, from the PEM the
        // details already carry.
        $this->pub = openssl_pkey_get_public($details['key']);
        if ($this->pub === false) {
            throw new RuntimeException('GrantSigner: could not derive public key: ' . openssl_error_string());
        }
    }

    /**
     * Where the key was loaded from - for the admin page and the tools.
     */
    public function keyPath() {
        return $this->keyPath;
    }

    /**
     * Is this a UID the way we expect it: 24 hex characters, as printed.
     */
    public static function isValidUid($uid) {
        return is_string($uid) && preg_match('/^[0-9A-Fa-f]{24}$/', $uid) === 1;
    }

    /**
     * Is this serial something the firmware can store: 1..31 printable ASCII.
     * The firmware rejects anything else as GRANT_MALFORMED, so refuse it here
     * where the message can say why.
     */
    public static function isValidSerial($serial) {
        return is_string($serial)
            && strlen($serial) >= 1
            && strlen($serial) <= self::SERIAL_LEN - 1
            && preg_match('/^[\x20-\x7E]+$/', $serial) === 1;
    }

    /**
     * The 60 bytes the signature covers.
     */
    public static function payload($uid, $serial, $companyId, $issuedUtc) {
        if (!self::isValidUid($uid)) {
            throw new InvalidArgumentException('uid must be 24 hex characters');
        }
        if (!self::isValidSerial($serial)) {
            throw new InvalidArgumentException('serial must be 1-31 printable ASCII characters');
        }

        $p  = pack('V', self::MAGIC);
        $p .= pack('V', self::LAYOUT_VER);
        $p .= hex2bin($uid);                                   // 12 bytes, as printed
        $p .= str_pad($serial, self::SERIAL_LEN, "\0");         // NUL across all 32
        $p .= pack('V', (int)$companyId);
        $p .= pack('V', (int)$issuedUtc);

        if (strlen($p) !== self::SIGNED_LEN) {
            throw new RuntimeException('GrantSigner: payload is ' . strlen($p) . ' bytes, not ' . self::SIGNED_LEN);
        }
        return $p;
    }

    /**
     * Mint a grant. Returns the 124 raw bytes.
     */
    public function sign($uid, $serial, $companyId, $issuedUtc) {
        $payload = self::payload($uid, $serial, $companyId, $issuedUtc);

        $der = '';
        if (!openssl_sign($payload, $der, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('GrantSigner: openssl_sign failed: ' . openssl_error_string());
        }

        // Never hand out a signature this key cannot itself verify. Cheap, and
        // it turns a corrupt key file into an error here rather than a refusal
        // on somebody's bench.
        if (openssl_verify($payload, $der, $this->pub, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('GrantSigner: signature did not verify against its own key');
        }

        $raw = self::derToRaw($der);
        $grant = $payload . $raw;

        if (strlen($grant) !== self::TOTAL_LEN) {
            throw new RuntimeException('GrantSigner: grant is ' . strlen($grant) . ' bytes, not ' . self::TOTAL_LEN);
        }
        return $grant;
    }

    /**
     * Mint a grant and return it the way the tool sends it to the device.
     */
    public function signBase64($uid, $serial, $companyId, $issuedUtc) {
        $b64 = base64_encode($this->sign($uid, $serial, $companyId, $issuedUtc));
        if (strlen($b64) !== self::B64_LEN) {
            throw new RuntimeException('GrantSigner: base64 is ' . strlen($b64) . ' chars, not ' . self::B64_LEN);
        }
        return $b64;
    }

    /**
     * Check a grant we issued earlier still verifies against the current key.
     * Used before re-issuing a stored blob, so a rotated key is noticed at the
     * first re-provision rather than by a vendor.
     */
    public function verifyBase64($b64) {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== self::TOTAL_LEN) {
            return false;
        }
        $payload = substr($raw, 0, self::SIGNED_LEN);
        $sig     = substr($raw, self::SIGNED_LEN);
        return openssl_verify($payload, self::rawToDer($sig), $this->pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The public half as the firmware holds it: raw x||y, 64 bytes, no prefix.
     * tools/grant_pubkey.php prints this as a C array to compare with
     * license_key.h - "is the server's key the one in the firmware" is the
     * first question when grants start being refused.
     */
    public function publicKeyXY() {
        $d = openssl_pkey_get_details($this->key);
        $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return $x . $y;
    }

    /**
     * Decode a grant's fields, for display. Does not verify.
     */
    public static function decodeBase64($b64) {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== self::TOTAL_LEN) {
            return null;
        }
        $f = unpack('Vmagic/Vlayout_ver', substr($raw, 0, 8));
        $tail = unpack('Vcompany_id/Vissued_utc', substr($raw, 52, 8));
        return [
            'magic'      => $f['magic'],
            'magic_ok'   => $f['magic'] === self::MAGIC,
            'layout_ver' => $f['layout_ver'],
            'uid'        => strtoupper(bin2hex(substr($raw, 8, 12))),
            'serial'     => rtrim(substr($raw, 20, 32), "\0"),
            'company_id' => $tail['company_id'],
            'issued_utc' => $tail['issued_utc'],
            'sig_hex'    => bin2hex(substr($raw, 60, 8)) . '...',
        ];
    }

    // ------------------------------------------------------------------
    // DER <-> raw. OpenSSL speaks DER; micro-ecc on the device reads r||s.
    // ------------------------------------------------------------------

    /**
     * SEQUENCE { INTEGER r, INTEGER s } -> 32-byte r . 32-byte s.
     *
     * DER integers are signed and minimal: a value with its top bit set gets
     * a leading 0x00 (33 bytes), a small value can be short (31 or fewer).
     * Strip the one, left-pad the other.
     */
    public static function derToRaw($der) {
        $n = strlen($der);
        if ($n < 8 || ord($der[0]) !== 0x30) {
            throw new RuntimeException('GrantSigner: signature is not a DER SEQUENCE');
        }

        $pos = 1;
        $seqLen = self::derLen($der, $pos);
        if ($pos + $seqLen !== $n) {
            throw new RuntimeException('GrantSigner: DER SEQUENCE length mismatch');
        }

        $r = self::derInt($der, $pos);
        $s = self::derInt($der, $pos);
        if ($pos !== $n) {
            throw new RuntimeException('GrantSigner: trailing bytes after DER signature');
        }

        return self::fixInt($r) . self::fixInt($s);
    }

    /**
     * 32-byte r . 32-byte s -> DER, for openssl_verify.
     */
    public static function rawToDer($raw) {
        if (strlen($raw) !== self::SIG_LEN) {
            throw new RuntimeException('GrantSigner: raw signature must be 64 bytes');
        }
        $r = self::derIntEncode(substr($raw, 0, 32));
        $s = self::derIntEncode(substr($raw, 32, 32));
        $body = $r . $s;
        return "\x30" . self::derLenEncode(strlen($body)) . $body;
    }

    private static function derLen($der, &$pos) {
        $b = ord($der[$pos++]);
        if ($b < 0x80) {
            return $b;
        }
        $count = $b & 0x7F;
        if ($count < 1 || $count > 2) {
            throw new RuntimeException('GrantSigner: unsupported DER length form');
        }
        $len = 0;
        for ($i = 0; $i < $count; $i++) {
            $len = ($len << 8) | ord($der[$pos++]);
        }
        return $len;
    }

    private static function derInt($der, &$pos) {
        if (ord($der[$pos++]) !== 0x02) {
            throw new RuntimeException('GrantSigner: expected DER INTEGER');
        }
        $len = self::derLen($der, $pos);
        $val = substr($der, $pos, $len);
        $pos += $len;
        return $val;
    }

    /** Strip a sign byte, or pad up to 32. */
    private static function fixInt($v) {
        $v = ltrim($v, "\0");
        if (strlen($v) > 32) {
            throw new RuntimeException('GrantSigner: DER integer wider than 256 bits');
        }
        return str_pad($v, 32, "\0", STR_PAD_LEFT);
    }

    /** Minimal, positive DER INTEGER from a 32-byte unsigned value. */
    private static function derIntEncode($v32) {
        $v = ltrim($v32, "\0");
        if ($v === '') {
            $v = "\0";
        }
        if (ord($v[0]) & 0x80) {
            $v = "\0" . $v;               // keep it positive
        }
        return "\x02" . self::derLenEncode(strlen($v)) . $v;
    }

    private static function derLenEncode($len) {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x100) {
            return "\x81" . chr($len);
        }
        return "\x82" . chr($len >> 8) . chr($len & 0xFF);
    }
}
