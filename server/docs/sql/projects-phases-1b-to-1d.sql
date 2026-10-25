-- Jucans Projects: MySQL / MariaDB migration SQL.
-- Requires users and Projects Phase 1A tables already applied; phases 1B-1D must not already be applied.
-- Generated from the current Laravel migrations using MySQL grammar in pretend mode.
-- No database connection or SQL execution was performed during export.
-- CHECK enforcement requires a database version that supports it.
-- Laravel migration tracking is not modified by this script.

-- Migration: 2026_10_25_000004_create_project_collaborators_table
create table `project_collaborators` (
    `id` bigint unsigned not null auto_increment primary key,
    `project_id` bigint unsigned not null,
    `user_id` bigint unsigned not null,
    `sharing_mode` enum('all_tasks', 'selected_tasks') not null,
    `created_at` timestamp null,
    `updated_at` timestamp null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_collaborators` add constraint `project_collaborators_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete restrict;

alter table `project_collaborators` add constraint `project_collaborators_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete restrict;

alter table `project_collaborators` add unique `project_collaborators_project_id_user_id_unique`(`project_id`, `user_id`);

alter table `project_collaborators` add unique `project_collaborators_project_identity_unique`(`project_id`, `id`);

alter table `project_collaborators` add index `project_collaborators_user_id_project_id_index`(`user_id`, `project_id`);

-- Migration: 2026_10_25_000005_create_project_tasks_table
create table `project_tasks` (
    `id` bigint unsigned not null auto_increment primary key,
    `project_id` bigint unsigned not null,
    `created_by` bigint unsigned not null,
    `title` varchar(255) not null,
    `description` text null,
    `execution_type` enum('one_time', 'recurring', 'persistent') not null,
    `priority` enum('low', 'medium', 'high', 'urgent') not null default 'medium',
    `status` enum('active', 'completed', 'archived') not null default 'active',
    `scheduled_date` date null,
    `scheduled_time` time null,
    `due_date` date null,
    `due_time` time null,
    `show_in_daily_widget` tinyint(1) not null default '1',
    `created_at` timestamp(6) null,
    `updated_at` timestamp(6) null,
    `deleted_at` timestamp(6) null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_tasks` add constraint `project_tasks_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete restrict;

alter table `project_tasks` add constraint `project_tasks_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete restrict;

alter table `project_tasks` add unique `project_tasks_project_identity_unique`(`project_id`, `id`);

alter table `project_tasks` add index `project_tasks_listing_index`(`project_id`, `deleted_at`, `status`);

alter table `project_tasks` add index `project_tasks_project_id_created_by_index`(`project_id`, `created_by`);

alter table `project_tasks` add index `project_tasks_project_id_due_date_index`(`project_id`, `due_date`);

alter table `project_tasks` add index `project_tasks_project_id_scheduled_date_index`(`project_id`, `scheduled_date`);

-- Migration: 2026_10_25_000006_create_project_task_grants_table
create table `project_task_grants` (
    `id` bigint unsigned not null auto_increment primary key,
    `project_id` bigint unsigned not null,
    `collaborator_id` bigint unsigned not null,
    `task_id` bigint unsigned not null,
    `created_at` timestamp null,
    `updated_at` timestamp null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_grants` add unique `project_task_grants_collaborator_id_task_id_unique`(`collaborator_id`, `task_id`);

alter table `project_task_grants` add constraint `project_task_grants_collaborator_foreign` foreign key (`project_id`, `collaborator_id`) references `project_collaborators` (`project_id`, `id`) on delete cascade;

alter table `project_task_grants` add constraint `project_task_grants_task_foreign` foreign key (`project_id`, `task_id`) references `project_tasks` (`project_id`, `id`) on delete restrict;

alter table `project_task_grants` add index `project_task_grants_task_id_collaborator_id_index`(`task_id`, `collaborator_id`);

-- Migration: 2026_10_25_000007_create_project_task_occurrences_table
create table `project_task_occurrences` (
    `id` bigint unsigned not null auto_increment primary key,
    `task_id` bigint unsigned not null,
    `occurrence_key` varchar(10) not null,
    `scheduled_date` date null,
    `scheduled_time` time null,
    `due_date` date null,
    `due_time` time null,
    `status` enum('pending', 'done', 'skipped') not null default 'pending',
    `completed_by` bigint unsigned null,
    `completed_at` timestamp(6) null,
    `lock_version` bigint unsigned not null default '0',
    `created_at` timestamp(6) null,
    `updated_at` timestamp(6) null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_occurrences` add constraint `project_task_occurrences_task_id_foreign` foreign key (`task_id`) references `project_tasks` (`id`) on delete restrict;

alter table `project_task_occurrences` add constraint `project_task_occurrences_completed_by_foreign` foreign key (`completed_by`) references `users` (`id`) on delete restrict;

alter table `project_task_occurrences` add unique `task_occurrences_logical_unique`(`task_id`, `occurrence_key`);

alter table `project_task_occurrences` add unique `task_occurrences_identity_unique`(`task_id`, `id`);

alter table `project_task_occurrences` add index `project_task_occurrences_task_id_scheduled_date_index`(`task_id`, `scheduled_date`);

-- Migration: 2026_10_25_000008_create_project_task_completion_events_table
create table `project_task_completion_events` (
    `id` bigint unsigned not null auto_increment primary key,
    `occurrence_id` bigint unsigned not null,
    `action` enum('done', 'undo', 'skip') not null,
    `actor_id` bigint unsigned not null,
    `performed_on` date not null,
    `recorded_at` timestamp(6) not null,
    `reverses_event_id` bigint unsigned null,
    `request_id` varchar(100) not null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_completion_events` add constraint `project_task_completion_events_occurrence_id_foreign` foreign key (`occurrence_id`) references `project_task_occurrences` (`id`) on delete restrict;

alter table `project_task_completion_events` add constraint `project_task_completion_events_actor_id_foreign` foreign key (`actor_id`) references `users` (`id`) on delete restrict;

alter table `project_task_completion_events` add unique `task_completion_events_request_unique`(`occurrence_id`, `request_id`);

alter table `project_task_completion_events` add unique `task_completion_events_identity_unique`(`occurrence_id`, `id`);

alter table `project_task_completion_events` add unique `project_task_completion_events_reverses_event_id_unique`(`reverses_event_id`);

alter table `project_task_completion_events` add constraint `task_completion_events_reversal_foreign` foreign key (`occurrence_id`, `reverses_event_id`) references `project_task_completion_events` (`occurrence_id`, `id`) on delete restrict;

alter table `project_task_completion_events` add index `task_completion_events_business_date_index`(`occurrence_id`, `performed_on`, `id`);

-- Migration: 2026_10_25_000009_create_project_task_execution_requests_table
create table `project_task_execution_requests` (
    `id` bigint unsigned not null auto_increment primary key,
    `task_id` bigint unsigned not null,
    `occurrence_id` bigint unsigned not null,
    `request_id` varchar(100) not null,
    `payload_hash` char(64) not null,
    `recorded_at` timestamp(6) not null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_execution_requests` add unique `task_execution_requests_unique`(`task_id`, `request_id`);

alter table `project_task_execution_requests` add constraint `task_execution_requests_occurrence_foreign` foreign key (`task_id`, `occurrence_id`) references `project_task_occurrences` (`task_id`, `id`) on delete restrict;

-- Migration: 2026_10_25_000010_create_project_task_schedule_versions_table
create table `project_task_schedule_versions` (
    `id` bigint unsigned not null auto_increment primary key,
    `task_id` bigint unsigned not null,
    `version` int unsigned not null,
    `effective_from` date not null,
    `effective_until` date null,
    `recurrence_type` enum('daily', 'every_n_days', 'weekdays', 'monthly') not null,
    `interval_days` int unsigned null,
    `weekdays` json null,
    `month_day` tinyint unsigned null,
    `anchor_date` date not null,
    `start_date` date null,
    `end_date` date null,
    `scheduled_time` time null,
    `created_by` bigint unsigned not null,
    `created_at` timestamp(6) null,
    `updated_at` timestamp(6) null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_schedule_versions` add constraint `project_task_schedule_versions_task_id_foreign` foreign key (`task_id`) references `project_tasks` (`id`) on delete restrict;

alter table `project_task_schedule_versions` add constraint `project_task_schedule_versions_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete restrict;

alter table `project_task_schedule_versions` add unique `task_schedule_versions_number_unique`(`task_id`, `version`);

alter table `project_task_schedule_versions` add unique `task_schedule_versions_identity_unique`(`task_id`, `id`);

alter table `project_task_schedule_versions` add index `task_schedule_versions_effective_index`(`task_id`, `effective_from`, `effective_until`);

-- Migration: 2026_10_25_000011_link_occurrences_to_schedule_versions
alter table `project_task_occurrences` add `schedule_version_id` bigint unsigned null;

alter table `project_task_occurrences` add constraint `task_occurrences_schedule_foreign` foreign key (`task_id`, `schedule_version_id`) references `project_task_schedule_versions` (`task_id`, `id`) on delete restrict;

-- Migration: 2026_10_25_000012_create_project_task_deletion_periods_table
create table `project_task_deletion_periods` (
    `id` bigint unsigned not null auto_increment primary key,
    `task_id` bigint unsigned not null,
    `deleted_at` timestamp(6) not null,
    `restored_at` timestamp(6) null,
    `deleted_by` bigint unsigned null,
    `restored_by` bigint unsigned null
) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `project_task_deletion_periods` add constraint `project_task_deletion_periods_task_id_foreign` foreign key (`task_id`) references `project_tasks` (`id`) on delete restrict;

alter table `project_task_deletion_periods` add constraint `project_task_deletion_periods_deleted_by_foreign` foreign key (`deleted_by`) references `users` (`id`) on delete restrict;

alter table `project_task_deletion_periods` add constraint `project_task_deletion_periods_restored_by_foreign` foreign key (`restored_by`) references `users` (`id`) on delete restrict;

alter table `project_task_deletion_periods` add index `task_deletion_periods_interval_index`(`task_id`, `deleted_at`, `restored_at`);

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
