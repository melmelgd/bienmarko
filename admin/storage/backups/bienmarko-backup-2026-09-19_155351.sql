-- Bien Marko's Pension House — Database Backup
-- Database: bienmarko
-- Generated: 2026-09-19 15:53:51

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table structure for `accommodation_categories`
-- ----------------------------
DROP TABLE IF EXISTS `accommodation_categories`;
CREATE TABLE `accommodation_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `accommodation_photos`
-- ----------------------------
DROP TABLE IF EXISTS `accommodation_photos`;
CREATE TABLE `accommodation_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `accommodation_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_accommodation` (`accommodation_id`),
  CONSTRAINT `fk_acc_photos_accommodation` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `accommodations`
-- ----------------------------
DROP TABLE IF EXISTS `accommodations`;
CREATE TABLE `accommodations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `type` varchar(100) NOT NULL DEFAULT '',
  `room_number` varchar(20) DEFAULT NULL,
  `capacity` smallint(5) unsigned NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('available','booked','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `description` text DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `image_filename` varchar(255) DEFAULT NULL,
  `tour_filename` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `category_id` int(10) unsigned DEFAULT NULL,
  `is_featured` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `admin_notifications`
-- ----------------------------
DROP TABLE IF EXISTS `admin_notifications`;
CREATE TABLE `admin_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned NOT NULL,
  `type` varchar(60) NOT NULL DEFAULT 'booking',
  `message` varchar(400) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking` (`booking_id`),
  KEY `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `admins`
-- ----------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_email` (`email`),
  KEY `fk_admins_created_by` (`created_by`),
  CONSTRAINT `fk_admins_created_by` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admins` (`id`, `name`, `email`, `password_hash`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'shayra', 'administrator@admin.com', '$2y$10$lZ9lT1ut8ajBLI4F3.i5fe8rLpJ.v7YArWPUMOSKQHRgVsT5rcipC', NULL, '2026-09-07 23:02:42', '2026-09-07 23:02:42');

-- ----------------------------
-- Table structure for `backups`
-- ----------------------------
DROP TABLE IF EXISTS `backups`;
CREATE TABLE `backups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(150) NOT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `bookings`
-- ----------------------------
DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(20) NOT NULL,
  `guest_id` int(10) unsigned DEFAULT NULL,
  `guest_name` varchar(200) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `payment_method` enum('cash','gcash') DEFAULT 'cash',
  `booking_type` enum('registered','guest') NOT NULL DEFAULT 'registered',
  `companion_names` text DEFAULT NULL,
  `accommodation_id` int(10) unsigned NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `guests_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_accommodation` (`accommodation_id`),
  KEY `idx_dates` (`check_in`,`check_out`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `expenses`
-- ----------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `category` varchar(40) NOT NULL DEFAULT 'Others',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `feedback`
-- ----------------------------
DROP TABLE IF EXISTS `feedback`;
CREATE TABLE `feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guest_id` int(10) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `category` enum('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
  `message` text NOT NULL,
  `is_read` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_published` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `guest_notifications`
-- ----------------------------
DROP TABLE IF EXISTS `guest_notifications`;
CREATE TABLE `guest_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guest_id` int(10) unsigned NOT NULL,
  `booking_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(60) NOT NULL DEFAULT 'payment',
  `message` varchar(400) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_booking` (`booking_id`),
  KEY `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `guests`
-- ----------------------------
DROP TABLE IF EXISTS `guests`;
CREATE TABLE `guests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `country` varchar(80) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `avatar_filename` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guests_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `guests` (`id`, `first_name`, `last_name`, `email`, `country`, `phone`, `avatar_filename`, `password_hash`, `created_at`, `updated_at`) VALUES ('1', 'nanaue', '', 'nanaue@aintshit.com', NULL, '+639542275504', NULL, '$2y$10$J3/QMnvXANB8Bx8VJGuWauidkfLkenWS6YrSYEItz4ququ6lW/kaC', '2026-09-19 12:55:02', '2026-09-19 12:55:02');

-- ----------------------------
-- Table structure for `inventory_items`
-- ----------------------------
DROP TABLE IF EXISTS `inventory_items`;
CREATE TABLE `inventory_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'Others',
  `quantity` int(10) unsigned NOT NULL DEFAULT 0,
  `min_stock_level` int(10) unsigned NOT NULL DEFAULT 0,
  `cost_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `expiration_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `inventory_logs`
-- ----------------------------
DROP TABLE IF EXISTS `inventory_logs`;
CREATE TABLE `inventory_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `change_qty` int(11) NOT NULL,
  `reason` enum('initial','restock','usage','adjustment') NOT NULL DEFAULT 'adjustment',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inv_log_item` (`item_id`),
  CONSTRAINT `fk_inv_log_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `login_attempts`
-- ----------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('user','admin') NOT NULL,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_role_email_time` (`role`,`email`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('1', 'user', 'admin@bienmarko.test', '::1', '2026-09-19 12:46:50');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('2', 'user', 'admin@bienmarko.test', '::1', '2026-09-19 12:47:06');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('3', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 12:48:52');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('4', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 12:48:53');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('5', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 12:48:53');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('6', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 12:48:53');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('7', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 12:49:04');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('8', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 21:49:41');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('9', 'user', 'shayra@bienmarko.test', '::1', '2026-09-19 21:49:52');
INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('10', 'user', 'admin@bienmarko.test', '::1', '2026-09-19 21:50:09');

-- ----------------------------
-- Table structure for `payments`
-- ----------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned NOT NULL,
  `room_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `extra_guest_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `other_charges` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(255) DEFAULT NULL,
  `total_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','gcash') NOT NULL DEFAULT 'cash',
  `payment_status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking` (`booking_id`),
  CONSTRAINT `fk_pay_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `system_settings`
-- ----------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `property_name` varchar(150) NOT NULL DEFAULT '',
  `contact_email` varchar(190) NOT NULL DEFAULT '',
  `contact_number` varchar(20) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `notify_new_booking` tinyint(1) NOT NULL DEFAULT 1,
  `notify_payment` tinyint(1) NOT NULL DEFAULT 1,
  `notify_low_inventory` tinyint(1) NOT NULL DEFAULT 0,
  `notify_guest_feedback` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`id`, `property_name`, `contact_email`, `contact_number`, `address`, `notify_new_booking`, `notify_payment`, `notify_low_inventory`, `notify_guest_feedback`, `updated_at`) VALUES ('1', '', '', '', '', '1', '1', '0', '0', '2026-09-19 21:51:58');

SET FOREIGN_KEY_CHECKS=1;
