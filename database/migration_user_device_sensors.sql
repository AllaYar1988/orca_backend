-- Migration: User Device Sensor Access Control
-- Run this SQL on your database
--
-- This table allows restricting user access to specific sensors within a device.
-- If no rows exist for a user+device combo, user sees ALL sensors (default behavior).
-- If rows exist, user only sees the specified sensors (log_keys).

CREATE TABLE IF NOT EXISTS user_device_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_id INT NOT NULL,
    log_key VARCHAR(50) NOT NULL,           -- The sensor key (e.g., 'temperature', 'pressure')
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,

    -- Unique constraint: one entry per user per device per sensor
    UNIQUE KEY unique_user_device_sensor (user_id, device_id, log_key),

    INDEX idx_user_id (user_id),
    INDEX idx_device_id (device_id),
    INDEX idx_user_device (user_id, device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usage:
--
-- Case 1: User has access to device but NO rows in user_device_sensors
--         -> User sees ALL sensors for that device (default)
--
-- Case 2: User has access to device AND has rows in user_device_sensors
--         -> User only sees sensors listed in user_device_sensors
--
-- Example: Restrict user 5 to only see 'temperature' and 'humidity' on device 10
-- INSERT INTO user_device_sensors (user_id, device_id, log_key) VALUES
-- (5, 10, 'temperature'),
-- (5, 10, 'humidity');
