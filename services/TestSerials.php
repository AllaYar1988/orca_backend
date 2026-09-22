<?php
require_once __DIR__ . '/../config/env.php';
/**
 * TestSerials - the handful of serial numbers that are not devices.
 *
 * Development and test boards are reflashed all day and are never sold, so
 * counting them as devices says something untrue about how many exist. They
 * are given one of a small, closed set of serials instead - ten of them, all
 * ending in the year - and the rows that carry those serials are marked
 * is_test and left out of the count.
 *
 * THE LIST IS THE TRUTH, NOT A CHECKBOX
 * A row is a test row because of the serial it carries, never because of a
 * flag someone ticked. Orca's "Test board" box only means "pick one for me":
 * type a reserved serial without ticking it and the row is still a test row,
 * tick it and type a real serial and the server hands back a reserved one
 * instead. There is no combination of box and typing that produces a board
 * which is test in one place and real in another.
 *
 * SHARED ON PURPOSE
 * A test serial may be live on any number of chips at once - that is the
 * whole point, and it is why the uniqueness check in provision.php is
 * skipped for them. Each chip still gets its own grant, signed against its
 * own UID, because a grant is bound to the chip and sharing a serial is only
 * bookkeeping.
 *
 * WHAT IT COSTS
 * A test serial is an unlimited free pass: anyone holding the provisioning
 * key can build boards all day under A0010707 and none of them will be
 * counted. What keeps that honest is that the name is visible - the board
 * reports it to this server on every upload - so a test board loose in the
 * field stands out on the portal rather than hiding among the real ones.
 */

class TestSerials {

    /**
     * The ten. Overridable with PROVISION_TEST_SERIALS in .env (comma
     * separated) so the set can change without a deploy; the default is
     * here so a server with no such line still behaves.
     */
    const DEFAULTS = 'A0010707,A0020707,A0030707,A0040707,A0050707,' .
                     'A0060707,A0070707,A0080707,A0090707,A0100707';

    private static $cache = null;

    /**
     * The reserved serials, upper case, in the order they were configured.
     */
    public static function all() {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $raw = trim((string)env('PROVISION_TEST_SERIALS', ''));
        if ($raw === '') {
            $raw = self::DEFAULTS;
        }
        $out = [];
        foreach (explode(',', $raw) as $s) {
            $s = strtoupper(trim($s));
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return self::$cache = $out;
    }

    /**
     * Is this one of the reserved serials? The one question the rest of the
     * code asks - is_test is derived from it and never from the request.
     */
    public static function isTest($serial) {
        return in_array(strtoupper(trim((string)$serial)), self::all(), true);
    }

    public static function count() {
        return count(self::all());
    }
}
