-- ============================================================================
--  MALLENGKE · ALL TABLES
-- ---------------------------------------------------------------------------
--  Every table of the `mollengke` database, in dependency order, so one
--  pass creates all the foreign keys.
--
--  HOW TO IMPORT
--    MySQL / MariaDB ..........  mysql -u root < db/file.sql
--    MySQL Workbench ...........  open this file in the SQL Editor, execute.
--    DBeaver ..................  right-click the database > SQL Scripts >
--                                SQL Script… , pick this file, execute.
--    phpMyAdmin ...............  Import tab > choose this file > Go.
--
--  The tables are created with IF NOT EXISTS, so importing this file never
--  drops or overwrites data that is already there.
--
--  Engine : InnoDB · utf8mb4 · utf8mb4_unicode_ci
--  Schema : version 15
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `mollengke`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `mollengke`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remember_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_remember` (`remember_token`)
) ENGINE=InnoDB AUTO_INCREMENT=1297 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `role` enum('superadmin','admin','moderator') NOT NULL DEFAULT 'admin',
  `avatar_color` varchar(20) NOT NULL DEFAULT '#16a34a',
  `last_login` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_username` (`username`),
  UNIQUE KEY `uniq_admin_email` (`email`),
  KEY `idx_admin_role` (`role`),
  KEY `idx_admin_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `birthdate` date NOT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated') NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `business_name` varchar(120) DEFAULT NULL,
  `stall_number` varchar(30) DEFAULT NULL,
  `market_section` varchar(60) NOT NULL,
  `product_type` varchar(150) NOT NULL,
  `years_in_business` int(10) unsigned NOT NULL DEFAULT 0,
  `id_type` varchar(60) NOT NULL,
  `id_number` varchar(60) NOT NULL,
  `emergency_name` varchar(120) NOT NULL,
  `emergency_relationship` varchar(60) NOT NULL,
  `emergency_contact` varchar(20) NOT NULL,
  `status` enum('Pending','Approved','Rejected','Suspended') NOT NULL DEFAULT 'Pending',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `qr_token` varchar(64) DEFAULT NULL,
  `permit_status` enum('Valid','Pending','Expired','Suspended') NOT NULL DEFAULT 'Pending',
  `permit_expiry` date DEFAULT NULL,
  `payment_status` enum('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
  `payment_due` date DEFAULT NULL,
  `last_payment_date` date DEFAULT NULL,
  `stall_status` enum('Occupied','Available') NOT NULL DEFAULT 'Occupied',
  `access_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `access_note` varchar(500) DEFAULT NULL,
  `access_by` varchar(120) DEFAULT NULL,
  `access_at` datetime DEFAULT NULL,
  `access_kind` enum('none','suspended','removed') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vendors_stall` (`stall_number`),
  UNIQUE KEY `uniq_vendors_qr` (`qr_token`),
  KEY `idx_vendors_user` (`user_id`),
  KEY `idx_vendors_status` (`status`),
  KEY `idx_vendors_section_stall` (`market_section`,`stall_number`),
  KEY `idx_vendors_permit` (`permit_status`,`permit_expiry`),
  KEY `idx_vendors_payment` (`payment_status`,`payment_due`),
  CONSTRAINT `fk_vendors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_vendors_stall` CHECK (char_length(trim(`stall_number`)) > 0),
  CONSTRAINT `chk_vendors_section` CHECK (char_length(trim(`market_section`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1291 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customers_username` (`username`),
  UNIQUE KEY `uniq_customers_email` (`email`),
  KEY `idx_customers_remember` (`remember_token`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_a_id` int(10) unsigned NOT NULL,
  `vendor_b_id` int(10) unsigned NOT NULL,
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_message_preview` varchar(160) DEFAULT NULL,
  `unread_a` int(10) unsigned NOT NULL DEFAULT 0,
  `unread_b` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vc_pair` (`vendor_a_id`,`vendor_b_id`),
  KEY `idx_vc_a` (`vendor_a_id`,`last_message_at`),
  KEY `idx_vc_b` (`vendor_b_id`,`last_message_at`),
  CONSTRAINT `fk_vc_a` FOREIGN KEY (`vendor_a_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vc_b` FOREIGN KEY (`vendor_b_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_vc_order` CHECK (`vendor_a_id` < `vendor_b_id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `sender_vendor_id` int(10) unsigned NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vm_conv` (`conversation_id`,`id`),
  KEY `idx_vm_unread` (`conversation_id`,`sender_vendor_id`,`is_read`),
  KEY `fk_vm_sender` (`sender_vendor_id`),
  CONSTRAINT `fk_vm_conv` FOREIGN KEY (`conversation_id`) REFERENCES `vendor_conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vm_sender` FOREIGN KEY (`sender_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_vm_body` CHECK (char_length(trim(`body`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `category` varchar(40) NOT NULL DEFAULT 'announcement',
  `audience` enum('all','section') NOT NULL DEFAULT 'all',
  `section` varchar(60) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `recipient_count` int(10) unsigned NOT NULL DEFAULT 0,
  `read_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_by_name` varchar(160) NOT NULL DEFAULT 'Market Office',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sms_sent` int(10) unsigned NOT NULL DEFAULT 0,
  `sms_failed` int(10) unsigned NOT NULL DEFAULT 0,
  `sms_skipped` int(10) unsigned NOT NULL DEFAULT 0,
  `sms_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ann_feed` (`is_active`,`is_pinned`,`published_at`),
  KEY `idx_ann_expiry` (`is_active`,`expires_at`),
  KEY `idx_ann_audience` (`audience`,`section`),
  KEY `idx_ann_author` (`created_by`),
  CONSTRAINT `fk_ann_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_ann_title` CHECK (char_length(trim(`title`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=185 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sender_type` enum('admin','vendor','customer','system') NOT NULL DEFAULT 'system',
  `sender_id` int(10) unsigned DEFAULT NULL,
  `sender_name` varchar(160) NOT NULL DEFAULT 'System',
  `recipient_type` enum('admin','vendor','customer') NOT NULL DEFAULT 'vendor',
  `recipient_id` int(10) unsigned NOT NULL DEFAULT 0,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `category` varchar(40) NOT NULL DEFAULT 'general',
  `title` varchar(180) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `announcement_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_inbox` (`recipient_type`,`recipient_id`,`is_read`,`id`),
  KEY `idx_notif_sender` (`sender_type`,`sender_id`),
  KEY `idx_notif_parent` (`parent_id`),
  KEY `idx_notif_feed` (`recipient_type`,`recipient_id`,`created_at`,`id`),
  KEY `idx_notif_category` (`category`),
  KEY `idx_notif_announcement` (`announcement_id`,`is_read`),
  CONSTRAINT `fk_notif_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notif_parent` FOREIGN KEY (`parent_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_notif_title` CHECK (char_length(trim(`title`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=1998 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `action` varchar(80) NOT NULL,
  `target` varchar(120) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_created` (`created_at`),
  KEY `idx_log_admin` (`admin_id`,`created_at`),
  KEY `idx_log_action` (`action`,`created_at`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1375 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stall_swap_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `requester_vendor_id` int(10) unsigned NOT NULL,
  `requester_stall` varchar(30) NOT NULL DEFAULT '',
  `requester_section` varchar(60) DEFAULT NULL,
  `target_vendor_id` int(10) unsigned NOT NULL,
  `target_stall` varchar(30) NOT NULL DEFAULT '',
  `target_section` varchar(60) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `conversation_id` int(10) unsigned DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_by_name` varchar(160) DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_swap_queue` (`status`,`id`),
  KEY `idx_swap_req` (`requester_vendor_id`,`status`,`id`),
  KEY `idx_swap_tgt` (`target_vendor_id`,`status`,`id`),
  KEY `idx_swap_pair` (`requester_vendor_id`,`target_vendor_id`,`status`),
  KEY `fk_swap_conv` (`conversation_id`),
  KEY `fk_swap_admin` (`decided_by`),
  CONSTRAINT `fk_swap_admin` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_swap_conv` FOREIGN KEY (`conversation_id`) REFERENCES `vendor_conversations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_swap_requester` FOREIGN KEY (`requester_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_swap_target` FOREIGN KEY (`target_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_swap_distinct` CHECK (`requester_vendor_id` <> `target_vendor_id`),
  CONSTRAINT `chk_swap_decided` CHECK (`status` = 'Pending' or `decided_at` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stall_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_id` int(10) unsigned NOT NULL,
  `requested_stall` varchar(30) DEFAULT NULL,
  `request_note` varchar(500) DEFAULT NULL,
  `status` enum('Pending','Assigned','Declined') NOT NULL DEFAULT 'Pending',
  `assigned_stall` varchar(30) DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_by_name` varchar(120) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sa_status` (`status`,`created_at`),
  KEY `idx_sa_decider` (`decided_by`),
  KEY `idx_sa_vendor` (`vendor_id`,`status`),
  CONSTRAINT `fk_sa_admin` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sa_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_sa_decided` CHECK (`status` = 'Pending' or `decided_at` is not null),
  CONSTRAINT `chk_sa_assigned` CHECK (`status` <> 'Assigned' or `assigned_stall` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=462 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reporter_vendor_id` int(10) unsigned NOT NULL,
  `reported_vendor_id` int(10) unsigned NOT NULL,
  `reason` varchar(60) NOT NULL,
  `details` text NOT NULL,
  `status` enum('Open','Reviewed','Dismissed') NOT NULL DEFAULT 'Open',
  `admin_note` varchar(500) DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_by_name` varchar(120) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vr_status` (`status`,`created_at`),
  KEY `idx_vr_reporter` (`reporter_vendor_id`,`status`),
  KEY `idx_vr_reported` (`reported_vendor_id`,`status`),
  KEY `fk_vr_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_vr_reported` FOREIGN KEY (`reported_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vr_reporter` FOREIGN KEY (`reporter_vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_vr_distinct` CHECK (`reporter_vendor_id` <> `reported_vendor_id`),
  CONSTRAINT `chk_vr_details` CHECK (char_length(trim(`details`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=191 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_id` int(10) unsigned NOT NULL,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `direction` enum('vendor','admin') NOT NULL DEFAULT 'vendor',
  `body` text NOT NULL,
  `stall_requested` varchar(30) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_am_vendor` (`vendor_id`,`created_at`),
  KEY `idx_am_inbox` (`direction`,`is_read`,`created_at`),
  KEY `fk_am_admin` (`admin_id`),
  CONSTRAINT `fk_am_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_am_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_am_body` CHECK (char_length(trim(`body`)) > 0)
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_meta` (
  `meta_key` varchar(64) NOT NULL,
  `meta_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_meta` (`meta_key`, `meta_value`) VALUES
  ('schema_version', '15'),
  ('last_migration', 'schema_version=15 loaded from db/file.sql')
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);
