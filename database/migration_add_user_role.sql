-- Migration: Add role column to users table
-- Run this SQL to add user roles support
-- Roles: admin, user, viewer

-- Add role column to users table
ALTER TABLE users
ADD COLUMN role ENUM('admin', 'user', 'viewer') NOT NULL DEFAULT 'user'
AFTER is_active;

-- Add index for role queries
ALTER TABLE users ADD INDEX idx_role (role);

-- Update existing users to 'user' role (already default, but explicit)
UPDATE users SET role = 'user' WHERE role IS NULL;
