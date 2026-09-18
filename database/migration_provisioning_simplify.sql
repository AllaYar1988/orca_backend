-- Migration: provisioning belongs to the vendor, not to a customer
-- Run AFTER migration_provisioning.sql:
--   mysql -u username -p orca_iot < migration_provisioning_simplify.sql
--
-- WHY
-- The first migration hung provisioning off `companies`, and companies are
-- CUSTOMERS - the people who own devices and log in to see their data. The
-- vendor is whoever builds the devices, which is a different role entirely.
-- Putting a provisioning key and a device quota on a customer row said
-- something untrue about the data.
--
-- There is one vendor today: us. So provisioning has no owner table at all -
-- the key lives in .env as PROVISION_KEY, and the serial prefix beside it.
-- Nothing to create, nothing to keep in step.
--
-- When there is a second vendor, THIS is the point to add a `vendors` table
-- and give provisions.vendor_id a home. The rows written now survive it:
-- every one of them belongs to vendor "us", whatever that ends up being
-- called.

-- ---------------------------------------------------------------------------
-- provisions.company_id: no longer who provisioned, and not required.
-- Kept, nullable, for the question it can usefully answer later: which
-- CUSTOMER a device was eventually sold to. Nothing writes it today.
-- ---------------------------------------------------------------------------
ALTER TABLE provisions MODIFY COLUMN company_id INT NULL;

-- ---------------------------------------------------------------------------
-- The customer table goes back to describing customers.
-- ---------------------------------------------------------------------------
ALTER TABLE companies
    DROP COLUMN provision_key,
    DROP COLUMN device_quota,
    DROP COLUMN serial_prefix,
    DROP COLUMN serial_next;
