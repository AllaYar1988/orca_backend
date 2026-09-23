-- Migration: Simu licences - one row per PC, requested by Simu, approved here
-- Run this SQL on your database:
--   mysql -u username -p orca_iot < migration_simu_licenses.sql
--
-- WHY
-- Simu (the PC software that polls the loggers) is locked to the computer it
-- is installed on, and until now the licence file that unlocks it was signed
-- by hand on a laptop, with no record of who got one. This is the record, and
-- the place the decision is made: the same shape as `provisions` for chips,
-- with the Machine ID where the UID is.
--
-- ONE ROW PER PC
-- machine_id is UNIQUE. A PC asking again gets its own row back - pending
-- again, or the same document once approved - so a reinstall is free and one
-- computer cannot hold two licences. A new motherboard or a reinstalled
-- Windows is a new Machine ID, a new row, and a new decision.
--
-- TWO WAYS IN, ONE FILE
--   online   Simu POSTs api/simu_request.php -> status 'pending' -> an admin
--            approves on admin/simu_licenses.php -> Simu polls the document
--            up with api/simu_poll.php.
--   manual   the customer emails the Machine ID; the admin issues the row on
--            the same page, downloads license.json and emails it back.
-- `source` says which; the document is the same either way.
--
-- The document (license_doc) is stored verbatim rather than re-signed on
-- demand: ECDSA is randomised, so re-signing the same facts gives different
-- bytes. Returning the stored document keeps a re-request byte-identical to
-- the original, which is what makes it auditable.
--
-- ROOM FOR LATER
-- Nothing here expires and nothing counts devices. When that comes, it is
-- new columns on this table and new fields in the signed payload; a Simu
-- that predates them ignores fields it does not know, so nothing already
-- installed breaks.

CREATE TABLE IF NOT EXISTS simu_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    machine_id CHAR(32) NOT NULL,
    customer VARCHAR(100) NOT NULL,
    contact VARCHAR(100) NULL,
    company_id INT NULL,
    hostname VARCHAR(100) NULL,
    simu_version VARCHAR(30) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    source VARCHAR(8) NOT NULL DEFAULT 'online',
    license_doc TEXT NULL,
    issued_utc INT UNSIGNED NULL,
    requested_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    disabled_at TIMESTAMP NULL,
    ip_address VARCHAR(45) NULL,
    last_poll_at TIMESTAMP NULL,
    poll_count INT UNSIGNED NOT NULL DEFAULT 0,
    reissue_count INT UNSIGNED NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_machine_id (machine_id),
    INDEX idx_status (status),
    INDEX idx_customer (customer),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_simu_licenses_company FOREIGN KEY (company_id)
        REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- machine_id     the PC, 32 hex characters exactly as Simu's activation page
--                shows it. UNIQUE is the whole mechanism: one PC, one row,
--                however many times it asks.
-- customer       the company name as typed in Simu, or by the admin. Signed
--                into the document, shown in Simu's settings.
-- contact        optional: an email or phone the customer typed with the
--                request, so the admin can reach them. Not signed.
-- company_id     which CUSTOMER account, if any - bookkeeping only, as on
--                provisions. NULL is fine; a deleted company clears it.
-- status         pending  asked, not yet decided
--                active   approved (or issued by hand): license_doc is set
--                rejected refused; asking again makes it pending again
--                disabled revoked after it was active; Simu is refused until
--                         an admin restores it. The file already on the PC
--                         goes on working - there is no expiry yet.
-- source         online (Simu asked) or manual (admin typed the Machine ID).
-- license_doc    the signed document, verbatim, exactly as handed out.
-- issued_utc     the timestamp inside the signed payload.
-- requested_at   the last time Simu asked (a re-request refreshes it).
-- poll_count     how often the PC has checked back. A pending row that has
--                stopped polling is a customer who gave up, or went offline.
-- reissue_count  how many times an active PC asked again (reinstall).
-- ip_address     where the request came from. Not authentication - a trail.

-- ============================================================================
-- Every decision, kept
-- ============================================================================
CREATE TABLE IF NOT EXISTS simu_license_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    event VARCHAR(20) NOT NULL,
    machine_id CHAR(32) NOT NULL,
    customer VARCHAR(100) NULL,
    license_doc TEXT NULL,
    actor VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_license_id (license_id),
    INDEX idx_machine_id (machine_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- event      requested | approved | rejected | issued_manual | reissued |
--            disabled | restored
-- actor      'simu' for what the PC did, 'admin' (with the username when
--            known) for what a person did
-- license_id is not a foreign key on purpose: the history outlives the row it
--            describes, including a row deleted by hand.
