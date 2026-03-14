-- Zoho-Parasut-Sync Database Schema
-- Run this SQL to create all required tables

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- System Tables
-- =============================================

CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `parasut_client_id` varchar(255) DEFAULT NULL,
  `parasut_client_secret` varchar(255) DEFAULT NULL,
  `parasut_username` varchar(255) DEFAULT NULL,
  `parasut_password` varchar(255) DEFAULT NULL,
  `parasut_company_id` varchar(255) DEFAULT NULL,
  `parasut_access_token` text DEFAULT NULL,
  `parasut_refresh_token` text DEFAULT NULL,
  `parasut_expires_at` int(11) DEFAULT NULL,
  `zoho_client_id` varchar(255) DEFAULT NULL,
  `zoho_client_secret` varchar(255) DEFAULT NULL,
  `zoho_refresh_token` text DEFAULT NULL,
  `zoho_organization_id` varchar(255) DEFAULT NULL,
  `zoho_access_token` text DEFAULT NULL,
  `zoho_expires_at` int(11) DEFAULT NULL,
  `zoho_tld` varchar(10) DEFAULT 'com',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_password` varchar(255) DEFAULT NULL,
  `cron_last_run` varchar(255) DEFAULT NULL,
  `cron_secret_key` varchar(255) DEFAULT NULL,
  `parasut_webhook_secret` varchar(255) DEFAULT NULL,
  `zoho_webhook_key` varchar(255) DEFAULT NULL,
  `turnstile_site_key` varchar(255) DEFAULT NULL,
  `turnstile_secret_key` varchar(255) DEFAULT NULL,
  `zoho_tax_map` text DEFAULT NULL,
  `default_product_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `level` varchar(20) NOT NULL DEFAULT 'INFO',
  `module` varchar(50) NOT NULL DEFAULT 'general',
  `message` text NOT NULL,
  `context` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`),
  KEY `idx_module` (`module`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lockout_until` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sync_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) DEFAULT NULL,
  `action_type` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sync_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_type` varchar(50) NOT NULL,
  `remote_id` varchar(100) NOT NULL,
  `system_source` enum('parasut','zoho') NOT NULL,
  `locked_until` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `resource_type` (`resource_type`,`remote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `api_metrics` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `service` varchar(20) NOT NULL,
  `method` varchar(10) NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `http_code` int(11) DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `is_retry` tinyint(1) DEFAULT 0,
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service` (`service`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `job_queue` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `job_type` varchar(100) NOT NULL,
  `payload` longtext DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_job_type` (`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- Parasut Tables
-- =============================================

CREATE TABLE IF NOT EXISTS `parasut_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parasut_id` varchar(50) NOT NULL,
  `product_code` varchar(255) DEFAULT NULL,
  `product_name` varchar(500) NOT NULL,
  `list_price` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'TRY',
  `is_archived` tinyint(1) DEFAULT 0,
  `raw_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `stock_quantity` decimal(15,2) DEFAULT 0.00,
  `invoice_count` int(11) DEFAULT 0,
  `buying_price` decimal(15,2) DEFAULT 0.00,
  `vat_rate` decimal(5,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parasut_id` (`parasut_id`),
  KEY `idx_product_code` (`product_code`),
  KEY `idx_product_name` (`product_name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `parasut_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parasut_id` varchar(50) NOT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `net_total` decimal(15,2) DEFAULT 0.00,
  `gross_total` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'TRY',
  `status` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `zoho_invoice_id` varchar(50) DEFAULT NULL,
  `zoho_total` decimal(15,2) DEFAULT NULL,
  `synced_to_zoho` tinyint(1) DEFAULT 0,
  `synced_at` datetime DEFAULT NULL,
  `sync_error` text DEFAULT NULL,
  `raw_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `payment_status` varchar(50) DEFAULT 'unpaid',
  `remaining_payment` decimal(15,2) DEFAULT 0.00,
  `last_status_check_at` datetime DEFAULT NULL,
  `invoice_type` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parasut_id` (`parasut_id`),
  KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_issue_date` (`issue_date`),
  KEY `idx_synced` (`synced_to_zoho`),
  KEY `idx_zoho_invoice_id` (`zoho_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `parasut_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `parasut_product_id` varchar(50) DEFAULT NULL,
  `product_name` varchar(500) DEFAULT NULL,
  `quantity` decimal(15,4) DEFAULT 0.0000,
  `unit_price` decimal(15,2) DEFAULT 0.00,
  `discount_rate` decimal(5,2) DEFAULT 0.00,
  `vat_rate` decimal(5,2) DEFAULT 0.00,
  `net_total` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_product` (`parasut_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `parasut_purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parasut_id` varchar(50) NOT NULL,
  `issue_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `net_total` decimal(15,2) DEFAULT 0.00,
  `gross_total` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'TRY',
  `description` text DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `zoho_po_id` varchar(50) DEFAULT NULL,
  `synced_to_zoho` tinyint(1) DEFAULT 0,
  `synced_at` datetime DEFAULT NULL,
  `sync_error` text DEFAULT NULL,
  `raw_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parasut_id` (`parasut_id`),
  KEY `idx_issue_date` (`issue_date`),
  KEY `idx_synced` (`synced_to_zoho`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- Zoho Tables
-- =============================================

CREATE TABLE IF NOT EXISTS `zoho_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zoho_id` varchar(50) NOT NULL,
  `product_code` varchar(255) DEFAULT NULL,
  `product_name` varchar(500) NOT NULL,
  `unit_price` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'TRY',
  `is_active` tinyint(1) DEFAULT 1,
  `raw_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `buying_price` decimal(15,2) DEFAULT 0.00,
  `vat_rate` decimal(5,2) DEFAULT 0.00,
  `stock_quantity` decimal(15,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_product_code` (`product_code`),
  KEY `idx_zoho_id` (`zoho_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `zoho_invoices` (
  `id` varchar(50) NOT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `total` decimal(15,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `raw_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_invoice_date` (`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- Merge Tables
-- =============================================

CREATE TABLE IF NOT EXISTS `merge_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `source_local_id` varchar(255) DEFAULT NULL,
  `target_local_id` varchar(255) DEFAULT NULL,
  `source_zoho_id` varchar(255) DEFAULT NULL,
  `target_zoho_id` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `affected_records` longtext DEFAULT NULL,
  `backup_data` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entity_type` (`entity_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_redirects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `old_zoho_id` varchar(50) NOT NULL,
  `new_zoho_id` varchar(50) NOT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_old_zoho` (`old_zoho_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================
-- Initial Data
-- =============================================

INSERT IGNORE INTO `settings` (`id`) VALUES (1);

SET FOREIGN_KEY_CHECKS = 1;
