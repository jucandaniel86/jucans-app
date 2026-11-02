-- Jucans Backend Phase 2: Note Values & Historical Configuration
-- Equivalent to 2026_10_31_000001_add_progress_note_values (MySQL branch).
-- Exported from the migration using Laravel MySQL grammar in pretend mode.
-- No database connection was opened by the export.
-- FIRST run project-note-values-phase-2-preflight.sql and check every result.
-- Requires MySQL >= 8.0.16 (not MariaDB), InnoDB and enforced foreign keys.
-- Requires Phase 1 configuration tables and project progress-note tables.
-- Select the intended database explicitly before importing this file.
-- Run ONCE, with the application paused for writes. Do not use --force on mysql.
-- No legacy note backfill/default assignment. No definitions or values are deleted.
-- DDL is not one atomic transaction: stop at the FIRST error and inspect state.
-- Does NOT register Laravel migration history. After successful verification,
-- register this migration as applied before later running artisan migrate.
-- Do not execute this SQL AND the corresponding Laravel migration.

alter table `project_custom_field_options` add unique `field_options_identity`(`field_id`, `id`);

alter table `project_progress_notes` add `note_type_id` bigint unsigned null;

alter table `project_progress_notes` add constraint `note_configuration_type_fk` foreign key (`project_id`, `note_type_id`) references `project_note_types` (`project_id`, `id`) on delete restrict;

alter table `project_progress_notes` add `configuration_snapshot` json null, add `configuration_captured_at` timestamp(6) null;

alter table `project_progress_notes` add index `notes_type_index`(`project_id`, `note_type_id`);

create table `project_progress_note_values` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `note_id` bigint unsigned not null, `field_id` bigint unsigned not null, `value_type` enum('number', 'text', 'boolean', 'select') not null, `number_value` decimal(20, 6) null, `text_value` longtext null, `boolean_value` tinyint(1) null, `select_option_id` bigint unsigned null, `created_at` timestamp(6) null, `updated_at` timestamp(6) null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_progress_note_values` add unique `note_values_identity`(`note_id`, `field_id`);

alter table `project_progress_note_values` add constraint `note_values_note_fk` foreign key (`project_id`, `note_id`) references `project_progress_notes` (`project_id`, `id`) on delete restrict;

alter table `project_progress_note_values` add constraint `note_values_field_fk` foreign key (`project_id`, `field_id`) references `project_custom_fields` (`project_id`, `id`) on delete restrict;

alter table `project_progress_note_values` add constraint `note_values_option_fk` foreign key (`field_id`, `select_option_id`) references `project_custom_field_options` (`field_id`, `id`) on delete restrict;

alter table `project_progress_note_values` add index `note_values_field_index`(`project_id`, `field_id`);

ALTER TABLE project_progress_notes ADD CONSTRAINT note_context_check CHECK ((note_type_id IS NULL AND configuration_snapshot IS NULL AND configuration_captured_at IS NULL) OR (note_type_id IS NOT NULL AND configuration_snapshot IS NOT NULL AND configuration_captured_at IS NOT NULL));

ALTER TABLE project_progress_note_values ADD CONSTRAINT note_value_type_check CHECK (value_type IN ('number','text','boolean','select') AND (boolean_value IS NULL OR boolean_value IN (0,1)) AND (number_value IS NULL OR value_type = 'number') AND (text_value IS NULL OR value_type = 'text') AND (boolean_value IS NULL OR value_type = 'boolean') AND (select_option_id IS NULL OR value_type = 'select'));

DELIMITER $$
CREATE TRIGGER note_context_immutable BEFORE UPDATE ON project_progress_notes FOR EACH ROW BEGIN IF OLD.note_type_id IS NOT NULL AND (NOT (NEW.note_type_id <=> OLD.note_type_id) OR NOT (NEW.configuration_snapshot <=> OLD.configuration_snapshot) OR NOT (NEW.configuration_captured_at <=> OLD.configuration_captured_at)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Saved note configuration is immutable'; END IF; END$$
DELIMITER ;

-- Verify successful completion before recording migration history:
SHOW CREATE TABLE `project_progress_notes`;
SHOW CREATE TABLE `project_progress_note_values`;
SHOW CREATE TABLE `project_custom_field_options`;
SHOW CREATE TRIGGER `note_context_immutable`;

-- ONLY after all 14 DDL statements completed and the schema above was verified,
-- run the following statement separately to avoid Laravel replaying this migration.
-- It records a new batch and does nothing if the migration is already recorded.
-- Do not register a partially applied schema.
-- INSERT INTO `migrations` (`migration`, `batch`)
-- SELECT '2026_10_31_000001_add_progress_note_values', COALESCE(MAX(`batch`), 0) + 1
-- FROM `migrations`
-- HAVING COALESCE(SUM(`migration` = '2026_10_31_000001_add_progress_note_values'), 0) = 0;
