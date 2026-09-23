-- ============================================================================
-- Hardware and bootloader versions on the provisioning row
-- ============================================================================
-- fw_version has been on the row since the start: the firmware the board ran
-- when it was provisioned. These two complete the picture of what was built:
--
--   hw_version    the board revision ("1.1"). Nothing on the board can measure
--                 it, so the operator types it into Orca, which stores it on
--                 the board (Shared Config, `hw set=`) before asking for the
--                 grant - the row and the board say the same thing.
--   boot_version  the bootloader's version ("V1.1.37"), which the bootloader
--                 stamps into Shared Config and the application reports.
--                 NULL for a bootloader older than that stamp.
--
-- All three are a snapshot taken at provisioning. The hardware revision never
-- changes; the other two go stale after a firmware update, which is not what
-- this row is for.
--
-- Run: mysql -u <user> -p <db> < migration_provision_versions.sql
-- ============================================================================

ALTER TABLE provisions
    ADD COLUMN hw_version   VARCHAR(20) NULL AFTER fw_version,
    ADD COLUMN boot_version VARCHAR(50) NULL AFTER hw_version;
