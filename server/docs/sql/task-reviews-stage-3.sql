-- Jucans Stage 3: apply this script OR the Laravel migration, never both.
-- Requires the existing Projects/task occurrences/progress notes tables.
-- Does not register the migration in Laravel's migrations ledger.
-- Existing notes are preserved and are NOT automatically converted into reviews.
ALTER TABLE `project_tasks`
  ADD COLUMN `review_mode` ENUM('single','multiple') NOT NULL DEFAULT 'single';

CREATE TABLE `project_task_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `occurrence_id` BIGINT UNSIGNED NOT NULL,
  `note_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `slot` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_task_reviews_note_id_unique` (`note_id`),
  UNIQUE KEY `task_reviews_slot_unique` (`occurrence_id`,`slot`),
  UNIQUE KEY `task_reviews_author_unique` (`occurrence_id`,`user_id`),
  KEY `task_reviews_project_task_fk` (`project_id`,`task_id`),
  KEY `task_reviews_occurrence_fk` (`task_id`,`occurrence_id`),
  KEY `task_reviews_note_fk` (`project_id`,`note_id`),
  KEY `project_task_reviews_user_id_foreign` (`user_id`),
  CONSTRAINT `task_reviews_project_task_fk` FOREIGN KEY (`project_id`,`task_id`)
    REFERENCES `project_tasks` (`project_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `task_reviews_occurrence_fk` FOREIGN KEY (`task_id`,`occurrence_id`)
    REFERENCES `project_task_occurrences` (`task_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `task_reviews_note_fk` FOREIGN KEY (`project_id`,`note_id`)
    REFERENCES `project_progress_notes` (`project_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `project_task_reviews_user_id_foreign` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
