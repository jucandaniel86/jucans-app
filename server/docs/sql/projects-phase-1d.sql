-- Jucans Projects: MySQL / MariaDB migration SQL.
-- Requires Projects Phase 1A and task phases 1B/1C.1/1C.2 already applied.
-- Generated from the current Laravel migrations using MySQL grammar in pretend mode.
-- No database connection or SQL execution was performed during export.
-- CHECK enforcement requires a database version that supports it.
-- Laravel migration tracking is not modified by this script.

-- Migration: 2026_10_25_000013_create_project_progress_notes_tables
create table `project_progress_notes` (
    `id` bigint unsigned not null auto_increment primary key,
    `project_id` bigint unsigned not null,
    `created_by` bigint unsigned not null,
    `title` varchar(255) not null,
    `content` text not null,
    `noted_at` date not null,
    `updated_by` bigint unsigned null,
    `created_at` timestamp(6) null,
    `updated_at` timestamp(6) null,
    `deleted_at` timestamp(6) null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_progress_notes` add constraint `project_progress_notes_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete restrict;

alter table `project_progress_notes` add constraint `project_progress_notes_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete restrict;

alter table `project_progress_notes` add constraint `project_progress_notes_updated_by_foreign` foreign key (`updated_by`) references `users` (`id`) on delete restrict;

alter table `project_progress_notes` add unique `progress_notes_project_identity_unique`(`project_id`, `id`);

alter table `project_progress_notes` add index `progress_notes_journal_index`(`project_id`, `deleted_at`, `noted_at`);

create table `project_progress_note_tasks` (
    `project_id` bigint unsigned not null,
    `note_id` bigint unsigned not null,
    `task_id` bigint unsigned not null,
    primary key (`note_id`, `task_id`)
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_progress_note_tasks` add unique `progress_note_tasks_identity_unique`(`project_id`, `note_id`, `task_id`);

alter table `project_progress_note_tasks` add constraint `progress_note_tasks_note_foreign` foreign key (`project_id`, `note_id`) references `project_progress_notes` (`project_id`, `id`) on delete restrict;

alter table `project_progress_note_tasks` add constraint `progress_note_tasks_task_foreign` foreign key (`project_id`, `task_id`) references `project_tasks` (`project_id`, `id`) on delete restrict;

alter table `project_progress_note_tasks` add index `project_progress_note_tasks_task_id_note_id_index`(`task_id`, `note_id`);

-- Migration: 2026_10_25_000014_create_project_task_evaluations_tables
create table `project_task_evaluations` (
    `id` bigint unsigned not null auto_increment primary key,
    `project_id` bigint unsigned not null,
    `task_id` bigint unsigned not null,
    `evaluation_date` date not null,
    `score` tinyint unsigned not null,
    `created_by` bigint unsigned not null,
    `note_id` bigint unsigned null,
    `lock_version` bigint unsigned not null default '0',
    `active_slot` enum('1') null default '1',
    `withdrawn_at` timestamp(6) null,
    `created_at` timestamp(6) null,
    `updated_at` timestamp(6) null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_evaluations` add constraint `project_task_evaluations_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete restrict;

alter table `project_task_evaluations` add unique `task_evaluations_active_day_unique`(`task_id`, `evaluation_date`, `active_slot`);

alter table `project_task_evaluations` add constraint `task_evaluations_task_foreign` foreign key (`project_id`, `task_id`) references `project_tasks` (`project_id`, `id`) on delete restrict;

alter table `project_task_evaluations` add constraint `task_evaluations_note_task_foreign` foreign key (`project_id`, `note_id`, `task_id`) references `project_progress_note_tasks` (`project_id`, `note_id`, `task_id`) on delete restrict;

alter table `project_task_evaluations` add index `project_task_evaluations_note_id_active_slot_index`(`note_id`, `active_slot`);

create table `project_task_evaluation_revisions` (
    `id` bigint unsigned not null auto_increment primary key,
    `evaluation_id` bigint unsigned not null,
    `actor_id` bigint unsigned not null,
    `previous_score` tinyint unsigned null,
    `new_score` tinyint unsigned null,
    `previous_note_id` bigint unsigned null,
    `new_note_id` bigint unsigned null,
    `recorded_at` timestamp(6) not null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_evaluation_revisions` add constraint `project_task_evaluation_revisions_evaluation_id_foreign` foreign key (`evaluation_id`) references `project_task_evaluations` (`id`) on delete restrict;

alter table `project_task_evaluation_revisions` add constraint `project_task_evaluation_revisions_actor_id_foreign` foreign key (`actor_id`) references `users` (`id`) on delete restrict;

alter table `project_task_evaluation_revisions` add constraint `project_task_evaluation_revisions_previous_note_id_foreign` foreign key (`previous_note_id`) references `project_progress_notes` (`id`) on delete restrict;

alter table `project_task_evaluation_revisions` add constraint `project_task_evaluation_revisions_new_note_id_foreign` foreign key (`new_note_id`) references `project_progress_notes` (`id`) on delete restrict;

alter table `project_task_evaluation_revisions` add index `project_task_evaluation_revisions_evaluation_id_id_index`(`evaluation_id`, `id`);

ALTER TABLE project_task_evaluations ADD CONSTRAINT task_evaluation_score_check CHECK (score BETWEEN 1 AND 10);
