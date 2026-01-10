-- Migration: Add API key for device HMAC authentication
-- Run this SQL on your database

-- Add api_key column to devices table
ALTER TABLE devices
ADD COLUMN api_key VARCHAR(64) NULL AFTER serial_number,
ADD INDEX idx_api_key (api_key);

-- Generate API keys for existing devices (32 bytes = 64 hex chars)
UPDATE devices SET api_key = SHA2(CONCAT(serial_number, RAND(), NOW()), 256) WHERE api_key IS NULL;

-- Make api_key NOT NULL after populating
ALTER TABLE devices MODIFY COLUMN api_key VARCHAR(64) NOT NULL;
