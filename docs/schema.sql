-- ============================================================================
--  MALLENGKE · DATABASE SCHEMA  (schema version 15)
-- ============================================================================
--  Engine  : InnoDB / utf8mb4 / utf8mb4_unicode_ci
--  Source  : config/db.php  (the declared source of truth)
--
--  HOW TO USE THIS FILE
--    MySQL / MariaDB ..........  SOURCE docs/schema.sql;
--    MySQL Workbench ...........  Schema > SQL Schemas > <db> > SQL Editor,
--                                open this file, execute.
--    DBeaver ..................  right-click the database > SQL Scripts >
--                                SQL Script… , pick this file, execute.
--    dbForge Data Modeler .....  File > New Model from Database, then use this
--                                file as the DDL source (or paste it into a
--                                "Script" page).
--
--  TABLES ARE IN DEPENDENCY ORDER, so a single pass creates every foreign
--  key without a second pass.
--
--  CARDINALITY LEGEND (also in docs/erd.md)
--    ||--o{   one parent  ->  zero or more children   (NOT NULL foreign key)
--    o|--o{   0..1 parent->  zero or more children   (nullable foreign key)
--  Every child column below points at its parent; deletes cascade along
--  ownership (CASCADE) or preserve the bookkeeping column (SET NULL).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
--  users  —  vendor login accounts
--  A login row only carries credentials. Everything a market person is (name,
--  stall, permit, payments) lives in `vendors`.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(50)  NOT NULL,
  `email`          VARCHAR(190) NOT NULL,
  `password`       VARCHAR(255) NOT NULL,
  `remember_token` VARCHAR(64)  DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_remember` (`remember_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  admin_users  —  market office staff
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `email`         VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(120) NOT NULL,
  `role`          ENUM('superadmin','admin','moderator') NOT NULL DEFAULT 'admin',
  `avatar_color`  VARCHAR(20)  NOT NULL DEFAULT '#16a34a',
  `avatar_path`   VARCHAR(255) DEFAULT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  `last_ip`       VARCHAR(45)  DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_username` (`username`),
  UNIQUE KEY `uniq_admin_email` (`email`),
  KEY `idx_admin_role` (`role`),
  KEY `idx_admin_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  vendors  —  the market person. 1:1 in intent with `users`, 1:N as declared.
--  NOTE: `user_id` is indexed but NOT unique, so the database permits one
--  login to back several vendor rows. See docs/erd.md > "Declared vs intended".
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `vendors`;
CREATE TABLE `vendors` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              INT UNSIGNED DEFAULT NULL,
  `first_name`           VARCHAR(80)  NOT NULL,
  `middle_name`          VARCHAR(80)  DEFAULT NULL,
  `last_name`            VARCHAR(80)  NOT NULL,
  `birthdate`            DATE         NOT NULL,
  `gender`               ENUM('Male','Female','Other') NOT NULL,
  `civil_status`         ENUM('Single','Married','Widowed','Separated') NOT NULL,
  `contact_number`       VARCHAR(20)  NOT NULL,
  `email`                VARCHAR(190) DEFAULT NULL,
  `avatar_path`          VARCHAR(255) DEFAULT NULL,
  `address`              VARCHAR(255) NOT NULL,
  `business_name`        VARCHAR(120) DEFAULT NULL,
  `stall_number`         VARCHAR(30)  DEFAULT NULL,
  `market_section`       VARCHAR(60)  NOT NULL,
  `product_type`         VARCHAR(150) NOT NULL,
  `years_in_business`    INT UNSIGNED NOT NULL DEFAULT 0,
  `id_type`              VARCHAR(60)  NOT NULL,
  `id_number`            VARCHAR(60)  NOT NULL,
  `emergency_name`       VARCHAR(120) NOT NULL,
  `emergency_relationship` VARCHAR(60) NOT NULL,
  `emergency_contact`    VARCHAR(20)  NOT NULL,
  `status`               ENUM('Pending','Approved','Rejected','Suspended') NOT NULL DEFAULT 'Pending',
  `access_blocked`       TINYINT(1)   NOT NULL DEFAULT 0,
  `access_kind`          ENUM('none','suspended','removed') NOT NULL DEFAULT 'none',
  `access_note`          VARCHAR(500) DEFAULT NULL,
  `access_by`            VARCHAR(120) DEFAULT NULL,
  `access_at`            DATETIME     DEFAULT NULL,
  `remarks`              VARCHAR(255) DEFAULT NULL,
  `qr_token`             VARCHAR(64)  DEFAULT NULL,
  `permit_status`        ENUM('Valid','Pending','Expired','Suspended') NOT NULL DEFAULT 'Pending',
  `permit_expiry`        DATE         DEFAULT NULL,
  `payment_status`       ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
  `payment_due`          DATE         DEFAULT NULL,
  `last_payment_date`    DATE         DEFAULT NULL,
  `stall_status`         ENUM('Occupied','Available') NOT NULL DEFAULT 'Occupied',
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vendors_qr` (`qr_token`),
  UNIQUE KEY `uniq_vendors_stall` (`stall_number`),
  KEY `idx_vendors_user` (`user_id`),
  KEY `idx_vendors_status` (`status`),
  KEY `idx_vendors_section_stall` (`market_section`, `stall_number`),
  KEY `idx_vendors_permit` (`permit_status`, `permit_expiry`),
  KEY `idx_vendors_payment` (`payment_status`, `payment_due`),
  CONSTRAINT `chk_vendors_stall`   CHECK (CHAR_LENGTH(TRIM(stall_number)) > 0),
  CONSTRAINT `chk_vendors_section` CHECK (CHAR_LENGTH(TRIM(market_section)) > 0),
  CONSTRAINT `fk_vendors_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  customers  —  shoppers. Leaf entity: it owns nothing and points at nothing.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`     VARCHAR(80)  NOT NULL,
  `last_name`      VARCHAR(80)  NOT NULL,
  `email`          VARCHAR(190) NOT NULL,
  `phone`          VARCHAR(20)  DEFAULT NULL,
  `address`        VARCHAR(255) DEFAULT NULL,
  `avatar_path`    VARCHAR(255) DEFAULT NULL,
  `username`       VARCHAR(50)  NOT NULL,
  `password`       VARCHAR(255) NOT NULL,
  `remember_token` VARCHAR(64)  DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customers_username` (`username`),
  UNIQUE KEY `uniq_customers_email` (`email`),
  KEY `idx_customers_remember` (`remember_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  vendor_conversations  —  one vendor <-> one vendor thread.
--  Stored as a canonical pair (vendor_a_id < vendor_b_id, CHECK-enforced) so
--  UNIQUE(a,b) is a real uniqueness guarantee regardless of who opened the chat.
--  Vendors therefore hold TWO roles: they own a thread from both sides.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `vendor_conversations`;
CREATE TABLE `vendor_conversations` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_a_id`          INT UNSIGNED NOT NULL,
  `vendor_b_id`          INT UNSIGNED NOT NULL,
  `last_message_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_preview` VARCHAR(160) DEFAULT NULL,
  `unread_a`             INT UNSIGNED NOT NULL DEFAULT 0,
  `unread_b`             INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vc_pair` (`vendor_a_id`, `vendor_b_id`),
  KEY `idx_vc_a` (`vendor_a_id`, `last_message_at`),
  KEY `idx_vc_b` (`vendor_b_id`, `last_message_at`),
  CONSTRAINT `chk_vc_order` CHECK (`vendor_a_id` < `vendor_b_id`),
  CONSTRAINT `fk_vc_a` FOREIGN KEY (`vendor_a_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vc_b` FOREIGN KEY (`vendor_b_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  vendor_messages  —  the posts inside a vendor <-> vendor thread. 1:N, CASCADE.
--  The sender is also a vendors FK, so a message names its author and its thread.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `vendor_messages`;
CREATE TABLE `vendor_messages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id`  INT UNSIGNED NOT NULL,
  `sender_vendor_id` INT UNSIGNED NOT NULL,
  `body`             TEXT NOT NULL,
  `is_read`          TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vm_conv` (`conversation_id`, `id`),
  KEY `idx_vm_unread` (`conversation_id`, `sender_vendor_id`, `is_read`),
  CONSTRAINT `chk_vm_body` CHECK (CHAR_LENGTH(TRIM(body)) > 0),
  CONSTRAINT `fk_vm_conv` FOREIGN KEY (`conversation_id`)
    REFERENCES `vendor_conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vm_sender` FOREIGN KEY (`sender_vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  announcements  —  market-wide bulletin. Fan-out target for notifications.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`            VARCHAR(180) NOT NULL,
  `body`             TEXT NOT NULL,
  `category`         VARCHAR(40) NOT NULL DEFAULT 'announcement',
  `audience`         ENUM('all','section') NOT NULL DEFAULT 'all',
  `section`          VARCHAR(60) DEFAULT NULL,
  `is_pinned`        TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `published_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`       DATETIME DEFAULT NULL,
  `recipient_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `read_count`       INT UNSIGNED NOT NULL DEFAULT 0,
  `sms_sent`         INT UNSIGNED NOT NULL DEFAULT 0,
  `sms_failed`       INT UNSIGNED NOT NULL DEFAULT 0,
  `sms_skipped`      INT UNSIGNED NOT NULL DEFAULT 0,
  `sms_sent_at`      DATETIME DEFAULT NULL,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_by_name`  VARCHAR(160) NOT NULL DEFAULT 'Market Office',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_feed` (`is_active`, `is_pinned`, `published_at`),
  KEY `idx_ann_expiry` (`is_active`, `expires_at`),
  KEY `idx_ann_audience` (`audience`, `section`),
  KEY `idx_ann_author` (`created_by`),
  CONSTRAINT `fk_ann_admin` FOREIGN KEY (`created_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_ann_title` CHECK (CHAR_LENGTH(TRIM(title)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  notifications  —  one inbox row per recipient.
--  sender_id / recipient_id are POLYMORPHIC (the *_type column says which table
--  the id belongs to), so they cannot be foreign keys. parent_id and
--  announcement_id are real FKs.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_type`     ENUM('admin','vendor','customer','system') NOT NULL DEFAULT 'system',
  `sender_id`       INT UNSIGNED DEFAULT NULL,
  `sender_name`     VARCHAR(160) NOT NULL DEFAULT 'System',
  `recipient_type`  ENUM('admin','vendor','customer') NOT NULL DEFAULT 'vendor',
  `recipient_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `parent_id`       INT UNSIGNED DEFAULT NULL,
  `announcement_id` INT UNSIGNED DEFAULT NULL,
  `category`        VARCHAR(40) NOT NULL DEFAULT 'general',
  `title`           VARCHAR(180) NOT NULL DEFAULT '',
  `body`            TEXT DEFAULT NULL,
  `link`            VARCHAR(255) DEFAULT NULL,
  `is_read`         TINYINT(1) NOT NULL DEFAULT 0,
  `read_at`         DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_inbox` (`recipient_type`, `recipient_id`, `is_read`, `id`),
  KEY `idx_notif_feed` (`recipient_type`, `recipient_id`, `created_at`, `id`),
  KEY `idx_notif_sender` (`sender_type`, `sender_id`),
  KEY `idx_notif_parent` (`parent_id`),
  KEY `idx_notif_announcement` (`announcement_id`, `is_read`),
  KEY `idx_notif_category` (`category`),
  CONSTRAINT `chk_notif_title` CHECK (CHAR_LENGTH(TRIM(title)) > 0),
  CONSTRAINT `fk_notif_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `notifications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notif_announcement` FOREIGN KEY (`announcement_id`)
    REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  admin_activity_log  —  audit trail. 1 admin -> 0..N entries, CASCADE.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `admin_activity_log`;
CREATE TABLE `admin_activity_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `action`     VARCHAR(80) NOT NULL,
  `target`     VARCHAR(120) DEFAULT NULL,
  `details`    TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_admin` (`admin_id`, `created_at`),
  KEY `idx_log_action` (`action`, `created_at`),
  KEY `idx_log_created` (`created_at`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admin_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  stall_swap_requests  —  one vendor asks the office to trade stalls.
--  Vendors appear TWICE: as requester and as target. The stall/section values
--  are a snapshot of the moment of the request, so the row stays a faithful
--  audit record even after the trade.
--  Cardinality: vendors 1 -- 0..N stall_swap_requests, twice over.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stall_swap_requests`;
CREATE TABLE `stall_swap_requests` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requester_vendor_id` INT UNSIGNED NOT NULL,
  `requester_stall`    VARCHAR(30) NOT NULL DEFAULT '',
  `requester_section`  VARCHAR(60) DEFAULT NULL,
  `target_vendor_id`   INT UNSIGNED NOT NULL,
  `target_stall`       VARCHAR(30) NOT NULL DEFAULT '',
  `target_section`     VARCHAR(60) DEFAULT NULL,
  `reason`             VARCHAR(500) DEFAULT NULL,
  `conversation_id`    INT UNSIGNED DEFAULT NULL,
  `status`             ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `decided_by`         INT UNSIGNED DEFAULT NULL,
  `decided_by_name`    VARCHAR(160) DEFAULT NULL,
  `decision_note`      VARCHAR(500) DEFAULT NULL,
  `decided_at`         DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_swap_queue` (`status`, `id`),
  KEY `idx_swap_req` (`requester_vendor_id`, `status`, `id`),
  KEY `idx_swap_tgt` (`target_vendor_id`, `status`, `id`),
  KEY `idx_swap_pair` (`requester_vendor_id`, `target_vendor_id`, `status`),
  CONSTRAINT `chk_swap_distinct` CHECK (`requester_vendor_id` <> `target_vendor_id`),
  CONSTRAINT `chk_swap_decided`  CHECK (`status` = 'Pending' OR `decided_at` IS NOT NULL),
  CONSTRAINT `fk_swap_requester` FOREIGN KEY (`requester_vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_swap_target` FOREIGN KEY (`target_vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_swap_conv` FOREIGN KEY (`conversation_id`)
    REFERENCES `vendor_conversations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_swap_admin` FOREIGN KEY (`decided_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  stall_applications  —  one vendor asks the office for a stall.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stall_applications`;
CREATE TABLE `stall_applications` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`       INT UNSIGNED NOT NULL,
  `requested_stall` VARCHAR(30) DEFAULT NULL,
  `request_note`    VARCHAR(500) DEFAULT NULL,
  `status`          ENUM('Pending','Assigned','Declined') NOT NULL DEFAULT 'Pending',
  `assigned_stall`  VARCHAR(30) DEFAULT NULL,
  `decision_note`   VARCHAR(500) DEFAULT NULL,
  `decided_by`      INT UNSIGNED DEFAULT NULL,
  `decided_by_name` VARCHAR(120) DEFAULT NULL,
  `decided_at`      DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sa_status` (`status`, `created_at`),
  KEY `idx_sa_decider` (`decided_by`),
  -- `idx_sa_vendor` is deliberately the WHOLE index: its leading column is
  -- `vendor_id`, so InnoDB resolves fk_sa_vendor against it and the
  -- "this vendor's requests, newest first" lookup at the same time.
  KEY `idx_sa_vendor` (`vendor_id`, `status`),
  CONSTRAINT `fk_sa_vendor` FOREIGN KEY (`vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sa_admin` FOREIGN KEY (`decided_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_sa_decided`  CHECK (`status` = 'Pending' OR `decided_at` IS NOT NULL),
  CONSTRAINT `chk_sa_assigned` CHECK (`status` <> 'Assigned' OR `assigned_stall` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  vendor_reports  —  one vendor reports another to the office.
--  Both ends are vendors, so vendors -> 0..N reports twice over.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `vendor_reports`;
CREATE TABLE `vendor_reports` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_vendor_id` INT UNSIGNED NOT NULL,
  `reported_vendor_id` INT UNSIGNED NOT NULL,
  `reason`             VARCHAR(60) NOT NULL,
  `details`            TEXT NOT NULL,
  `status`             ENUM('Open','Reviewed','Dismissed') NOT NULL DEFAULT 'Open',
  `admin_note`         VARCHAR(500) DEFAULT NULL,
  `reviewed_by`        INT UNSIGNED DEFAULT NULL,
  `reviewed_by_name`   VARCHAR(120) DEFAULT NULL,
  `reviewed_at`        DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vr_status` (`status`, `created_at`),
  KEY `idx_vr_reporter` (`reporter_vendor_id`, `status`),
  KEY `idx_vr_reported` (`reported_vendor_id`, `status`),
  CONSTRAINT `chk_vr_distinct` CHECK (`reporter_vendor_id` <> `reported_vendor_id`),
  CONSTRAINT `chk_vr_details`  CHECK (CHAR_LENGTH(TRIM(details)) > 0),
  CONSTRAINT `fk_vr_reporter` FOREIGN KEY (`reporter_vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vr_reported` FOREIGN KEY (`reported_vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vr_reviewer` FOREIGN KEY (`reviewed_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  admin_messages  —  one vendor <-> market office thread, both directions.
--  `direction` replaces a conversation header: the office is neither a vendor
--  nor a customer, so it gets its own single-thread-per-vendor table.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `admin_messages`;
CREATE TABLE `admin_messages` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`       INT UNSIGNED NOT NULL,
  `admin_id`        INT UNSIGNED DEFAULT NULL,
  `direction`       ENUM('vendor','admin') NOT NULL DEFAULT 'vendor',
  `body`            TEXT NOT NULL,
  `stall_requested` VARCHAR(30) DEFAULT NULL,
  `is_read`         TINYINT(1) NOT NULL DEFAULT 0,
  `read_at`         DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_am_vendor` (`vendor_id`, `created_at`),
  KEY `idx_am_inbox` (`direction`, `is_read`, `created_at`),
  CONSTRAINT `chk_am_body` CHECK (CHAR_LENGTH(TRIM(body)) > 0),
  CONSTRAINT `fk_am_vendor` FOREIGN KEY (`vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_am_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  schema_meta  —  internal bookkeeping (schema version + last migration).
--  Not part of the domain model; kept here so a dump is self-contained.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `schema_meta`;
CREATE TABLE `schema_meta` (
  `meta_key`   VARCHAR(64) NOT NULL,
  `meta_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  POST-LOAD SEED (matches mallengke_seed() in config/db.php)
-- ============================================================================
INSERT INTO `schema_meta` (`meta_key`, `meta_value`) VALUES
  ('schema_version', '15'),
  ('last_migration', 'schema_version=15 loaded from docs/schema.sql');

INSERT INTO `admin_users` (`username`,`email`,`password_hash`,`full_name`,`role`,`avatar_color`) VALUES
  ('admin',     'admin@mallengke.local',     '$2y$10$PLACEHOLDER_admin_Admin@123',     'Market Administrator', 'superadmin', '#16a34a'),
  ('moderator', 'moderator@mallengke.local', '$2y$10$PLACEHOLDER_mod_Mod@123',       'Market Moderator',      'moderator', '#0ea5e9');
-- NOTE: the two placeholder hashes above are NOT valid bcrypt. They exist so
-- the modeler import has one row per table. Passwords are re-hashed on first
-- sign-in; seed real ones by running the app once against an empty database.
