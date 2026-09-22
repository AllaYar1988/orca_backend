-- ============================================================================
-- Test boards: the serials that are not devices
-- ============================================================================
-- Development and test boards are reflashed all day and never sold. Counting
-- them would make the one number this whole system exists to produce - how
-- many devices were built - wrong by however many boards sit on the bench.
--
-- They get one of ten reserved serials instead (A0010707 .. A0100707, the
-- list lives in TestSerials / PROVISION_TEST_SERIALS), and the rows carrying
-- them are marked is_test. Those rows:
--   - may share a serial with any number of other chips: the live-serial
--     uniqueness rule is skipped for them, in provision.php;
--   - are left out of countLive() and out of the live figures in stats();
--   - still hold a real grant, signed against their own chip's UID, because
--     a grant is bound to the chip and sharing a serial is only bookkeeping.
--
-- is_test is derived from the serial every time a row is written, so it can
-- never disagree with the name the board carries. A test board that is later
-- given a real serial goes through the ordinary rename path and the flag
-- clears itself - which is the moment it becomes a device and starts being
-- counted.
--
-- The unprovisioned_devices view needs no change: it keeps only devices whose
-- serial matches NO live row, so a board reporting a test serial matches and
-- is correctly not reported as built outside the process.
-- ============================================================================

ALTER TABLE provisions
    ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER serial_number,
    ADD INDEX idx_is_test (is_test);

-- Anything already carrying a reserved serial was a test board all along.
UPDATE provisions SET is_test = 1 WHERE serial_number IN (
    'A0010707','A0020707','A0030707','A0040707','A0050707',
    'A0060707','A0070707','A0080707','A0090707','A0100707');
