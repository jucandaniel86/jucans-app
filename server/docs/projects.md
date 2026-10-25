# Projects specification

## Scope and approved corrections

Phase 1A implements categories, project settings, status history, and listing.
Phase 1B adds collaboration, task visibility, basic task CRUD, and soft deletion/
restoration. Phase 1C.1 adds explicit occurrences, Done/Undo/Skip, and completion
history. Phase 1C.2 adds versioned recurrence and calculated occurrences. Phase 1D
adds progress notes, evaluations, and note-template previews. Phase 1E adds progress
aggregation and chart-ready APIs. Phase 2A adds the Projects frontend foundation.
Phase 2B adds task management/calendar, Phase 2C adds the progress journal and
evaluations, Phase 2D adds frontend analytics, and Phase 2E adds the Home daily task
widget. Sharing management UI and bulk actions remain unimplemented.

The following rules apply to the overall module and supersede the original
architecture proposal:

1. Categories are entities in `project_categories`, referenced by `projects.category_id`.
   Initial categories are Health, Wellness, Financial, and Hobby.
2. General progress notes without task associations are visible only to their
   author and the project owner.
3. Any collaborator with task access can mark an execution Done or Undo, including
   an execution completed by someone else. Every effective action retains its
   actor and history. Completion state is shared.
4. The project owner administers all tasks and notes. Collaborators can edit or
   soft-delete their own tasks and edit/soft-delete their own notes. Phase 1D also
   permits note restoration by its author or owner while access remains valid.
   Phase 1B permits task restoration by the project owner or
   task creator while the project is active and the creator still has access.
5. A selected-task collaborator always retains access to tasks they created while
   they remain a project member, independently of explicit selected-task grants.
   Removing membership removes that collaborator access.

Future phases retain versioned schedules, dated occurrences, and append-only
Done/Undo history. They require separate approval.

## Phase 1A schema

- `project_categories`: ID, name, unique slug, sort order, timestamps.
- `projects`: ID, required name, nullable description, required category ID,
  icon (default `folder`), progress type (default `completion`), default execution
  type (default `one_time`), nullable note template, status (default `active`),
  created-by user ID, last meaningful activity timestamp, timestamps.
- `project_status_periods`: ID, project ID, status, UTC start/end timestamps,
  changed-by user ID, timestamps. A null end means the current period.

Status values: `active`, `completed`, `archived`.
Progress types: `completion`, `daily_completion`, `scored`.
Default execution types: `one_time`, `recurring`, `persistent`.

Projects belong to a category and an owner (`created_by`). Projects have many
status periods, each attributed to a user. Foreign keys restrict deletion of
referenced categories, users, and projects to protect history. No project deletion
endpoint is provided.

Indexes cover category slug uniqueness, owner/status/category/activity listing,
and project status-period start/end lookups. Project and period timestamps retain
microseconds. Periods use half-open intervals `[starts_at, ends_at)`.

Creation and status transitions run in transactions. Transitions lock the project
before checking authorization, closing the sole current period, and inserting its
successor. This serializes service writes and maintains one current, non-overlapping
period. The database does not independently enforce interval overlap or one-open-
period constraints; direct SQL writes can violate this invariant and are not a
supported write path.

## Phase 1A API

All routes use Sanctum authentication. No admin bypass is granted.

| Method | URL | Behavior |
| --- | --- | --- |
| GET | `/api/project-categories` | Categories in configured sort order. |
| GET | `/api/projects` | Paginated accessible project listing (owner or collaborator). |
| POST | `/api/projects` | Create project and its initial status period atomically. |
| GET | `/api/projects/{project}` | Accessible detail with category, owner, status periods. |
| PATCH | `/api/projects/{project}` | Update active project settings. |
| PATCH | `/api/projects/{project}/status` | Owner-only status transition. |

Creation requires `name` and an existing `category_id`; the other project fields
are optional. An explicit valid initial status is accepted; otherwise it is active.
The authenticated user always becomes the owner. Owner/activity/timestamp fields
cannot be assigned by API clients.

Settings PATCH supports name, description, category ID, icon, progress type,
default execution type, and note template. It rejects status and ownership changes.
Nullable fields can be cleared. Status changes must use the separate status route.

Allowed transitions:

- Active to completed or archived.
- Completed or archived to active (reactivation).
- Repeating the current status is an idempotent no-op.
- Completed to archived, or archived to completed, returns 409 until reactivation.

Inactive projects remain readable but settings PATCH returns 403, including for
their owner. Unrelated-user detail reads return 404; non-owner settings writes return 403.
Invalid payloads return 422. Unauthenticated requests return 401.

Responses use the existing `data` envelope; paginated lists include `links` and
`meta`. List responses omit status periods. Owner serialization excludes PIN and
admin status.

### Listing and meaningful activity

Filters:

- `status=active` (default).
- `status=inactive` combines completed and archived.
- `status=completed`, `status=archived`, or `status=all`.
- Optional existing `category_id`.
- `page` and `per_page` (default 20, maximum 100).

The flat paginated result groups projects by category sort order and category ID.
Within each category, projects sort by `last_activity_at` descending, then project
ID descending for deterministic ties. A page can split a category.

Meaningful activity currently means creation, an actual settings change, or a
status transition. Reads, identical settings PATCHes, and repeated status requests
do not change activity or timestamps. Later task/note activity must update this
field through the relevant transactional services when those phases are approved.
Phase 1B now records actual task and sharing changes as described below.

## Local setup and validation

The category seeder upserts by unique slug, preserves IDs and creation timestamps,
and resets canonical names/sort order. It is safe to rerun without duplicate rows.
Migrations do not seed categories implicitly. After applying migrations in an
approved environment, run:

```sh
php artisan db:seed --class=ProjectCategorySeeder
```

The Composer PSR-4 mapping includes `Database\Seeders\` so seeders work through
standard Artisan commands. Regenerate autoload metadata after changing that mapping.

Focused tests use SQLite in memory:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/Api/ProjectApiTest.php
```

Tests exercise authentication, owner isolation (including admins), category and
field validation, initial history, transitions/reactivation, read-only enforcement,
rollback on failed history writes, stale-model authorization, idempotent requests,
category seeder reruns, meaningful activity, filters, ordering, pagination, and
foreign-key protection.

The deployed database engine/version remains unverified. SQLite tests do not
establish production row-lock/concurrency behavior. No production migrations,
seeding, data changes, or deployment are part of Phase 1A.

## Decisions deferred to later phases

Timezone/day boundaries, recurrence cutoff and month-end/DST rules, recurrence
deadlines/backlog, inactive-day eligibility, restoration permissions, evaluation
ownership/retroactivity, associated-note/template visibility, and progress semantics
for recurring/persistent tasks still require decisions before their implementation.

## Phase 1A file manifest

Created:

- `app/Enums/ProjectStatus.php`
- `app/Enums/ProjectProgressType.php`
- `app/Enums/ProjectTaskExecutionType.php`
- `app/Models/ProjectCategory.php`
- `app/Models/Project.php`
- `app/Models/ProjectStatusPeriod.php`
- `app/Policies/ProjectPolicy.php`
- `app/Services/ProjectService.php`
- `app/Http/Controllers/Api/ProjectController.php`
- `app/Http/Controllers/Api/ProjectCategoryController.php`
- `app/Http/Requests/Project/StoreProjectRequest.php`
- `app/Http/Requests/Project/UpdateProjectRequest.php`
- `app/Http/Requests/Project/ListProjectsRequest.php`
- `app/Http/Requests/Project/ChangeProjectStatusRequest.php`
- `app/Http/Resources/ProjectResource.php`
- `app/Http/Resources/ProjectCategoryResource.php`
- `app/Http/Resources/ProjectStatusPeriodResource.php`
- `database/migrations/2026_10_25_000001_create_project_categories_table.php`
- `database/migrations/2026_10_25_000002_create_projects_table.php`
- `database/migrations/2026_10_25_000003_create_project_status_periods_table.php`
- `database/seeders/ProjectCategorySeeder.php`
- `tests/Feature/Api/ProjectApiTest.php`
- `docs/projects.md`

Modified:

- `app/Models/User.php`: owner relationship.
- `app/Providers/AuthServiceProvider.php`: project policy registration.
- `routes/api.php`: authenticated Project/category routes.
- `composer.json`: seeder namespace autoload mapping; no dependencies changed.

Validation results:

- Focused Project API suite: 21 tests, 281 assertions, all passing.
- Full backend suite: 197 tests, 1,636 assertions, 6 failures (191 tests passing).
- All six failures reproduced in a separate checkout of the original HEAD:
  four Diet general-information tests receive 405 because the expected PATCH route
  is absent; two Recipe tests fail on existing query-count and image-URL expectations.
  Those modules were not changed by Phase 1A.
- Pint check: all 22 new PHP files pass.
- `git diff --check`: passes.

## Phase 1B collaboration and tasks

### Database and permissions

- `project_collaborators`: ID, project/user foreign keys, sharing mode, timestamps.
  Membership is unique per project/user. The owner cannot be added as a collaborator;
  Form Request and locked service validation enforce this cross-table rule.
- `project_tasks`: ID, project and creator foreign keys, required title, nullable
  description, execution type, priority (default medium), status (default active),
  nullable scheduled date/time and separate due date/time, daily-widget flag
  (default true), timestamps, and `deleted_at`.
- `project_task_grants`: project, collaborator, and task identifiers, timestamps.
  Unique collaborator/task pairs prevent duplicates. Composite foreign keys require
  the membership and task to belong to the same project. Membership removal cascades
  to grants; task/project/user foreign keys restrict hard deletion to protect data.

Sharing modes:

- `all_tasks`: all non-deleted project tasks, including tasks created in the future.
- `selected_tasks`: explicit task grants plus all tasks authored by the collaborator.
  Authored-task visibility requires current membership and cannot be removed by
  replacing grants. Removing membership revokes all collaborator project/task access,
  including authored tasks. No admin bypass exists.

Owners alone list/manage collaborators or change project settings/status. Collaborators
can create tasks in active projects, and edit/archive/complete/soft-delete/restore
their own tasks while their project access remains valid. Owners manage all tasks.
Task `completed` is currently a definition status; it does not create an execution,
calculate recurrence, or implement Done Today.

All task/sharing writes lock the project first and recheck authorization and active
status within a transaction. Task writes then lock the task. This also serializes
them with Phase 1A status transitions. Sharing and grants are revalidated inside the
transaction. Schema constraints independently prevent duplicate memberships and
cross-project grants.

No hard-delete endpoint exists. Soft deletion preserves grants and task identity.
The deleted-task list is limited to the owner or the current member's own tasks;
all-task sharing alone does not expose other users' deleted tasks. Deleted tasks are
excluded from normal lists/details, and restored through the dedicated endpoint.
There is no deletion/restoration interval ledger yet; a future occurrence/history
phase must introduce one before relying on historical deletion eligibility.

### API

All routes require Sanctum. All child route bindings are scoped to the project.
`{collaborator}` means the membership ID, not the user ID.

| Method | URL | Behavior |
| --- | --- | --- |
| GET | `/api/projects/{project}/collaborators` | Owner-only membership list with safe user fields and granted task IDs. |
| POST | `/api/projects/{project}/collaborators` | Add existing user with `user_id`, required `sharing_mode`, optional `task_ids`. |
| PATCH | `/api/projects/{project}/collaborators/{collaborator}` | Change `sharing_mode`, optionally replace `task_ids`. |
| DELETE | `/api/projects/{project}/collaborators/{collaborator}` | Remove membership and its grants. |
| PUT | `/api/projects/{project}/collaborators/{collaborator}/tasks` | Replace selected grants with required `task_ids` array; empty clears explicit grants. |
| GET | `/api/projects/{project}/tasks` | Paginated visible tasks. |
| POST | `/api/projects/{project}/tasks` | Create task; title required. |
| GET | `/api/projects/{project}/tasks/{task}` | Visible non-deleted task detail. |
| PATCH | `/api/projects/{project}/tasks/{task}` | Owner/creator task update, including definition status. |
| DELETE | `/api/projects/{project}/tasks/{task}` | Owner/creator soft deletion. |
| GET | `/api/projects/{project}/tasks/deleted` | Paginated deleted tasks manageable by the requester. |
| POST | `/api/projects/{project}/tasks/{task}/restore` | Owner/creator restoration while project is active. |

New memberships require `sharing_mode` to be `all_tasks` or `selected_tasks`. Only
non-deleted tasks from the same project can be newly granted. Grant replacements
are limited to 500 IDs per request. Switching to all-task mode clears explicit
grants; switching back starts with supplied grants or an empty selection. Keeping
selected mode and omitting `task_ids` preserves its current grants.

Task creation accepts all task definition fields except project/creator/timestamps/
deletion metadata, which are server-controlled. Omitted execution type inherits the
current project default. The other defaults are medium priority, active status, and
`show_in_daily_widget=true`.

Dates use `YYYY-MM-DD`; optional times use 24-hour `HH:MM`. Each time requires its
corresponding date, including after partial updates. Clear both date and time to
remove an existing timed schedule/deadline. Date-only values remain date strings,
with null times; no midnight or timezone conversion is introduced. Scheduled and
due fields are independent; no ordering constraint between them is assumed.

Task list filters: `search` (title or description), `execution_type`, `priority`,
`status`, `sort`, `page`, and `per_page` (default 20, maximum 100). All statuses are
included unless filtered. Search and pagination totals are calculated only within
the authorized task scope. Responses remain flat, with execution type available
for future frontend grouping. Deleted lists support the same filters.

Default `sort=priority` orders urgent, high, medium, low, then relevant date/time;
`sort=date` puts relevant date/time before priority. Relevant date means due date
when present, otherwise scheduled date. Undated tasks sort last within that ordering;
date-only entries follow timed entries on the same date. This is only a sorting
convention; it does not assign a deadline time. Task ID descending breaks ties.

Actual task creation, edits/status changes, deletion/restoration, and sharing/grant
changes update `projects.last_activity_at`. Reads and no-op edits/grant replacements
do not. Existing project listing now includes current memberships, while owner-only
settings and status policies remain separate from read access.

### Phase 1B file manifest

Created:

- `app/Enums/ProjectSharingMode.php`, `app/Enums/ProjectTaskPriority.php`.
- `app/Models/ProjectCollaborator.php`, `ProjectTask.php`, `ProjectTaskGrant.php`.
- `app/Policies/ProjectTaskPolicy.php`.
- `app/Services/ProjectSharingService.php`, `ProjectTaskService.php`.
- `app/Http/Controllers/Api/ProjectCollaboratorController.php`, `ProjectTaskController.php`.
- `app/Http/Requests/Project/StoreProjectCollaboratorRequest.php`,
  `UpdateProjectCollaboratorRequest.php`, `SetProjectTaskGrantsRequest.php`,
  `StoreProjectTaskRequest.php`, `UpdateProjectTaskRequest.php`, `ListProjectTasksRequest.php`.
- `app/Http/Resources/ProjectCollaboratorResource.php`, `ProjectTaskResource.php`.
- `database/migrations/2026_10_25_000004_create_project_collaborators_table.php`.
- `database/migrations/2026_10_25_000005_create_project_tasks_table.php`.
- `database/migrations/2026_10_25_000006_create_project_task_grants_table.php`.
- `tests/Feature/Api/ProjectCollaborationTaskApiTest.php`.

Modified: `app/Models/Project.php`, `app/Models/User.php`,
`app/Policies/ProjectPolicy.php`, `app/Providers/AuthServiceProvider.php`,
`routes/api.php`, and this specification. No frontend or package changes.

### Phase 1B verification and limits

Only the four requested targeted scenarios were run:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit tests/Feature/Api/ProjectCollaborationTaskApiTest.php --stop-on-failure
```

Result: 4 tests passed, 64 assertions. They cover owner task management, selected-task
visibility/own-task retention, collaborator write restrictions, and access revocation
on membership removal (including an admin user). Essential duplicate/owner membership
and cross-project grant validation is covered within those scenarios. No full backend
suite was run for Phase 1B.

Production migrations/data/deployment were not touched. Production-engine concurrency
remains unverified. Recurring definitions store no recurrence rules or occurrences.
Scheduling history, executions, evaluations, progress notes, charts, daily-widget
rendering, and frontend remain deferred.

## Phase 1C.1 executions

Three new tables:

- `project_task_occurrences`: stable non-null key, schedule/deadline snapshots,
  pending/done/skipped projection, completion actor/time, and lock version.
  Unique `(task_id, occurrence_key)`; task/user foreign keys restrict deletion.
- `project_task_completion_events`: append-only meaningful done/undo/skip events,
  actor, performed-on DATE, recorded-at UTC timestamp, reversal link, and request ID.
  Unique occurrence/request pairs and reversal IDs; composite reversal foreign key
  requires the reversed event to belong to the same occurrence. Model updates and
  deletes throw; there is no history update/delete API. Direct SQL/query-builder
  writes must not bypass this application invariant.
- `project_task_execution_requests`: unique task/request receipts and payload hashes,
  including successful no-ops. A composite foreign key ties each receipt to its task's
  occurrence. This prevents an old no-op Done retry from completing work again after
  someone has undone it. Receipt writes and state/events share one transaction.

Under `/api/projects/{project}/tasks/{task}` (Sanctum; scoped task binding):

| Method | Suffix | Purpose |
| --- | --- | --- |
| GET | `/occurrences?from=YYYY-MM-DD&to=YYYY-MM-DD` | Persisted occurrences by scheduled date. |
| GET | `/occurrences/{key}` | Persisted occurrence state; missing occurrence returns 404. |
| POST | `/occurrences/{key}/done` | Done / persistent Done Today for an explicit date. |
| POST | `/occurrences/{key}/undo` | Restore pending, retaining and linking reversed history. |
| POST | `/occurrences/{key}/skip` | Explicit Skip; never inferred for missed dates. |
| GET | `/execution-history?from=YYYY-MM-DD&to=YYYY-MM-DD` | Events filtered by performed-on business date. |
| POST | `/complete` | Owner/creator explicitly completes a persistent task. |

Keys are `single` for one-time tasks and explicit `YYYY-MM-DD` for persistent/recurring
tasks. Mutation bodies require `request_id` (string, maximum 100) and `lock_version`
(integer, initially 0). Optional `performed_on` is a calendar date. It defaults to
the dated key, or the application-local current date for one-time Done/Skip. Undo
defaults to the reversed event's performed-on date and rejects a different date.
There is no age limit for retroactive entries. Reads never generate occurrences.
Both list APIs require a range of at most 366 dates and support `page`/`per_page`
(20 default, 100 maximum). Unscheduled single occurrences are included in occurrence
lists. Query different bounded ranges to retrieve arbitrarily old history.

Actions lock project, task, then occurrence and recheck current permissions. Any
collaborator with task access can execute or undo another collaborator's work.
Inactive projects and archived/soft-deleted tasks block execution. Completed tasks
block new execution actions, except Undo of a Done single occurrence (which reactivates
the one-time task) and harmless repeated single Done. Authorization is checked even
on idempotent replay; removed members cannot replay old requests.

Conflicting stale versions or request-ID reuse with changed actor/action/key/version/
performed-on input return 409. Repeating the current desired state is a successful
no-op, even with an older version; it creates no event and does not advance version
or project activity. Exact receipt replays return current occurrence state without
reapplying the original action. Each effective transition increments lock version,
appends its event, updates the projection, and records project activity atomically.

One-time Done synchronizes task status to completed; Undo returns it to active.
Generic task creation/PATCH must use Done to complete one-time work; PATCH cannot
bypass Undo. Archival remains independent. Unarchiving a single with a Done projection
restores completed status so it can then be undone. Legacy completed tasks without
occurrence history are not backfilled with invented events; their owner/creator can
reactivate them through task PATCH before recording executions. Execution type is
immutable once occurrences exist.

Persistent/recurring Done never completes the task definition. Permanent persistent
completion is an owner/creator setting operation and does not fabricate a dated event.
Occurrences retain schedule/time/deadline snapshots from their first materialization;
dated tasks use the selected date. Due dates are copied literally, never shifted by
an inferred recurrence rule. Date-only values remain DATE strings with null times.
Recorded/completed timestamps use UTC; calendar defaults use the application's
configured timezone (currently UTC; no project timezone exists).

Recurring dates are explicit, provisional input: no schedule is generated or claimed
to validate them. Versioned recurrence validation belongs to Phase 1C.2. Historical
inactive/deleted-interval eligibility is also not evaluated by this phase.

Files: added `ProjectTaskOccurrenceStatus`/`ProjectTaskCompletionAction` enums;
three migrations ending in `000007`–`000009`; occurrence/event/request models;
`ProjectTaskExecutionService`, `ProjectTaskExecutionController`, two execution Form
Requests, two API Resources, and `ProjectTaskExecutionApiTest`. Modified `ProjectTask`,
`ProjectTaskPolicy`, `ProjectTaskService`, routes, and this specification.

Targeted verification: `ProjectTaskExecutionApiTest` passed (5 tests, 64 assertions),
covering single Done/shared Undo/history, two persistent dates, hidden/revoked access,
duplicate/no-op retries and stale conflicts, and inactive projects. Only this test file
was run; an initial HTTP 201/200 response mismatch was corrected before rerunning it.
No full suite, production data changes, deployment, frontend, or packages. Production
database concurrency remains unverified by SQLite tests.

## Phase 1C.2 recurrence

- Added `project_task_schedule_versions`: unique task/version numbers, effective
  DATE intervals `[effective_from, effective_until)`, recurrence configuration,
  stable anchor, optional inclusive start/end dates and time, creator/timestamps.
- Added nullable `schedule_version_id` to occurrences with a composite foreign key
  ensuring the version belongs to that task. Recorded snapshots/history are retained.
- Added `project_task_deletion_periods` with deletion/restoration timestamps and
  actors. Task writes record these intervals in the same locked transaction.

All endpoints below require Sanctum and use `/api/projects/{project}/tasks/{task}`:

| Method | Suffix | Behavior |
| --- | --- | --- |
| POST | `/schedule` | Initial rule for an active recurring task; 409 if one exists. |
| PATCH | `/schedule` | New future version; 404 if no initial version exists. |
| GET | `/schedule` | Current effective version, or null. |
| GET | `/schedule-versions` | Paginated versions, newest first. |
| GET | `/occurrences?from=...&to=...` | Calculated recurring occurrences merged with recorded state. |
| GET | `/occurrences/{YYYY-MM-DD}` | Recorded occurrence or eligible calculated pending state. |

Only the owner/task creator configures schedules; task-access collaborators can
read them. Inactive projects/tasks and soft-deleted tasks reject configuration.
POST/PATCH use a complete rule definition: required `recurrence_type` is `daily`,
`every_n_days` (requires positive `interval_days`), `weekdays` (requires distinct ISO
`weekdays`, Monday=1 through Sunday=7), or `monthly` (requires `month_day`, 1–31).
Optional fields: `start_date`, `end_date` (`YYYY-MM-DD`), `scheduled_time` (`HH:MM`).
Omitted optional fields are cleared in the new version. Monthly days 29/30/31 skip
months lacking that day, including February 29 in non-leap years; no date clamping.

Initial versions take effect on their creation's local calendar date, without
retroactive rule generation. Edits take effect the next local calendar day. The
anchor is explicit `start_date`, otherwise the prior anchor or initial effective
date. Reactivation/restoration never resets it. Repeated edits on the same day
retain superseded future versions as empty effective intervals; they do not overlap.
Project/task locks serialize writers; interval overlap is prevented by the service,
not an independent database exclusion constraint. No direct schedule-table writes.

Queries remain bounded to 366 dates, paginated, and read-only. Virtual rows have
`id=null`, `is_recorded=false`, pending state/version 0, and their schedule version.
Recorded rows retain their identity and snapshots. List `is_scheduled` indicates
whether the date is currently eligible; previously recorded exceptions remain in
the response. Invalid new recurring execution dates return 422. Linked historical
occurrences or legacy rows with action history remain correctable even when newer
rules exclude the date. Legacy no-op rows without a schedule link or events do not
bypass new-date validation. No existing history is rewritten or deleted.

Calendar eligibility uses project timezone if present, otherwise application timezone
(currently UTC). Project status at the beginning of that local day controls eligibility:
an activation during a day resumes eligibility the following day; a deactivation
during a day excludes dates beginning the following day. No active status interval at
the day boundary means no calculated occurrence, including a project created later
that day. Deletion intervals use the same beginning-of-day convention. No backlog
is manufactured for excluded dates, and no automatic skips or cron jobs are needed.
Current inactive/deleted tasks expose recorded history rather than new virtual rows.

Legacy closed deletion intervals cannot be reconstructed. If a legacy task is
currently deleted, restoration captures its known `deleted_at` before clearing it;
unknown deletion actors remain null. One-time/persistent execution paths are unchanged.
Due fields still snapshot literal task deadlines; no recurrence deadline offsets or
timezone field/configuration UI are added.

Files added: `ProjectRecurrenceType`; schedule/deletion-period models; three migrations
`000010`–`000012`; `ProjectTaskScheduleService`, `ProjectTaskRecurrenceService`,
`SaveProjectTaskScheduleRequest`, schedule resource/controller, and
`ProjectTaskRecurrenceApiTest`. Modified task/occurrence models, task policy/services,
execution controller/resource, routes, this spec, and one Phase 1C.1 test fixture
(persistent task for its schedule-independent idempotency scenario).

Targeted recurrence tests: 5 passed, 59 assertions. No full suite, frontend/package
changes, production data changes, or deployment. Production-engine concurrency and
unknown pre-existing deletion history remain unverified/unrecoverable respectively.

## Phase 1D notes, evaluations, and templates

Schema (two migrations, `000013` and `000014`):

- `project_progress_notes`: project/author, required title/content/observation DATE,
  nullable updater, timestamps, SoftDeletes, and project/date journal index.
- `project_progress_note_tasks`: unique note/task associations. Composite foreign
  keys require the note and task to belong to the same project.
- `project_task_evaluations`: project/task, evaluation DATE, integer score 1–10,
  author, optional note, lock version, nullable active slot, withdrawal timestamp,
  and timestamps. Composite foreign keys require a linked note to include that task.
  Unique `(task_id, evaluation_date, active_slot)` allows one active score (`1`) and
  multiple suspended/withdrawn rows (`NULL`). Score range is enforced by CHECK on
  production engines supporting CHECK, and equivalent SQLite triggers in tests.
- `project_task_evaluation_revisions`: actor, previous/new score, previous/new note
  references, and UTC recorded timestamp. Initial creation and explicit withdrawal
  use a null previous/new score respectively. Rows are retained and append-only via
  Eloquent; no revision editing/deletion endpoint exists.

All routes use Sanctum and scoped project/task/note/evaluation bindings:

| Method | URL under `/api/projects/{project}` | Behavior |
| --- | --- | --- |
| GET / POST | `/notes` | Visible paginated journal / create. |
| GET / PATCH / DELETE | `/notes/{note}` | Read / explicit update / soft-delete. |
| POST | `/notes/{note}/restore` | Author/owner restoration while project is active. |
| GET | `/notes/trash` | Owner-only deleted-note journal. |
| GET / POST | `/tasks/{task}/evaluations` | Active visible evaluations / create. |
| GET / PATCH | `/tasks/{task}/evaluations/{evaluation}` | Visible record / explicit score or note-link update. |
| DELETE | `/tasks/{task}/evaluations/{evaluation}` | Explicit author/owner withdrawal; history is retained. |
| GET | `/tasks/{task}/evaluations/{evaluation}/revisions` | Paginated visible revision history. |
| POST | `/note-template/preview` | Resolve the saved project template without writing data. |

Owners see all notes. General notes are visible only to their author/owner; current
project membership is still required for collaborators. Linked notes require access
to every associated task, including for their author. Collaborators cannot read a
note linked to a deleted task until task access is restored. Owners retain access.
The same scope protects journal totals, search, details, task links, linked scores,
and revision references. Readers cannot edit another author's notes/evaluations.
All mutations recheck permissions and active project state after taking the project
lock; notes/evaluations are then locked inside the same transaction. No admin bypass.

Note inputs: required `title`, `content`, `noted_at` (`YYYY-MM-DD`), optional
`task_ids` (maximum 100 distinct accessible non-deleted tasks). PATCH accepts partial
fields; omitting task IDs preserves links, and an empty array makes a general note.
Task associations referenced by evaluation history cannot be removed (409); this
preserves the relational invariant rather than silently detaching evaluations.
Journal filters: `search` in title/content, `task_id`, `from`/`to` observation dates,
`general_only`, `page`, `per_page` (20 default, 100 maximum). Sort: observation date
descending, then ID descending. Trash supports the same filters and retains scores.

Evaluation POST requires `evaluation_date` and integer `score`, with optional
`note_id`. Duplicate active task/date returns 409, never an upsert. PATCH requires
expected `lock_version` and accepts score/note changes; stale versions return 409.
The date/task/author remain immutable; date corrections require explicit withdrawal
and a new evaluation. DELETE also requires `lock_version`. An accessible task can
be evaluated independently of its execution/completion state; the project must be
active. Actual updates append revisions and advance versions; no-op PATCH does not.

Deleting a note suspends its linked scores and advances their versions. Active list
and contribution scope excludes suspended/withdrawn scores, deleted notes, and deleted
tasks. No aggregate calculations are implemented. Restoration checks all task/date
slots before changing anything: a conflict returns 409 and leaves the note/scores
unchanged. An authorized user may explicitly withdraw the replacement evaluation,
then retry restoration. Withdrawn scores never reactivate. Score revisions survive
suspension, restoration, and withdrawal. Project activity changes only on mutations.

Templates reuse `projects.note_template` and existing owner-only project settings.
Preview accepts optional `date` and accessible `task_ids`. Literal `strtr` substitution
resolves `{date}`, `{tasks}` (titles separated by newlines), `{project}`, and `{user}`.
Unknown placeholders remain literal; substitutions are not recursive, content is
never evaluated, and output is plain text in JSON. A missing template previews as
an empty string. Dates remain DATE values; audit timestamps are UTC.

Files created: migrations `000013`/`000014`; models `ProjectProgressNote`,
`ProjectTaskEvaluation`, `ProjectTaskEvaluationRevision`; note/evaluation policies;
`ProjectProgressNoteService`, `ProjectTaskEvaluationService`; note/evaluation/template
controllers; Store/Update/List note requests, Store/Update evaluation requests,
`PreviewProjectNoteTemplateRequest`; three resources; and
`tests/Feature/Api/ProjectProgressNotesEvaluationApiTest.php`.
Modified: `Project`, `ProjectTask`, `AuthServiceProvider`, routes, and this spec.

Verification: only `ProjectProgressNotesEvaluationApiTest` was run (5 passed,
73 assertions). It covers general/multi-task visibility, score uniqueness and locking,
note suspension/conflicting restore/withdrawal, and cross-project rejection, including
database constraints. Pint passed all 25 relevant PHP files; whitespace check passed.
No full suite, production writes, deployment, packages, aggregates, or frontend.
Production engine/version and concurrency remain unverified; CHECK enforcement
requires an engine/version that supports it. Application validation remains mandatory.

## Phase 1E progress and chart API

Read-only Sanctum endpoints under `/api/projects/{project}`:

| GET suffix | Response inside `data` |
| --- | --- |
| `/progress/summary` | `project_id`, `progress_type`, `timezone`, `filters`, `completion`, `daily`. |
| `/progress/timeline` | Common metadata, `range`, chronological daily `series`. |
| `/progress/tasks/{task}` | Timeline plus `evaluations` and `executions` for one accessible task. |

Filters: optional `task_id`, `execution_type` (`one_time`, `recurring`, `persistent`),
`date=YYYY-MM-DD` (summary date / inclusive timeline end; defaults to local today),
`range=7|30|90|all` (timeline default 30), and `page` for `all`. A conflicting task
path/query returns 422; a hidden/deleted/foreign task filter returns 404. Task detail
uses the existing task policy. Removed members cannot access any progress endpoint.

`all` pages backwards through at most 90 calendar dates per response, oldest first
within each page. `range` contains `from`, `to`, `selection`, `page`, `last_page`,
and nullable `next_page`. Keep the same explicit `date` when following pages.
Only accessible non-deleted tasks/history determine the lower bound; out-of-range
pages return 422. No unlimited event/evaluation history is loaded.

Completion counts task definitions by status, independently of occurrence counts:
`total_visible_tasks` includes archived tasks; `total_tasks`, `completed_tasks`,
and `remaining_tasks` exclude archived tasks. `archived_tasks` is separate.
`by_execution_type` repeats these counts for each execution type. Completing a
recurring/persistent execution does not complete its definition. Completion has
no percentage. Currently deleted tasks are excluded from every endpoint, including
historical dates; after restoration, known deletion intervals still exclude schedules.

Each daily row contains:

```json
{
  "date": "2026-01-02",
  "scheduled_occurrences": 4,
  "completed_scheduled_occurrences": 2,
  "skipped_scheduled_occurrences": 1,
  "completion_percentage": 50,
  "done_occurrences": 3,
  "evaluation_average": 6.5,
  "evaluation_count": 2
}
```

`summary.daily` uses the same row structure. All metrics are returned for every
progress mode; `progress_type` tells the client which metric is primary. A zero
denominator yields null percentage. Missing scores yield null average and count 0.
Scores use the existing active/visible scopes: withdrawn/suspended scores, deleted
notes, and notes referencing any inaccessible task never contribute. The existing
unique active task/date constraint prevents duplicate contributions. Scores remain
independent of completion and are not inferred from status or inactive-day schedules.

Scheduling reuses versioned recurrence, beginning-of-local-day project status and
known task deletion intervals. Skips retain their scheduled denominator. One-time
schedules use recorded snapshots when available, otherwise the task's scheduled date.
An explicitly scheduled persistent task contributes only on its scheduled date;
unscheduled persistent Done Today contributes only to `done_occurrences`.
Overdue work never moves to a later day's denominator. Inactive task definitions
retain recorded occurrence history without generating new virtual occurrences;
recorded recurring dates still undergo historical schedule/lifecycle eligibility.
Archived task evaluations remain available, subject to note visibility.

`done_occurrences` reports current Done state, not the number of Done button clicks:
dated executions use their occurrence date; single executions use the latest action's
`performed_on`. Undo removes the contribution. The single's business date may differ
from its scheduled date, so Done counts can differ from scheduled completions.
Task `executions` are chronological projection rows (`date`, `scheduled_date`,
`occurrence_key`, `status`, `is_recorded`, `is_scheduled`, `lock_version`), including
virtual pending schedules. For singles, `date` uses the latest action's business date
or the scheduled date if no action exists. `evaluations` contains active score rows
(`id`, `date`, `score`, `lock_version`). These are current projections, not an
as-of reconstruction; the existing `/tasks/{task}/execution-history` endpoint retains
the full append-only Done/Undo/Skip audit. Retroactive actions/score corrections
therefore update past chart values without rewriting audit history.

No schema changes or occurrence writes. Queries batch task schedules/deletion periods,
range-bound occurrence projections and active scores. Only the latest event per
single is fetched to recover its business date; dated occurrences need no events.
`all` bounds use scoped MIN queries. Work grows with visible tasks × at most 90 days;
very large projects may eventually need additional task batching. Existing recurrence
APIs retain their default behavior. Project-local dates currently fall back to the
configured application timezone (UTC); no timezone field/settings were introduced.
Unknown legacy deletion intervals and production MySQL concurrency remain existing
limitations. SQLite tests do not verify production locking behavior.

Files added: `ProjectProgressService`, `ProjectProgressController`,
`ProjectProgressRequest`, `ProjectProgressResource`, and `ProjectProgressApiTest`.
Modified: `ProjectTaskRecurrenceService` (batched projections/shared day eligibility),
`routes/api.php`, and this specification.

Verification: five new focused tests pass (99 assertions); together with the existing
recurrence tests, the targeted run passes 10 tests / 158 assertions. No full suite,
production data changes, deployment, migrations, Home widget, or frontend changes.

## Phase 2A frontend foundation

Authenticated routes, without admin restriction: `/projects`, `/projects/create`,
`/projects/:id`, `/projects/:id/edit`. Projects follows Home in the drawer. The list
uses category/activity ordering, pagination and a collapsed lazy-loaded inactive
section. Creation/settings reuse existing form controls. Detail provides status,
description, optional real Phase 1E summary, owner-only settings/status actions and
Tasks/Progress/Charts placeholders. Inactive projects allow owner reactivation only.

Integrated API: GET `/project-categories`, GET/POST `/projects`, GET/PATCH
`/projects/{id}`, PATCH `/projects/{id}/status`, optional GET
`/projects/{id}/progress/summary`. The shared API/auth client handles tokens and
session expiry; screens handle 403/404/409 and field errors. No backend behavior
changed. List resources lack task counts: cards omit them to avoid a summary request
per card. Detail shows actual counts when Phase 1E is available and remains usable
if its summary endpoint returns 404.

New mobile files: `types/projects.ts`, `services/projectsApi.ts`,
`utils/projectPresentation.ts`, `components/projects/ProjectCard.vue`,
`views/projects/{ProjectsView,ProjectFormView,ProjectDetailView}.vue`, `projects.css`.
Updated menu/router and global base CSS. iOS controls have at least 16px text without
changing viewport or user zoom. Icon choices use emoji; unknown stored icon names
fall back to a folder visually and are preserved in settings.

Verification: Vue TypeScript, ESLint for changed TS/Vue files and Vite build-only
pass. No test suite was run. iPhone visual/interaction review remains manual.
No task CRUD, journal, charts, collaboration management, Home widget, packages,
production data changes or deployment. Stop after Phase 2A for visual review.

## Phase 2B task management frontend

The Tasks tab now provides three collapsible execution-type sections, each using
server filtering and 10-task pagination. Filters: search, status (active default),
priority. Cards show dates, priority, definition/execution state and a single upcoming
pending recurring occurrence. Upcoming lookup is bounded to 366 days; it never renders
all future occurrences. History defaults to 30 days, supports date ranges up to 366
days and paginated chronological events. History identifies actors by ID (or “Tu”);
the existing event resource does not provide actor names.

Create/edit forms support task metadata, independent date/time fields and widget
visibility. Recurring tasks support daily/every-N-days/weekday/monthly schedules,
optional bounds/time and explicit future-version confirmation. Editing uses the latest
schedule version, including a pending future version; detail also shows the effective
current rule. Monthly missing dates are skipped by the backend, never clamped in UI.
Execution type is chosen at creation; the edit form preserves it because history can
make type changes invalid. No frontend recurrence calculator is introduced.

Task and schedule writes are separate: after a partial create, the form retains the
returned task ID and retries only schedule creation. A preflight schedule-version read
prevents retrying an already-applied initial schedule POST. A task POST with an unknown
network/server outcome asks the user to check the list, rather than automatically
creating another task. These CRUD/schedule endpoints have no idempotency contract;
no cross-request atomicity or silent rollback is claimed.

Done/Undo/Skip use `single` or an explicit calendar date, current `lock_version` and a
random `request_id`. An uncertain execution response retains the command for an exact
idempotent retry; a 409 refreshes state without replaying a new action. Writes refresh
cards/list/project summary. Owner/creator controls gate edits, archive, soft delete,
restore and permanent persistent completion. All visible-task collaborators can execute.
Inactive projects/tasks block executions, with the backend's explicit completed-single
Undo exception. Restoring a deleted task preserves its definition status; reactivating
an archived single with Done state restores completed status, then Undo makes it active.
No permanent deletion exists.

Reused endpoints under `/api/projects/{project}/tasks`: GET/POST collection;
GET/PATCH/DELETE `/{task}`; GET `/deleted`; POST `/{task}/restore` and `/complete`;
GET `/{task}/occurrences` / `/{key}`; POST occurrence `/done`, `/undo`, `/skip`;
GET `/{task}/execution-history`, `/schedule`, `/schedule-versions`; POST/PATCH
`/{task}/schedule`. The shared API/auth client handles authentication; views handle
403/404/409/422/network errors. Dates remain calendar strings and separate times.
Timezone comes from the optional existing summary, with the documented UTC fallback.

Small backend integration addition: `sort=overdue` orders active overdue definitions
first (deadline, otherwise scheduled date; timed same-day entries become overdue
only after their time), then priority/date before pagination. Existing default sorts
are unchanged. It uses application timezone, matching the current backend convention.
This avoids sorting only a loaded page and missing lower-priority overdue tasks on
later pages. No schema or other backend behavior changes.

Files added: mobile `types/projectTasks.ts`, `services/projectTasksApi.ts`,
`utils/projectTasks.ts`, `components/projects/{ProjectTasksTab,ProjectTaskCard,
ProjectTaskForm}.vue`, `projectTasks.css`, `ProjectTaskFlows.spec.ts`; backend
`ProjectTaskSortingApiTest.php`. Modified Project detail, project summary type,
Task controller/list request and this document.

Verification: targeted frontend tests 2 passed; backend sorting test 1 passed,
8 assertions. TypeScript, relevant ESLint and Vite build pass. No full suite,
new packages, production writes/deployment, journal, charts, Home or bulk actions.
iPhone visual/functional testing remains manual. Stop after Phase 2B for review.

## Phase 2B.1A weekly calendar and compact cards

Tasks defaults to Calendar, with the existing Listă mode retaining search, filters,
per-type pagination, management and Trash. The seven-day calendar runs Monday–Sunday,
starts on the device-local current date, supports adjacent weeks and an Astăzi reset,
and displays Romanian calendar dates/ranges. Date arithmetic preserves literal date
strings across month/year and DST boundaries; no browser UTC date parsing is used.

Calendar loads visible, non-deleted task definitions in explicit 20-task pages,
including inactive statuses for reading. One-time tasks match their scheduled date;
recurring tasks require `is_scheduled=true` from the existing occurrence range API;
persistent tasks match recorded dated activity or an explicit scheduled date.
Undated one-time/persistent tasks and recurring tasks with no schedule are available
in a separate collapsed section. Reads never materialize occurrences.

There is no project-level calendar/batch endpoint or server task-date filter. The
frontend uses one seven-day occurrence query per loaded recurring/persistent task,
up to four concurrent task loads, and caches results for that week. A schedule-version
read distinguishes an unscheduled recurring task when no eligible weekly row exists.
Day selection reuses prefetched occurrence states; single occurrences use the existing
logical-single read. Pagination is explicit: the UI warns when calendar coverage is
partial and offers another 20 tasks. It does not claim a complete day until all task
pages have been loaded. Changing week replaces the week cache; mutations reload it.
Recurring eligibility/lifecycle/version rules remain entirely server-calculated.
Single/persistent display follows the existing backend's definition/activity semantics.

Compact ProjectTaskCard reuses the same mutation, authorization, retry, form and
history logic. Collapsed cards target roughly 96–120px with title, priority, compact
dates, execution state and Done/Undo; ellipsis expands details, including Skip and
existing recurrence/history/settings. Long content/errors or expanded cards may grow.
Detailed next-occurrence lookup retains its existing bounded 366-day behavior.
After a mutation, cards bypass prefetched state to fetch the authoritative projection.
No new calendar status colors, execution flows, backend functionality or dependencies.

Files: added `ProjectTaskCalendar.vue`; modified `ProjectTasksTab.vue`,
`ProjectTaskCard.vue`, `projectTasks.css`, `types/projectTasks.ts` (optional type filter
and page size for the existing API utility), and this document.
Verification: TypeScript, relevant ESLint and Vite build pass. No tests run, production
changes or deployment. iPhone visual review remains manual. Stop after Phase 2B.1A.

## Phase 2C progress journal and evaluations UI

Progress shows date-grouped, paginated notes with search, observation-date, task
and general-only filters. The on-demand form supports multiple tasks, optional
scores and plain-text template preview/application. Blank titles become
`Progres · date` because the existing backend requires a title. Task details open
Progress with that task preselected; execution actions are unchanged.

Project creation/settings also load and save `note_template` through the existing
POST/PATCH Projects API. The optional textarea explains `{date}`, `{tasks}`,
`{project}` and `{user}`; clearing it saves null. Owner-only active-project editing
is unchanged. In the note form, `Aplică template` fetches the current server preview
and asks for confirmation before replacing nonempty content.

Visibility remains server-scoped. Author names use known user/owner data, with
`Utilizator #ID` otherwise (resources expose author IDs only). Owner/author controls
respect active-project restrictions. Owner-only Trash lists deleted notes; an author
can restore their just-deleted note in the current session. The backend has no
collaborator Trash listing. Restore conflicts never withdraw another score.

Evaluations can also be created independently of notes. Existing scores require an
explicit edit with their displayed `lock_version`; withdrawal sends that version in
the DELETE body. Revision lists are lazy and paginated. Active evaluation lists do
not discover withdrawn records; revisions remain available for known IDs where
authorized. Dates remain calendar strings; timestamps are display-only.

Note and score writes use separate existing endpoints, so they are not one atomic
operation. The form reports partial success, freezes saved note fields and remembers
confirmed scores to avoid duplicate writes on retry. A conflict never becomes an
automatic upsert. Unknown note-creation outcomes require checking the journal.
Unchanged task associations are omitted on edit to preserve deleted-task links;
the server rejects removal of associations referenced by evaluation history.

Endpoints under `/api/projects/{project}`: GET/POST `/notes`,
GET/PATCH/DELETE `/notes/{note}`, GET `/notes/trash`, POST `/notes/{note}/restore`,
POST `/note-template/preview`; GET/POST `/tasks/{task}/evaluations`,
GET/PATCH/DELETE `/tasks/{task}/evaluations/{evaluation}` and GET its `/revisions`.
Task selection reuses the existing scoped, paginated task list.

Added mobile progress types/API/helper, two focused helper tests, task picker,
Progress tab, note form, evaluation card/panel and progress CSS. Modified Project
detail, task card/calendar/tab event forwarding and shared API DELETE-body support.
No backend functionality, schema, dependencies, Charts or Home changes.
Verification: TypeScript, relevant ESLint, Vite build and the two focused tests pass.
No full suite or production deployment. iPhone visual review remains manual.

## Phase 2D charts and analytics UI

Charts uses the existing authenticated progress summary, timeline and task analytics
endpoints. Project detail shares its already-loaded summary to avoid another read.
The default interval is 30 days; 7/90 days and paginated All are supported. All
navigates backwards in pages of at most 90 dates, with a fixed server-local end
date and independent project/task page controls. Statistics explicitly describe
the displayed page, rather than claiming a global all-history total.

Current task counts use backend status-based entity counts, excluding archived
tasks from current workload. No task percentage is invented. Daily completion
uses the backend's eligible scheduled numerator/denominator; unscheduled days
remain null. Period completion is the sum of completed scheduled occurrences
divided by scheduled occurrences, never a mean of daily percentages. Period scores
use daily means weighted by evaluation counts, equivalent to the arithmetic mean
of available evaluations. Missing scores remain null. Backend scopes control all
visibility, deleted-note suspension, withdrawals, archived history and recurrence.

Lightweight SVG charts display daily Done counts, eligible completion ratios and
scores on a fixed 1–10 scale. Nulls split lines; tap or the accessible date selector
shows exact values. Task analysis always supports scores, including in completion
projects. Recorded execution counts exclude virtual pending projections, and a
bounded detail list shows current recorded execution states. It is not an event
audit or button-click count; Done/Undo cycles are not additional completions.

Known limitation: the existing backend stores no complete task-definition status
history, so a daily completed-task-entity trend cannot be reconstructed accurately
for all execution types. The UI explains this and displays the separately labeled
current-Done occurrence series, never an inferred completed-task trend. Retroactive
changes update these current projections. Project timezone continues to use the
existing API's application-timezone fallback. No backend/schema or dependencies
were changed.

Files: new `ProjectChartsTab.vue`, `ProjectAnalyticsPanel.vue`,
`ProjectMetricChart.vue`, `projectCharts.css`, `types/projectCharts.ts`,
`services/projectChartsApi.ts`, `utils/projectCharts.ts` and its three focused tests.
Modified Project detail, optional summary breakdown typing and this document.
Verification: TypeScript, relevant ESLint, Vite build and three targeted mapping/API
tests pass. No full suite, production writes or deployment. Stop for iPhone review.

## Phase 2E Home daily tasks widget

`GET /api/projects/daily-tasks` is a read-only Sanctum aggregate. It accepts
`section=active|completed`, `page` and `per_page` (default 20, maximum 50). Response
`data` contains `date`, `timezone`, `summary: {scheduled, completed}`, per-section
`counts`, `rows: [{section, task, occurrence}]`, the referenced `projects`, and
`pagination: {page, last_page, total}`. Summary/counts cover all matching widget
tasks, independently of the displayed page. GET never creates occurrences.

The query uses existing task visibility and only active projects, non-deleted
tasks, and `show_in_daily_widget=true`. Task definitions are processed in batches
of 200 with eager-loaded project/lifecycle/schedule context. Only today's dated
occurrences and logical singles are read; latest single business dates use a
batched query, without loading audit logs or expanding previous missed dates.
Work scales with relevant visible task definitions, not historical day count.
Very large projects may eventually need additional database-side schedule filtering.

Sections are mutually exclusive, ordered Restante, Astăzi, Permanente, Finalizate;
each sorts urgent/high/medium/low, then date and ID. Only one-time tasks become
overdue, using deadline (otherwise scheduled date/time) from the current editable
definition, so rescheduling does not reuse an obsolete occurrence snapshot. Due-only
one-time tasks can appear today but do not count as scheduled. Recurring tasks
require today's versioned eligibility, respecting project/deletion periods. Old
missed recurring dates never become overdue today. Active persistent tasks appear
in Permanente until Done Today moves them to Finalizate; permanently completed or
archived persistent definitions are excluded. Completed singles appear if scheduled
today or their latest Done business date is today; old completed tasks do not pile up.

The numerator counts current Done states among today's eligible scheduled widget
occurrences. Skip remains in the denominator. Undated persistent tasks do not count;
explicitly scheduled persistent tasks count on that date. No schedules means a
neutral message. Timezone uses the existing application fallback (currently UTC);
no separate project timezone setting is introduced. Existing beginning-of-day
lifecycle semantics remain unchanged, including same-day project reactivation.

Home shows the first five active rows, expands the loaded page via Vezi toate,
then offers bounded additional pages. Finalizate starts collapsed and loads lazily.
Existing compact task cards retain idempotency, optimistic locking, conflict/error
handling and task details. Project names link to their project. Note creation reuses
the existing form with the task and today's date; Quick Add Task loads active project
choices only on demand and defaults the existing task form to today. Mutations
refresh the widget summary/list; midnight/foreground checks update the calendar day.

Added backend request/controller/service, two focused API tests; added mobile daily
types/API/widget and one focused widget test. Modified Home, the existing Home test
isolation, optional initial task-form date, API routes and this document. No schema,
core execution/recurrence changes, dependencies or deployment. Verification:
TypeScript, relevant ESLint and Vite build pass; 2 backend tests (52 assertions),
the new widget test and 3 existing targeted frontend tests pass. No full suite.
Stop after Phase 2E for iPhone review.
