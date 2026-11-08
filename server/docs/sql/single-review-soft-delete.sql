-- Equivalent to 2026_11_08_000001_release_soft_deleted_single_review_slots.php.
-- Run ONCE in phpMyAdmin/cPanel after the Stage 3 task-review schema, BEFORE
-- deploying the updated backend. Execute this SQL OR the Laravel migration.
-- Do not also execute the original CREATE TABLE script against existing tables.
-- These index names come from Stage 3; inspect SHOW CREATE TABLE project_task_reviews
-- first if production was created using a different schema.
-- DDL implicitly commits. No notes, values, snapshots, task status or audit links
-- are deleted/rewritten. This script does not register a Laravel migrations row.

-- 1. Default reservations stay occupied, preserving Multiple behavior.
ALTER TABLE `project_task_reviews`
  ADD COLUMN `active_slot` ENUM('1') NULL DEFAULT '1';

-- 2. Preserve both uniqueness barriers for occupied reservations. MySQL permits
-- multiple NULL reservations, so deleted Single history no longer blocks creation.
ALTER TABLE `project_task_reviews`
  DROP INDEX `task_reviews_slot_unique`,
  DROP INDEX `task_reviews_author_unique`,
  ADD UNIQUE KEY `task_reviews_slot_unique` (`occurrence_id`, `slot`, `active_slot`),
  ADD UNIQUE KEY `task_reviews_author_unique` (`occurrence_id`, `user_id`, `active_slot`);

-- 3. Release only pre-existing deleted SINGLE reservations. Multiple rows retain '1'.
UPDATE `project_task_reviews` AS r
INNER JOIN `project_tasks` AS t ON t.id = r.task_id
INNER JOIN `project_progress_notes` AS n ON n.id = r.note_id
SET r.active_slot = NULL
WHERE t.review_mode = 'single' AND n.deleted_at IS NOT NULL;
