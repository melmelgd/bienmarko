-- Bien Marko's Pension House — Database Backup
-- Database: bienmarko
-- Generated: 2026-11-12 02:29:05

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
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('26', '7', 'f8f47424c7d0ba24cf27f9eedb117b77.jpg', '0', '2026-10-28 21:37:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('27', '7', '35334dd7064b4fd8f1c6ddd10bc521f5.jpg', '1', '2026-10-28 21:37:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('28', '7', 'ad7ef770a5194b0aa5dea0a477f1c881.jpg', '2', '2026-10-28 21:37:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('29', '7', '2a076b9e059ceaa724ada8e7d6b7eb41.jpg', '3', '2026-10-28 21:37:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('30', '7', 'dcf474d678f6f1d47d962b1b91bd22fe.jpg', '4', '2026-10-28 21:37:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('31', '7', '7fe0a205c7ace8425e121340d6ab3276.jpg', '5', '2026-10-28 21:37:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('32', '8', '8d26317669197ddf8ac5f21dbbf78cea.jpg', '0', '2026-10-28 21:40:12');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('33', '8', '5c39764c8b4cc5b437c01342a171f732.jpg', '1', '2026-10-28 21:40:12');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('34', '8', 'e64bee5726c7dff181f165ec5edcf1bf.jpg', '2', '2026-10-28 21:40:12');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('35', '8', 'faf79b25b574242767afc8147b7581c8.jpg', '3', '2026-10-28 21:40:12');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('36', '8', '16efe24c0cdb4cad96fda301b2d81214.jpg', '4', '2026-10-28 21:40:12');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('37', '8', 'dd473c7276f5ad8cfd6c95a24308b0ba.jpg', '5', '2026-10-28 21:40:12');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('38', '9', '85f260cd915409e3b2849ed8adcc8bff.jpg', '0', '2026-10-28 21:41:24');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('39', '9', '302caf237d4ad3473482cb8053861bef.jpg', '1', '2026-10-28 21:41:24');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('40', '9', 'eb6ac82365fe745d817beaa7324fa9b7.jpg', '2', '2026-10-28 21:41:24');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('41', '9', '7ba5be3149e2592e94101a4fcd848dca.jpg', '3', '2026-10-28 21:41:24');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('42', '10', '9f81274abd2d6ea7c09650f6486ef92e.jpg', '0', '2026-10-28 22:16:04');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('43', '10', 'fa2ff75ce9c054ec03670611750789d1.jpg', '1', '2026-10-28 22:16:04');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('44', '10', '8e9f6b2dd86bcf11ee8225f44129ed77.jpg', '2', '2026-10-28 22:16:04');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('45', '11', 'a4b250696de836d119c6ef5f8b621fad.jpg', '0', '2026-10-28 22:18:59');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('46', '12', '728df8d584f0c6c0dab60a98dd1fb75e.jpg', '0', '2026-10-28 22:19:43');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('47', '14', 'd29f065919f3504e15d1360fd98e991c.jpg', '0', '2026-10-28 22:22:15');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('48', '14', '9da9883f2efdcfa9a33f23f257bac1db.jpg', '1', '2026-10-28 22:22:15');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('49', '14', 'cdc670a5e17588111ee341577867fec4.jpg', '2', '2026-10-28 22:22:15');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('50', '14', 'b48a7523f0c471cf08077c6831120fc2.jpg', '3', '2026-10-28 22:22:15');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('51', '14', 'ef3b5d10faabc5f5e2be38b8faa5721d.jpg', '4', '2026-10-28 22:22:15');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('52', '14', 'cfbb1ef92ecbad3c05ddf3873713796a.jpg', '5', '2026-10-28 22:22:15');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('53', '15', '4f0e09da3567ff78add68a716f44c567.jpg', '0', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('54', '15', '12570dbe7c20593cbbe9b72a5731edf9.jpg', '1', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('55', '15', 'c2617405869989dd112939861c9fca32.jpg', '2', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('56', '15', '8ae6fca8abef6a25f105fb2eef77c1dc.jpg', '3', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('57', '15', 'fd6f0d253e313849ae38c113e41c131b.jpg', '4', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('58', '15', '05c86255d1fa502815e82ba785434f54.jpg', '5', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('59', '15', 'c273ddbdcbe3d8b88b7c1217a1068b59.jpg', '6', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('60', '15', 'e315aa5087246ab8eca163799e9a7ea7.jpg', '7', '2026-10-28 22:25:28');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('61', '16', '398327d9046b013666346c8f3def2ae4.jpg', '0', '2026-11-10 20:15:33');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('62', '16', '4d3645c3782f016b15aa54d41e797b8c.jpg', '1', '2026-11-10 20:15:33');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('63', '16', 'd426985e524859fd42790e6e383b0a5a.jpg', '2', '2026-11-10 20:15:33');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('64', '17', 'fb0fdc13a1fa6e70165db213df641733.jpg', '0', '2026-11-10 20:19:53');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('65', '17', '4e7303794ab0a22fc613ee4754330abb.jpg', '1', '2026-11-10 20:19:53');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('66', '17', '136cf3b834c8fa79ae607ded6cdf4f58.jpg', '2', '2026-11-10 20:19:53');
INSERT INTO `accommodation_photos` (`id`, `accommodation_id`, `filename`, `sort_order`, `created_at`) VALUES ('67', '17', '2b4265675446d13f811c85654b12f230.jpg', '3', '2026-11-10 20:19:53');

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
  `bed_config` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_featured` (`is_featured`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('7', 'Barkada Room', 'Room', 'room 1', '8', '2000.00', 'available', NULL, '[\"Swimming Pool\"]', 'dcf474d678f6f1d47d962b1b91bd22fe.jpg', '2eb8b9d9420e6e84e8d7d11a229a29bb.html', '2026-10-28 21:37:49', '2026-10-28 22:13:44', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('8', 'Standard Room', 'Room', 'room 2', '3', '1500.00', 'available', NULL, '[]', '8d26317669197ddf8ac5f21dbbf78cea.jpg', '58097ec3f22a346739c09ccf477221d7.html', '2026-10-28 21:39:59', '2026-10-28 22:14:28', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('9', 'Couples Room', 'Room', 'room 3', '2', '1000.00', 'available', NULL, '[]', '85f260cd915409e3b2849ed8adcc8bff.jpg', '7373330a6736634b85ed67a48d569f6a.html', '2026-10-28 21:41:15', '2026-10-28 22:14:07', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('10', 'POOL', 'Swimming Pool', NULL, '60', '150.00', 'available', '1000 for guests less than 10 person', '[\"6hours\"]', '9f81274abd2d6ea7c09650f6486ef92e.jpg', '22fee9fdc099cf3a6651f495a12a7a94.html', '2026-10-28 22:15:57', '2026-11-06 11:30:43', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('11', 'Nepa Hut 1', 'Cottage', NULL, '30', '5.00', 'available', NULL, '[]', 'a4b250696de836d119c6ef5f8b621fad.jpg', NULL, '2026-10-28 22:18:37', '2026-11-06 11:29:30', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('12', 'Nepa Hut 2', 'Cottage', NULL, '30', '5.00', 'available', NULL, '[]', '24bf78c740a55b3962752fd51f487d68.jpg', NULL, '2026-10-28 22:19:37', '2026-11-06 11:29:20', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('13', 'Neppa Hut 3', 'Cottage', NULL, '30', '5.00', 'available', NULL, '[]', '8809a622e3b23b996de7ed67757dcf6f.jpg', NULL, '2026-10-28 22:20:12', '2026-11-06 11:29:38', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('14', 'Standard Room', 'Room', 'room 4', '2', '1500.00', 'available', NULL, '[]', 'b48a7523f0c471cf08077c6831120fc2.jpg', '628c47ff8a3084fc0f2f37ecf61f2b59.html', '2026-10-28 22:22:04', '2026-10-28 22:22:31', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('15', 'Family Room', 'Room', 'room 5', '5', '2000.00', 'available', NULL, '[]', 'fd6f0d253e313849ae38c113e41c131b.jpg', '853e42c24f3829330443d37e5bc263ce.html', '2026-10-28 22:24:57', '2026-10-28 22:25:50', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('16', 'Veranda', 'Event Hall', NULL, '30', '1000.00', 'available', 'for Small Meetings outdoor', '[]', '4d3645c3782f016b15aa54d41e797b8c.jpg', 'b7a8c8f65f48d871f3d648c8543d22bf.html', '2026-11-10 20:14:17', '2026-11-10 20:19:32', NULL, '0', NULL);
INSERT INTO `accommodations` (`id`, `name`, `type`, `room_number`, `capacity`, `price`, `status`, `description`, `amenities`, `image_filename`, `tour_filename`, `created_at`, `updated_at`, `category_id`, `is_featured`, `bed_config`) VALUES ('17', 'Kopi Ta (Cafe Venue)', 'Event Hall', NULL, '30', '6000.00', 'available', NULL, '[\"12 hrs\"]', 'fb0fdc13a1fa6e70165db213df641733.jpg', '4c996e1743f48d819c3e2a9bab05c806.html', '2026-11-10 20:18:52', '2026-11-10 20:20:17', NULL, '0', NULL);

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

INSERT INTO `admins` (`id`, `name`, `email`, `password_hash`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'shayra', 'administrator@admin.com', '$2y$10$lZ9lT1ut8ajBLI4F3.i5fe8rLpJ.v7YArWPUMOSKQHRgVsT5rcipC', NULL, '2026-09-07 23:02:42', '2026-10-05 18:08:38');

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
  `nationality` varchar(80) DEFAULT NULL,
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
  `checked_in_at` datetime DEFAULT NULL,
  `checked_out_at` datetime DEFAULT NULL,
  `pool_session` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_accommodation` (`accommodation_id`),
  KEY `idx_dates` (`check_in`,`check_out`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `email_log`
-- ----------------------------
DROP TABLE IF EXISTS `email_log`;
CREATE TABLE `email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned DEFAULT NULL,
  `kind` varchar(30) NOT NULL DEFAULT 'confirmation',
  `recipient` varchar(190) NOT NULL DEFAULT '',
  `subject` varchar(255) NOT NULL DEFAULT '',
  `status` enum('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  `gmail_message_id` varchar(64) DEFAULT NULL,
  `error` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_kind` (`booking_id`,`kind`,`status`),
  KEY `idx_created` (`created_at`)
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
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `inventory_item_id` int(10) unsigned DEFAULT NULL,
  `inventory_log_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `feedback`
-- ----------------------------
DROP TABLE IF EXISTS `feedback`;
CREATE TABLE `feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guest_id` int(10) unsigned NOT NULL,
  `booking_id` int(10) unsigned DEFAULT NULL,
  `accommodation_id` int(10) unsigned DEFAULT NULL,
  `accommodation_type` varchar(100) DEFAULT NULL,
  `accommodation_name` varchar(150) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `category` enum('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
  `message` text NOT NULL,
  `is_read` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_published` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feedback_booking` (`booking_id`),
  KEY `idx_guest` (`guest_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `feedback_reminders`
-- ----------------------------
DROP TABLE IF EXISTS `feedback_reminders`;
CREATE TABLE `feedback_reminders` (
  `booking_id` int(10) unsigned NOT NULL,
  `notified_at` datetime DEFAULT NULL,
  `emailed_at` datetime DEFAULT NULL,
  `email_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `gmail_oauth`
-- ----------------------------
DROP TABLE IF EXISTS `gmail_oauth`;
CREATE TABLE `gmail_oauth` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `sender_email` varchar(190) NOT NULL DEFAULT '',
  `refresh_token_enc` text DEFAULT NULL,
  `access_token_enc` text DEFAULT NULL,
  `access_expires_ts` int(10) unsigned NOT NULL DEFAULT 0,
  `scope` varchar(500) NOT NULL DEFAULT '',
  `status` enum('disconnected','connected','needs_reauth') NOT NULL DEFAULT 'disconnected',
  `last_error` varchar(500) DEFAULT NULL,
  `connected_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `gmail_oauth` (`id`, `sender_email`, `refresh_token_enc`, `access_token_enc`, `access_expires_ts`, `scope`, `status`, `last_error`, `connected_at`, `updated_at`) VALUES ('1', 'mitchdamayo22@gmail.com', 'v1:PhAy4nCdyEz1/m5rhNx7LgoBg/wqBiA1gmw/E79a6s/PRU/L/lKMhg6xpFhIVIzdZ2LJuTiiJBekh/awZN3sKJMz6b1yMN/JUvNkE5BE3vJkj3ZbR2fyC8VjDQjtQo3lRsycmBNp8niQcgGO2a7w02oQGibfSjxnObPtBXYMl4HmbqM=', 'v1:UYL4cA7pH3Yn3ZpjudmT3eUIW8U/59EfliWdR+GCVoCILAg3OUIZuqsmu+XA0OwPl3kgoPzuAISYJYrtI9yZfiEwoPU0bh2Azz2zUMsjoDOKMQoirLGpx8qcKVq6MdekMWOHTsxyITslAsCz1PtQX+bT2R6fvyj/6xE6QjWmtjRC2NuWWZvunWl+1dYrXg6Smf7kUj0TvmiV26sQ5ghniddfwhj+vFdBAYIouL7PpXxYh0kr1KWLH1a+QwazKwBRgadDd2gVcLO6N4fvN0tJ3Z3Pk25b0jqzi3sl134aGZYvIREZG1ty5Jp8BudYYka1XcRDGhpVHk0e0FEf4aB1uCHCIgiTUnyIY/fMsRQ8gA48wCXStey2Da9O', '1794056253', 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email openid', 'connected', NULL, '2026-11-07 14:17:08', '2026-11-07 19:57:34');

-- ----------------------------
-- Table structure for `guest_notifications`
-- ----------------------------
DROP TABLE IF EXISTS `guest_notifications`;
CREATE TABLE `guest_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guest_id` int(10) unsigned NOT NULL,
  `booking_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(60) NOT NULL DEFAULT 'payment',
  `remind_key` varchar(40) DEFAULT NULL,
  `message` varchar(400) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_notice` (`booking_id`,`type`,`remind_key`),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for `inventory_items`
-- ----------------------------
DROP TABLE IF EXISTS `inventory_items`;
CREATE TABLE `inventory_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'Others',
  `quantity` int(10) unsigned NOT NULL DEFAULT 0,
  `total_quantity` int(10) unsigned NOT NULL DEFAULT 0,
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `login_attempts` (`id`, `role`, `email`, `ip_address`, `created_at`) VALUES ('1', 'user', 'damayomitchbsitpittabango@gmail.com', '::1', '2026-11-09 11:01:00');

-- ----------------------------
-- Table structure for `password_resets`
-- ----------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `phone` varchar(20) NOT NULL,
  `otp_hash` char(64) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pr_phone` (`phone`),
  KEY `idx_pr_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `discount_percent` decimal(5,2) DEFAULT NULL,
  `discount_guests` int(10) unsigned DEFAULT NULL,
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
-- Table structure for `sms_log`
-- ----------------------------
DROP TABLE IF EXISTS `sms_log`;
CREATE TABLE `sms_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) unsigned DEFAULT NULL,
  `guest_id` int(10) unsigned DEFAULT NULL,
  `kind` varchar(30) NOT NULL,
  `dedupe_key` varchar(60) NOT NULL DEFAULT '',
  `phone` varchar(20) DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_kind` (`booking_id`,`kind`),
  KEY `idx_created` (`created_at`)
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

INSERT INTO `system_settings` (`id`, `property_name`, `contact_email`, `contact_number`, `address`, `notify_new_booking`, `notify_payment`, `notify_low_inventory`, `notify_guest_feedback`, `updated_at`) VALUES ('1', 'Bien Marko\'s Pension House', 'shaidiamos@gmail.com', '+639542275504', '1st Ave Street, Poblacion, Tabango, Philippines', '1', '1', '0', '0', '2026-10-30 10:11:15');

SET FOREIGN_KEY_CHECKS=1;
