-- Migration: Virtual Devices
-- Virtual devices group sensors from multiple physical devices of the same company

-- Virtual Devices Table
CREATE TABLE IF NOT EXISTS virtual_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_id (company_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB;

-- Virtual Device Sensors Table (maps sensors from real devices to virtual devices)
CREATE TABLE IF NOT EXISTS virtual_device_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    virtual_device_id INT NOT NULL,
    source_device_id INT NOT NULL,
    source_log_key VARCHAR(255) NOT NULL,
    display_label VARCHAR(255),
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices(id) ON DELETE CASCADE,
    FOREIGN KEY (source_device_id) REFERENCES devices(id) ON DELETE CASCADE,
    UNIQUE KEY unique_virtual_sensor (virtual_device_id, source_device_id, source_log_key),
    INDEX idx_virtual_device_id (virtual_device_id),
    INDEX idx_source_device_id (source_device_id)
) ENGINE=InnoDB;

-- User Virtual Device Access Table (which virtual devices a user can access)
CREATE TABLE IF NOT EXISTS user_virtual_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    virtual_device_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_virtual_device (user_id, virtual_device_id),
    INDEX idx_user_id (user_id),
    INDEX idx_virtual_device_id (virtual_device_id)
) ENGINE=InnoDB;
