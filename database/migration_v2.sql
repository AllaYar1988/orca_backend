-- Migration v2: Add multi-company support and IoT enhancements
-- Run these queries on your production database

-- 1. Add is_online column to devices table (if not exists)
ALTER TABLE devices ADD COLUMN IF NOT EXISTS is_online TINYINT(1) DEFAULT 0 AFTER is_active;

-- 2. Create user_companies table (if not exists)
CREATE TABLE IF NOT EXISTS user_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_company (user_id, company_id),
    INDEX idx_user_id (user_id),
    INDEX idx_company_id (company_id)
) ENGINE=InnoDB;

-- 3. Create device_events table (if not exists)
CREATE TABLE IF NOT EXISTS device_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_message TEXT,
    event_data JSON,
    severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    INDEX idx_device_id (device_id),
    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- 4. Create device_configs table (if not exists)
CREATE TABLE IF NOT EXISTS device_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    config_value TEXT,
    config_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    UNIQUE KEY unique_device_config (device_id, config_key),
    INDEX idx_device_id (device_id)
) ENGINE=InnoDB;

-- 5. Migrate existing users to user_companies table
-- If users have company_id column, copy that relationship to user_companies
-- Note: Only run this if your users table still has company_id column
-- INSERT IGNORE INTO user_companies (user_id, company_id)
-- SELECT id, company_id FROM users WHERE company_id IS NOT NULL;

-- 6. After migration is verified, you can optionally drop company_id from users
-- ALTER TABLE users DROP COLUMN company_id;
