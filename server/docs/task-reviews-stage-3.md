# Task Reviews — Stage 3

## Installation

Apply **either** migration `2026_11_04_000002_add_task_reviews.php` **or**
[manual MySQL SQL](sql/task-reviews-stage-3.sql), once, before deploying this code.
The SQL can be pasted into phpMyAdmin/cPanel and does not register a migration-ledger
entry. No migrations, SQL or data writes were executed during implementation.

`project_tasks.review_mode` defaults to `single`, including existing tasks.
`project_task_reviews` associates an existing progress note with exactly one stored
task occurrence and its author (`user_id = note.created_by`). The note retains all
existing content, observation DATE, UTC timestamps, templates, Note Type snapshot,
Custom Field values and optional task evaluations.

Composite FKs enforce same-project task/note and same-task occurrence relationships.
Unique note ID prevents using one note for multiple reviews. Unique occurrence+slot
uses slot 0 in Single and author ID in Multiple; occurrence+author is additionally
unique in both modes. Slots survive note soft deletion; restoration reuses the same
note/slot. Changing mode rekeys existing slots under the same project/task locks.
Multiple → Single returns 409 if any occurrence has more than one review, including
reviews in Trash. No reviews are discarded to satisfy a new limit.

Legacy notes are **not automatically converted** into occurrence reviews: their
observation date does not reliably identify an occurrence, especially for multi-task
notes. They remain intact, searchable and editable under their existing permissions.
Ordinary Progress notes remain distinct from explicit task reviews. No new note
storage system is introduced.

## API

All endpoints require Sanctum and existing project/task visibility.

- Task POST/PATCH accepts `review_mode: single|multiple`; Task Resources return it.
  Existing task owner/creator management permissions apply.
- `GET /api/projects/{project}/tasks/{task}/reviews?occurrence_key=single|YYYY-MM-DD&page=1`
  returns paginated progress-note resources (20/page), own review first, plus
  `review_context: {mode, can_create}`. Active reviews are visible to task readers.
  A user's own deleted review is also returned to allow author restoration; another
  user's deleted review content is excluded. No history expansion occurs.
- Create via existing `POST /api/projects/{project}/notes`, adding
  `review_task_id` and `review_occurrence_key`; `task_ids` must equal `[review_task_id]`.
  Other required note fields and optional `note_type_id`/`values` remain unchanged.
- Existing note GET/PATCH/DELETE/restore routes apply. Note resources now include
  `author: {id, username}` and nullable `review: {task_id, occurrence_key, user_id}`.
  Review identity/author/task association cannot be reassigned through PATCH.

The note and review reservation are created in one transaction under the existing
parent-project lock; failed reservations roll back note content/values and activity.
The same lock serializes status changes, membership revocation and mode changes.
Duplicate or unavailable slots return 409. Unique indexes provide a final duplicate
barrier. No status endpoint or recurrence schedule behavior was changed.

The first review requires the stored occurrence to be Done or Skipped. A pending
occurrence with no review has no Add Review action. Once an occurrence has reviews,
Multiple can still accept another eligible author's first review after Undo; existing
authors can edit their review regardless of occurrence status. Single remains
occupied by the first author. Archived tasks cannot receive new reviews; inactive
projects remain read-only. Recurring and persistent identities use the selected
calendar date; one-time tasks use the existing logical `single` occurrence.

Only the review author can edit, delete or restore it, including when another user
owns the project. Existing task-reader visibility applies to reading. Custom Fields
are protected by the same note policy. Evaluations linked to a review cannot be
attached, edited or withdrawn by a different review author, even through their own
API. Ordinary note/evaluation permissions remain unchanged.

## UI and bounds

Task Editor has Review Mode. Calendar/List/Home task cards show one compact
icon action: Add/Edit/View Review as permitted, with accessible labels. When several
reviews/actions are available they are grouped in the review panel. The author is
shown and only their edit action is available. The existing mobile note editor is
reused; the review task link is fixed. Observation date remains a separate field,
so editing it never changes review identity or frees a slot.

The existing Progress timeline also displays reviews and respects author-only
editing. Own deleted reviews can be restored from the review panel; slots are not
released through soft deletion. The existing owner Trash view remains available,
but owners cannot modify another author's review.

One bounded review-list request is made per displayed task/selected occurrence;
additional pages are fetched only on request. There is no batch review-summary
endpoint in this stage. Existing numeric evaluations remain independent and keep
their one-active-score-per-task/day uniqueness; Multiple reviews do not introduce
per-user numeric evaluation slots. Custom Field values remain per note.

## Files

New:
- `app/Models/ProjectTaskReview.php`
- `app/Services/ProjectTaskReviewService.php`
- `app/Http/Controllers/Api/ProjectTaskReviewController.php`
- `database/migrations/2026_11_04_000002_add_task_reviews.php`
- `docs/sql/task-reviews-stage-3.sql`, this document
- `mobile/src/components/projects/ProjectTaskReviews.vue`

Modified:
- `app/Models/ProjectTask.php`, `ProjectProgressNote.php`
- `app/Services/ProjectTaskService.php`, `ProjectProgressNoteService.php`, `ProjectTaskEvaluationService.php`
- `app/Policies/ProjectProgressNotePolicy.php`, `ProjectTaskEvaluationPolicy.php`
- `app/Http/Requests/Project/StoreProjectTaskRequest.php`, `StoreProjectProgressNoteRequest.php`, `UpdateProjectProgressNoteRequest.php`
- `app/Http/Resources/ProjectTaskResource.php`, `ProjectProgressNoteResource.php`
- `routes/api.php`, `docs/projects.md`
- `mobile/src/components/projects/ProjectTaskForm.vue`, `ProjectTaskCard.vue`
- `mobile/src/components/projects/ProjectProgressNoteEditor.vue`, `ProjectProgressNoteForm.vue`, `ProjectProgressTab.vue`
- `mobile/src/services/projectProgressApi.ts`
- `mobile/src/types/projectTasks.ts`, `projectProgress.ts`

## Manual verification

No automated tests were created, modified or run. Static compilation/lint and PHP
syntax checks only; functional/concurrency/device verification remains manual.

1. Apply SQL or migration. Create a task: Single is the default. To Do without a
   review hides Add. Done/Imposibil enables it. Save a review with a Note Type and
   numeric/SELECT Custom Fields; reload and verify saved values and author.
2. In Single, another accessible user can read but cannot edit/delete/restore the
   review, including when that user is project owner. Direct note/field-value API
   writes must fail. Two simultaneous authors creating the same review yield one
   success and one 409; no orphan note/value records should remain.
3. In Multiple, two users each create one review for the same occurrence. A second
   review by either author returns 409. Own edit remains possible after Undo. Other
   users can view reviews and add their own when their slot is available.
4. On recurring/persistent tasks, repeat on two selected dates: review identities
   remain independent. Changing a review's observation date does not free its slot.
   Note saving does not change task status; status changes do not delete reviews.
5. Change Single → Multiple: existing author remains. Multiple → Single is allowed
   only with at most one review per occurrence. Conflicts preserve every note.
6. Soft-delete own review: its slot stays occupied. Restore through its task panel;
   existing evaluation conflicts remain explicit 409. Confirm historical ordinary
   notes, membership revocation and inactive-project read-only restrictions remain.

## Single Review soft-delete correction (2026-11-08)

This correction supersedes the original Single slot-reservation behavior above.
Multiple still reserves an author's slot after soft deletion, unchanged.

Apply **either** migration
`2026_11_08_000001_release_soft_deleted_single_review_slots.php` **or** the separate
[manual MySQL SQL](sql/single-review-soft-delete.sql), once, after the existing
Stage 3 schema and before deploying the updated backend/frontend. No migration or
SQL was executed during implementation. The old schema's two UNIQUE indexes block
recreation regardless of frontend eligibility; both must be extended as supplied.

A nullable `active_slot` (`'1'` by default) is added to both reservation indexes.
Single live reviews use `'1'`; soft-deleted Single reviews use NULL. Multiple uses
`'1'` for both live and deleted reviews, retaining its existing one-per-author rule.
The upgrade releases existing Single tombstones only. The note, review association,
author, occurrence, Custom Fields and snapshots stay intact for audit.

Backend eligibility ignores deleted notes only in Single. Deletion releases the
Single reservation in the existing project-lock transaction. Restoration reserves
it again; if another live Single review occupies the occurrence, restoration returns
409 and leaves the deleted note intact. Existing evaluation restoration conflicts
also remain 409 and roll back the reservation change. Creation/restoration reconcile
old deleted Single reservations under the same lock. TO_DO still blocks NEW review
creation; task/occurrence status is never changed by this correction.

Frontend own-review detection ignores Single tombstones when deciding Add/Edit.
Single listing puts the live review before audit history, so even a long paginated
history cannot hide the active review or distort its badge count. Multiple listing
and eligibility remain unchanged. Authors retain access to their own deleted notes
under the existing visibility rules; no private content is newly exposed.

Mode changes preserve all audit rows. Multiple→Single keeps the prior conflict
rule. Single→Multiple returns 409 if the new Single history contains multiple
reviews by the same author for an occurrence, because the unchanged Multiple
reservation rule cannot represent that history. It never drops/merges records.
Likewise, migration rollback refuses conflicting history instead of deleting it.

Modified code: `ProjectTaskReviewService.php`, `ProjectProgressNoteService.php`,
`ProjectTaskReviewController.php`, `ProjectTaskReviews.vue`; new migration and SQL
above. No automated tests were created, changed or run.

Manual iPhone verification:

1. With an active project, create a Single review on Done; soft-delete it through
   the existing note action. Open task Reviews again: Add Review must be available.
2. Create another review for the same occurrence, with the same author; task status
   must remain Done. Repeat deletion/recreation and check live review count is 0/1.
3. Confirm old notes remain in audit/Trash with their saved values and occurrence
   links. Restore an old note while a live replacement exists: explicit 409, neither
   note overwritten. With the slot free, restoring one eligible old note succeeds.
4. Repeat with a collaborator who has task access. Another author's LIVE Single
   review still permits View only. Each author's deleted-note visibility is unchanged.
5. Repeat on two recurring dates; deleting one occurrence's review must not free or
   occupy another. Undo to TO_DO must still prevent creation of a new review.
6. Multiple: deleting one's review still does not enable creating another for that
   author; restoring it remains possible under the original evaluation constraints.
7. Confirm restoration conflicts and repeated save taps preserve audit/history and
   never leave more than one live Single review for the same occurrence.
