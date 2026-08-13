-- ============================================================
-- BienMarko — Bookings Table Migration
-- Run once. Safe to re-run: uses IF NOT EXISTS everywhere.
-- ============================================================

CREATE TABLE IF NOT EXISTS `bookings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference`        VARCHAR(20)  NOT NULL UNIQUE,
    `guest_id`         INT UNSIGNED NOT NULL,
    `accommodation_id` INT UNSIGNED NOT NULL,
    `check_in`         DATE         NOT NULL,
    `check_out`        DATE         NOT NULL,
    `guests_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `notes`            TEXT         NULL,
    `status`           ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
    `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_guest`           (`guest_id`),
    KEY `idx_accommodation`   (`accommodation_id`),
    KEY `idx_dates`           (`check_in`, `check_out`),
    KEY `idx_status`          (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
