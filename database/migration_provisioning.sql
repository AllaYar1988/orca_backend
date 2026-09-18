-- Migration: Device provisioning - counting serials before they exist
-- Run this SQL on your database:
--   mysql -u username -p orca_iot < migration_provisioning.sql
--
-- WHY
-- A vendor with our hardware, dl-flash and Orca can build a device end to end.
-- The only moment we can count - and bill - is when the board is given its
-- serial number, and the firmware now refuses to take one without a GRANT: a
-- record signed by this server saying "serial X belongs on chip Y". The chip
-- is identified by its 96-bit STM32 unique ID, which cannot be changed or
-- duplicated. Every grant this server ever issues is a row here.
--
-- Counting happens HERE, at provisioning, not when a device first connects.
-- Most devices never connect to this server at all; the tool on the vendor's
-- bench does.
--
-- The grant itself (grant_b64) is stored verbatim rather than re-signed on
-- demand: ECDSA is randomised, so re-signing the same facts gives different
-- bytes. Returning the stored blob keeps a re-provision byte-identical to the
-- original, which is what makes it auditable and what makes a wiped board's
-- repair free - same uid, same serial, same grant, quota untouched.

-- ============================================================================
-- Companies become vendors: a key Orca authenticates with, a quota, and a
-- serial range to allocate from.
-- ============================================================================
ALTER TABLE companies
    ADD COLUMN provision_key VARCHAR(64) NULL AFTER is_active,
    ADD COLUMN device_quota INT UNSIGNED NULL AFTER provision_key,
    ADD COLUMN serial_prefix VARCHAR(8) NULL AFTER device_quota,
    ADD COLUMN serial_next INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_prefix,
    ADD INDEX idx_provision_key (provision_key);

-- provision_key   Bearer token Orca sends. Set with tools/set_provision_key.php.
--                 NULL means this company cannot provision at all.
-- device_quota    Grants this company may hold. NULL = unlimited. Re-issues
--                 for a known UID never count against it.
-- serial_prefix   e.g. 'A2': serials are allocated as prefix + zero-padded
--                 counter to 8 characters (A2000137). NULL = the vendor must
--                 supply a serial and the server only checks it is unused.
-- serial_next     the next counter value; advanced under row lock.

-- ============================================================================
-- Provisions: one row per chip, ever.
-- ============================================================================
CREATE TABLE IF NOT EXISTS provisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    uid CHAR(24) NOT NULL,
    serial_number VARCHAR(31) NOT NULL,
    grant_b64 CHAR(168) NOT NULL,
    issued_utc INT UNSIGNED NOT NULL,
    retired_at TIMESTAMP NULL,
    replaced_by INT NULL,
    tool VARCHAR(50) NULL,
    tool_version VARCHAR(50) NULL,
    fw_version VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    reissue_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_reissued_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (replaced_by) REFERENCES provisions(id),
    UNIQUE KEY uq_uid (uid),
    INDEX idx_company_id (company_id),
    INDEX idx_serial_number (serial_number),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- uid            the chip, 24 hex characters exactly as dl-flash, Orca and the
--                firmware's `uid` command print it. UNIQUE is the whole
--                mechanism: one chip, one grant, however many times it is
--                asked for.
-- serial_number  NOT unique on its own: after a chip replacement the old row
--                is retired and a new row carries the same serial. The live
--                serial is unique among rows where retired_at IS NULL, which
--                the model enforces.
-- grant_b64      the 124-byte grant, base64, exactly as handed to the tool.
-- issued_utc     the timestamp inside the signed payload.
-- retired_at     set when the MCU was replaced and this chip's grant retired;
--                replaced_by points at the row that inherited the serial.
-- reissue_count  how many times the same UID asked again (re-flash, wiped
--                config). Free, but worth being able to see.
-- ip_address     where the request came from. Not authentication - a trail.

-- ============================================================================
-- Field sightings vs provisioning: the cross-check
-- ============================================================================
-- A device that connects to this server lands in `devices`. A serial that
-- appears there with no live row here is a device built outside the process.
-- The admin page reports it; this view is what it reads.
CREATE OR REPLACE VIEW unprovisioned_devices AS
    SELECT d.id, d.serial_number, d.company_id, c.name AS company_name,
           d.last_seen_at, d.created_at
    FROM devices d
    LEFT JOIN companies c ON c.id = d.company_id
    LEFT JOIN provisions p ON p.serial_number = d.serial_number
                          AND p.retired_at IS NULL
    WHERE p.id IS NULL;
