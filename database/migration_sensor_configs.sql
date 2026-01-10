-- Migration: Sensor Configuration for 4-20mA scaling and alarms
-- Run this SQL on your database

-- Create sensor_configs table
CREATE TABLE IF NOT EXISTS sensor_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    log_key VARCHAR(50) NOT NULL,           -- e.g., 'temperature', 'pressure', 'co2'

    -- Data type: '4-20' for 4-20mA sensors, 'real' for direct value sensors
    data_type ENUM('4-20', 'real') DEFAULT 'real',

    -- Zero-Span configuration (for 4-20mA sensors)
    -- Formula: real_value = zero_value + ((raw_mA - 4) / 16) * span_value
    zero_value DECIMAL(15,4) DEFAULT 0,     -- Value at 4mA
    span_value DECIMAL(15,4) DEFAULT 100,   -- Range (value at 20mA - value at 4mA)

    -- Display settings
    unit VARCHAR(20) DEFAULT '',            -- e.g., '°C', 'bar', 'ppm'
    decimals TINYINT DEFAULT 2,             -- Decimal places for display

    -- Alarm thresholds
    min_alarm DECIMAL(15,4) DEFAULT NULL,   -- Low alarm threshold (NULL = disabled)
    max_alarm DECIMAL(15,4) DEFAULT NULL,   -- High alarm threshold (NULL = disabled)
    alarm_enabled BOOLEAN DEFAULT FALSE,    -- Enable/disable alarms

    -- Metadata
    label VARCHAR(100) DEFAULT NULL,        -- Custom label (overrides default)
    sensor_type VARCHAR(10) DEFAULT 'GEN',  -- Sensor type code (TMP, HUM, PRS, etc.)

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Unique constraint: one config per device per log_key
    UNIQUE KEY unique_device_sensor (device_id, log_key),

    -- Foreign key to devices table
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example: Configure a 4-20mA temperature sensor (4mA=0°C, 20mA=100°C)
-- INSERT INTO sensor_configs (device_id, log_key, data_type, zero_value, span_value, unit, sensor_type, min_alarm, max_alarm, alarm_enabled)
-- VALUES (1, 'temperature', '4-20', 0, 100, '°C', 'TMP', 5, 35, TRUE);

-- Example: Configure a direct value CO2 sensor
-- INSERT INTO sensor_configs (device_id, log_key, data_type, unit, sensor_type, min_alarm, max_alarm, alarm_enabled)
-- VALUES (1, 'co2', 'real', 'ppm', 'CO2', 400, 1000, TRUE);
