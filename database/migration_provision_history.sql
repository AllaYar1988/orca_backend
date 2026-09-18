-- Migration: a serial may be rewritten, and every change is kept
-- Run AFTER migration_provisioning.sql and migration_provisioning_simplify.sql:
--   mysql -u username -p orca_iot < migration_provision_history.sql
--
-- WHY
-- A chip was bound to the first serial it was ever given, and asking for a
-- different one was refused. That is the wrong trade for a bench: serials get
-- mistyped, boards get repurposed before they ship, and a strict rule just
-- means somebody edits the database by hand - which is the one thing that
-- leaves no trace at all.
--
-- So the serial can be rewritten. What must not be lost is the fact that it
-- changed, and every grant ever issued for the chip. `provisions` keeps the
-- CURRENT state, one row per chip; this table keeps the timeline.
--
-- Counting is unaffected: a device is a chip, `provisions` still has one row
-- per chip, and rewriting a serial does not make a second device.

CREATE TABLE IF NOT EXISTS provision_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provision_id INT NULL,
    uid CHAR(24) NOT NULL,
    serial_number VARCHAR(31) NOT NULL,
    previous_serial VARCHAR(31) NULL,
    grant_b64 CHAR(168) NOT NULL,
    issued_utc INT UNSIGNED NOT NULL,
    event VARCHAR(20) NOT NULL,
    tool VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uid (uid),
    INDEX idx_serial_number (serial_number),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- One row per grant this server ever issued, newest last.
--   serial_number    what the grant says, at that moment
--   previous_serial  what it said before, on a change
--   event            issued | serial_changed | replaced_chip
--   grant_b64        the exact bytes handed out - a grant already installed
--                    on a board goes on working, so the old ones still matter
--
-- provision_id is not a foreign key on purpose: the history outlives the row
-- it describes, including a provision deleted by hand.

-- Backfill: every provision already recorded is its own first issue.
INSERT INTO provision_history
    (provision_id, uid, serial_number, grant_b64, issued_utc, event, tool, ip_address, created_at)
SELECT id, uid, serial_number, grant_b64, issued_utc, 'issued', tool, ip_address, created_at
FROM provisions
WHERE NOT EXISTS (SELECT 1 FROM provision_history h WHERE h.uid = provisions.uid);

-- ---------------------------------------------------------------------------
-- And drop the field cross-check view, which is not wanted after all.
--
-- It listed devices that had reported to this server under a serial no grant
-- was ever issued for. Every unit built before licensing is one of those, so
-- on a live system the list is mostly history rather than a question - and
-- this page is for what Orca provisions, nothing else.
--
-- Nothing reads it any more. IF EXISTS, so this is safe whether or not the
-- first migration got as far as creating it.
-- ---------------------------------------------------------------------------
DROP VIEW IF EXISTS unprovisioned_devices;
