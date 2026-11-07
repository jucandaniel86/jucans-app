-- Equivalent to 2026_11_07_000001_add_pie_select_aggregation_to_project_charts.php.
-- Run ONCE in phpMyAdmin/cPanel, after the existing Charts schema, before deploying
-- the updated backend. Run this script OR the Laravel migration, never both.
-- Does not register an entry in Laravel's migrations table.
-- Production SHOW CREATE TABLE was checked: both tables InnoDB; matching
-- BIGINT UNSIGNED keys; project_custom_fields has UNIQUE(project_id,id).
-- Existing chart rows receive NULL in both columns; legacy Select Pie stays COUNT.
-- No note values or task/review data are modified. DDL implicitly commits in MySQL.

ALTER TABLE `project_charts`
  ADD COLUMN `pie_aggregation` ENUM('count','sum') NULL,
  ADD COLUMN `pie_value_field_id` BIGINT UNSIGNED NULL,
  ADD INDEX `charts_pie_value_field_index` (`project_id`, `pie_value_field_id`),
  ADD CONSTRAINT `charts_pie_value_field_fk`
    FOREIGN KEY (`project_id`, `pie_value_field_id`)
    REFERENCES `project_custom_fields` (`project_id`, `id`)
    ON DELETE RESTRICT;
