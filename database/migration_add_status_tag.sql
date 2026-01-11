-- Migration: Add status tag to device_logs
-- This allows threshold status to be calculated at ingestion time
-- and stored with the data for efficient querying
--
-- Run this migration: mysql -u username -p database_name < migration_add_status_tag.sql

-- Add status column to device_logs table
ALTER TABLE device_logs
ADD COLUMN status ENUM('normal', 'warning', 'critical') DEFAULT 'normal' AFTER log_value;

-- Add index for efficient status filtering
CREATE INDEX idx_status ON device_logs(status);

-- Compound index for device + status queries (Level 1: Company page)
CREATE INDEX idx_device_status ON device_logs(device_id, status);

-- Compound index for time-range + status queries
CREATE INDEX idx_logged_status ON device_logs(logged_at, status);

-- ============================================================================
-- OPTIONAL: Backfill existing records with status based on current thresholds
-- Run this AFTER the migration if you want historical data to have status
-- WARNING: This can be slow on large tables - run during maintenance window
-- ============================================================================

-- UPDATE device_logs dl
-- JOIN sensor_configs sc ON dl.device_id = sc.device_id AND dl.log_key = sc.log_key
-- SET dl.status = CASE
--     WHEN sc.alarm_enabled = 1 AND (
--         (sc.min_alarm IS NOT NULL AND CAST(dl.log_value AS DECIMAL(15,4)) < sc.min_alarm) OR
--         (sc.max_alarm IS NOT NULL AND CAST(dl.log_value AS DECIMAL(15,4)) > sc.max_alarm)
--     ) THEN 'critical'
--     ELSE 'normal'
-- END
-- WHERE dl.status IS NULL OR dl.status = 'normal';
