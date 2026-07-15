--paste after schema.sql

CREATE TABLE IF NOT EXISTS `accommodations` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `type`           VARCHAR(100) NOT NULL DEFAULT '',
  `capacity`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `price`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`         ENUM('available','booked','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `description`    TEXT NULL,
  `amenities`      TEXT NULL,
  `image_filename` VARCHAR(255) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Column notes:
--   type            free text, but the admin UI offers common suggestions
--                   (Single/Twin/Double/Deluxe/Family Room, Suite, Dormitory)
--                   plus "Other" for anything custom.
--   status          manually set by an admin from the accommodations page —
--                   NOT derived automatically from the bookings table.
--   amenities       a JSON array of short strings, e.g. ["Wi-Fi","Air Conditioning"]
--   image_filename  just the filename; the file itself lives in
--                   admin/assets/uploads/accommodations/. NULL = no photo yet.
