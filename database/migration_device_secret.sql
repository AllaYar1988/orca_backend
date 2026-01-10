-- Migration: Add device_secret for device authentication
-- Run this SQL on your database

-- Add device_secret column (stores bcrypt hash of password)
ALTER TABLE devices
ADD COLUMN device_secret VARCHAR(255) NULL AFTER api_key;

-- Example: Set a secret for existing device (password: "test123")
-- UPDATE devices SET device_secret = '$2y$10$YourHashHere' WHERE serial_number = 'UA022';

-- To generate a hash in PHP:
-- echo password_hash('your_device_password', PASSWORD_DEFAULT);
