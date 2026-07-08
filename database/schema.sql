-- =============================================================================
-- BienMarko — Database Schema
-- -----------------------------------------------------------------------------
-- Run this against an empty database (see README.md for how to create one).
-- Safe to import via phpMyAdmin or the mysql CLI — it only creates tables
-- and seeds two demo accounts, nothing that needs elevated privileges.
--
-- Three tables:
--   guests          Guest-side login accounts (was the DEMO_GUESTS array in
--                    user/api/auth.php). Column names match the fields
--                    already used on user/profile.php (firstName, lastName,
--                    email, phone) so wiring that page up to real data later
--                    is a straight mapping.
--   admins           Staff-side login accounts (was the DEMO_ADMINS array in
--                    admin/api/auth.php). created_by tracks which admin
--                    created which — nullable, since the first admin(s)
--                    weren't created by anyone through the app.
--   login_attempts   Failed-login log used for rate limiting. Replaces the
--                    session-based counter from the previous version, so a
--                    lockout now survives a cleared session/cookie and works
--                    the same regardless of which browser or device someone
--                    is attempting from.
-- =============================================================================

CREATE TABLE IF NOT EXISTS guests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name     VARCHAR(100) NOT NULL,
    last_name      VARCHAR(100) NOT NULL,
    email          VARCHAR(190) NOT NULL,
    phone          VARCHAR(30)  NULL,
    password_hash  VARCHAR(255) NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guests_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_email (email),
    CONSTRAINT fk_admins_created_by FOREIGN KEY (created_by)
        REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role        ENUM('user','admin') NOT NULL,
    email       VARCHAR(190) NOT NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_role_email_time (role, email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Demo seed data — the same two accounts the placeholder arrays used before.
-- Safe to delete once real accounts exist; nothing else depends on these
-- specific rows. Passwords below are already bcrypt-hashed; the plaintext
-- is only in this comment for local testing:
--   guest: shayra@bienmarko.test / Guest@123
--   admin: admin@bienmarko.test  / Admin@123
-- -----------------------------------------------------------------------------

INSERT INTO guests (first_name, last_name, email, phone, password_hash)
SELECT 'Shayra', 'Walters', 'shayra@bienmarko.test', NULL,
       '$2y$10$YuBSCxKFv146MFJS9ZCI/epeFzgGsLOwhVYqrAMqL9DVjviMKyd/O'
WHERE NOT EXISTS (SELECT 1 FROM guests WHERE email = 'shayra@bienmarko.test');

INSERT INTO admins (name, email, password_hash, created_by)
SELECT 'Marko Villanueva', 'admin@bienmarko.test',
       '$2y$10$cBgAwAbeiPLpxbYITOvTqOfWfOIxFU8hVR8nBzyoQfk4WDmB7ZkHS', NULL
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE email = 'admin@bienmarko.test');
