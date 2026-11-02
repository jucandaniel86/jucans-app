-- Jucans: Custom Fields Library and Note Types, Backend Phase 1.
-- MySQL / MariaDB; requires the existing projects table (InnoDB).
-- Generated from Laravel migration grammar in pretend mode; no database connection opened.
-- Migration: 2026_10_30_000001_create_project_note_configuration_tables
-- Creates configuration tables only; no project initialization or historical data changes.
-- Laravel migration tracking is not modified. Do not apply both SQL and the migration.

create table `project_custom_fields` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `name` varchar(255) not null, `key` varchar(100) not null, `description` text null, `type` enum('number', 'select', 'boolean', 'text') not null, `unit` varchar(100) null, `min` decimal(20, 6) null, `max` decimal(20, 6) null, `step` decimal(20, 6) null, `aggregation` enum('average', 'sum', 'min', 'max', 'latest') null, `first_value_at` timestamp(6) null, `archived_at` timestamp(6) null, `created_at` timestamp(6) null, `updated_at` timestamp(6) null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_custom_fields` add constraint `project_custom_fields_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete restrict;

alter table `project_custom_fields` add unique `project_custom_fields_project_id_key_unique`(`project_id`, `key`);

alter table `project_custom_fields` add unique `custom_fields_project_identity`(`project_id`, `id`);

create table `project_custom_field_options` (`id` bigint unsigned not null auto_increment primary key, `field_id` bigint unsigned not null, `label` varchar(255) not null, `sort_order` int unsigned not null default '0', `archived_at` timestamp(6) null, `created_at` timestamp(6) null, `updated_at` timestamp(6) null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_custom_field_options` add constraint `project_custom_field_options_field_id_foreign` foreign key (`field_id`) references `project_custom_fields` (`id`) on delete restrict;

alter table `project_custom_field_options` add index `project_custom_field_options_field_id_sort_order_index`(`field_id`, `sort_order`);

create table `project_note_types` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `name` varchar(255) not null, `description` text null, `template` text null, `is_default` enum('1') null, `archived_at` timestamp(6) null, `created_at` timestamp(6) null, `updated_at` timestamp(6) null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_note_types` add constraint `project_note_types_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete restrict;

alter table `project_note_types` add unique `project_note_types_project_id_name_unique`(`project_id`, `name`);

alter table `project_note_types` add unique `note_types_default_unique`(`project_id`, `is_default`);

alter table `project_note_types` add unique `note_types_project_identity`(`project_id`, `id`);

create table `project_note_type_fields` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `note_type_id` bigint unsigned not null, `field_id` bigint unsigned not null, `sort_order` int unsigned not null default '0', `required` tinyint(1) not null default '0', `activated_at` timestamp(6) not null, `deactivated_at` timestamp(6) null, `active_slot` enum('1') null default '1') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_note_type_fields` add unique `note_type_fields_active_unique`(`note_type_id`, `field_id`, `active_slot`);

alter table `project_note_type_fields` add constraint `note_type_fields_type_fk` foreign key (`project_id`, `note_type_id`) references `project_note_types` (`project_id`, `id`) on delete restrict;

alter table `project_note_type_fields` add constraint `note_type_fields_field_fk` foreign key (`project_id`, `field_id`) references `project_custom_fields` (`project_id`, `id`) on delete restrict;

alter table `project_note_type_fields` add index `note_type_fields_history_index`(`note_type_id`, `activated_at`);
