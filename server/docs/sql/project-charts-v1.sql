-- Jucans Charts v1: MySQL SQL equivalent of
-- 2026_11_04_000001_create_project_charts_tables.php.
-- Execute manually in phpMyAdmin/cPanel against the intended database.
-- Requires existing projects and project_custom_fields (InnoDB), including
-- custom_fields_project_identity UNIQUE(project_id,id). Existing note-values
-- schema requires MySQL >= 8.0.16; no MariaDB compatibility is claimed here.
-- DDL causes implicit commits. Run this SQL OR the Laravel migration, not both.
-- This script does not alter the Laravel migrations ledger or existing note data.

CREATE TABLE `project_charts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('pie','line','bar') NOT NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `total` DECIMAL(20,6) NULL,
  `y_min` DECIMAL(20,6) NULL,
  `y_max` DECIMAL(20,6) NULL,
  `created_at` TIMESTAMP(6) NULL,
  `updated_at` TIMESTAMP(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charts_project_identity` (`project_id`,`id`),
  KEY `charts_display_order` (`project_id`,`active`,`priority`,`id`),
  CONSTRAINT `project_charts_project_id_foreign` FOREIGN KEY (`project_id`)
    REFERENCES `projects` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_chart_fields` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `chart_id` BIGINT UNSIGNED NOT NULL,
  `field_id` BIGINT UNSIGNED NOT NULL,
  `position` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chart_fields_identity` (`chart_id`,`field_id`),
  CONSTRAINT `chart_fields_chart_fk` FOREIGN KEY (`project_id`,`chart_id`)
    REFERENCES `project_charts` (`project_id`,`id`) ON DELETE CASCADE,
  CONSTRAINT `chart_fields_field_fk` FOREIGN KEY (`project_id`,`field_id`)
    REFERENCES `project_custom_fields` (`project_id`,`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
