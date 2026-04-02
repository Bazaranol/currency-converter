-- Create test database and grant access to the app user.
-- This script runs automatically on first container start.
CREATE DATABASE IF NOT EXISTS currency_converter_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON currency_converter_test.* TO 'currency_user'@'%';
FLUSH PRIVILEGES;
