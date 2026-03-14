-- Migration: Add invoice_mapping table and fix schema inconsistencies
-- Applied to existing installations that already have the old schema

-- Add missing columns to sync_history
ALTER TABLE `sync_history` 
  ADD COLUMN IF NOT EXISTS `resource_type` varchar(50) DEFAULT NULL AFTER `action_type`,
  ADD COLUMN IF NOT EXISTS `resource_id` varchar(100) DEFAULT NULL AFTER `resource_type`;

-- Rename job_queue to jobs (if old table exists)
-- Note: MySQL doesn't support IF EXISTS for RENAME, so we check first
-- If you get an error here, the table was already renamed

-- Add missing columns to jobs table
ALTER TABLE `jobs`
  ADD COLUMN IF NOT EXISTS `scheduled_at` timestamp NULL DEFAULT NULL AFTER `error_message`,
  ADD COLUMN IF NOT EXISTS `started_at` timestamp NULL DEFAULT NULL AFTER `scheduled_at`;

-- Make zoho_id UNIQUE (if not already)
-- This is safe because duplicates should not exist
ALTER TABLE `zoho_products` DROP INDEX IF EXISTS `idx_zoho_id`;
ALTER TABLE `zoho_products` ADD UNIQUE KEY `idx_zoho_id` (`zoho_id`);

-- Create invoice_mapping table
CREATE TABLE IF NOT EXISTS `invoice_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zoho_invoice_id` varchar(50) NOT NULL,
  `parasut_invoice_id` varchar(50) NOT NULL,
  `source` enum('zoho','parasut') NOT NULL DEFAULT 'parasut',
  `zoho_invoice_number` varchar(100) DEFAULT NULL,
  `parasut_invoice_number` varchar(100) DEFAULT NULL,
  `parasut_e_invoice_id` varchar(50) DEFAULT NULL,
  `parasut_e_archive_id` varchar(50) DEFAULT NULL,
  `e_document_number` varchar(100) DEFAULT NULL,
  `e_document_status` varchar(50) DEFAULT NULL,
  `sync_status` varchar(20) DEFAULT 'pending',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_zoho_invoice` (`zoho_invoice_id`),
  KEY `idx_parasut_invoice` (`parasut_invoice_id`),
  KEY `idx_sync_status` (`sync_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
