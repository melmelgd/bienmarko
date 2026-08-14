-- ============================================================
-- BienMarko — Accommodation Categories Migration
-- Run AFTER schema.sql and accommodations.sql.
-- Safe to re-run: uses IF NOT EXISTS / IF NOT EXISTS guards.
-- ============================================================

-- 1. Categories master table
CREATE TABLE IF NOT EXISTS `accommodation_categories` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add category_id FK to accommodations (nullable — rooms without a
--    category are grouped under "Uncategorised" in the UI).
ALTER TABLE `accommodations`
    ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL DEFAULT NULL
        AFTER `image_filename`,
    ADD KEY IF NOT EXISTS `idx_category` (`category_id`);

-- Note: We intentionally do NOT add a FOREIGN KEY constraint here so
-- that deleting a category does not cascade-delete rooms — the API
-- sets category_id to NULL on category deletion instead.
