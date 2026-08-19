-- ============================================================
-- BienMarko — Bookings Table Migration (updated)
-- Run once. Safe to re-run: uses IF NOT EXISTS everywhere.
-- Supports both registered-user bookings (guest_id set)
-- and walk-in guest bookings (guest_id NULL).
-- ============================================================

CREATE TABLE IF NOT EXISTS `bookings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference`        VARCHAR(20)  NOT NULL UNIQUE,
    `guest_id`         INT UNSIGNED NULL DEFAULT NULL,        -- NULL for walk-in guests
    `accommodation_id` INT UNSIGNED NOT NULL,
    `check_in`         DATE         NOT NULL,
    `check_out`        DATE         NOT NULL,
    `guests_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `notes`            TEXT         NULL,
    `status`           ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
    `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Guest-booking fields (NULL for registered-user bookings)
    `guest_name`       VARCHAR(200) NULL DEFAULT NULL,
    `email`            VARCHAR(200) NULL DEFAULT NULL,
    `phone`            VARCHAR(30)  NULL DEFAULT NULL,
    `address`          VARCHAR(255) NULL DEFAULT NULL,
    `payment_method`   ENUM('cash','gcash') NULL DEFAULT 'cash',
    `booking_type`     ENUM('registered','guest') NOT NULL DEFAULT 'registered',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_guest`           (`guest_id`),
    KEY `idx_accommodation`   (`accommodation_id`),
    KEY `idx_dates`           (`check_in`, `check_out`),
    KEY `idx_status`          (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- If the table was already created with the old schema (guest_id NOT NULL,
-- missing guest columns), run these ALTER statements to bring it up to date.
-- Safe to run even if columns already exist (uses IF NOT EXISTS / MODIFY).
-- ============================================================

-- Make guest_id nullable so walk-in guests (no account) can book
ALTER TABLE `bookings`
    MODIFY COLUMN `guest_id` INT UNSIGNED NULL DEFAULT NULL;

-- Add guest-booking columns if they don't exist yet
ALTER TABLE `bookings`
    ADD COLUMN IF NOT EXISTS `guest_name`     VARCHAR(200) NULL DEFAULT NULL     AFTER `total_amount`,
    ADD COLUMN IF NOT EXISTS `email`          VARCHAR(200) NULL DEFAULT NULL     AFTER `guest_name`,
    ADD COLUMN IF NOT EXISTS `phone`          VARCHAR(30)  NULL DEFAULT NULL     AFTER `email`,
    ADD COLUMN IF NOT EXISTS `address`        VARCHAR(255) NULL DEFAULT NULL     AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','gcash') NULL DEFAULT 'cash' AFTER `address`,
    ADD COLUMN IF NOT EXISTS `booking_type`   ENUM('registered','guest') NOT NULL DEFAULT 'registered' AFTER `payment_method`;

-- ============================================================
-- Admin notifications table
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_notifications` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id` INT UNSIGNED NULL DEFAULT NULL,
    `type`       VARCHAR(60)  NOT NULL DEFAULT 'booking',
    `message`    VARCHAR(400) NOT NULL,
    `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_booking` (`booking_id`),
    KEY `idx_is_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add any missing columns to admin_notifications if created with older schema
ALTER TABLE `admin_notifications`
    ADD COLUMN IF NOT EXISTS `booking_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `type`       VARCHAR(60) NOT NULL DEFAULT 'booking' AFTER `booking_id`;
