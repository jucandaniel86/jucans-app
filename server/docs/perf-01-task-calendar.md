# PERF-01 — Projects task calendar

Deploy backend and frontend together (backend first). No new migration, SQL,
package or rate-limit change is needed. The existing Single soft-delete migration
is a prerequisite from the preceding feature, not part of PERF-01.

## Read contract

`GET /api/projects/{project}/task-calendar?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`

Requires existing project access; task and note visibility use existing scopes.
Ranges are inclusive and limited to 31 dates (the UI requests seven).

```json
{
  "start_date": "2026-11-02",
  "end_date": "2026-11-08",
  "data": [{
    "task": { "id": 34, "execution_type": "recurring", "review_mode": "single" },
    "has_schedule": true,
    "current_rule": {},
    "latest_rule": {},
    "occurrences": [{
      "id": null,
      "occurrence_key": "2026-11-05",
      "scheduled_date": "2026-11-05",
      "status": "pending",
      "is_recorded": false,
      "is_scheduled": true,
      "lock_version": 0,
      "reviews": {
        "data": [],
        "meta": { "current_page": 1, "last_page": 1, "total": 0 },
        "review_context": { "mode": "single", "can_create": false, "active_count": 0 }
      }
    }]
  }]
}
```

Task/occurrence/rule/note objects above are abbreviated. They use the existing
resources, including note snapshots, field values and current configuration.
Unrecorded occurrences retain null IDs and version 0; GET never creates records.
One-time tasks use `single`, recurring/persistent tasks use date keys. Undated
items remain available. One-time display placement still uses the task's editable
scheduled date, rather than an old occurrence snapshot.

The backend batches lifecycle periods, schedule versions, occurrences, review
reservations and the first 20 visible notes per occurrence. It passes preloaded
records into the unchanged recurrence algorithm and the existing review eligibility
service. Deleted notes are retained for audit; other authors' deleted notes are
not exposed. Multiple reservations still include deleted notes, while Single
eligibility considers active notes only. All reservations participate in eligibility,
not just the visible first page.

## State and events

`ProjectTaskCalendar` owns the interval rows. It makes one aggregated request on
mount/week change and deduplicates concurrent loads of the same interval. Stale
responses are ignored. Day changes within the week do not fetch data. The card's
execution-date picker delegates date selection to the calendar as well: another day
in the week uses existing props, and an outside-week date loads that week once.
Reviews and commands therefore always refer to the same selected occurrence.

TaskCard/Reviews use supplied calendar props. Their existing standalone loading
remains available in Task List/Home. Calendar child watchers only adopt supplied
data, never initiate automatic HTTP reads. Explicit detail/history/next-occurrence
and review-page actions are delegated to the calendar through `calendarRead`.
They retain their existing endpoints and pagination. Opening details can therefore
make reads, but navigating a week or opening the Reviews popup does not.

The calendar response contains all relevant visible task definitions. The existing
"load another 20 tasks" interaction reveals the next local block without another
network request; review histories remain server-paginated, 20 notes per page.
There is no global cache. Very large projects can have a substantial aggregate
payload; review payloads are capped per occurrence, but task definitions and review
reservation metadata are not silently truncated.

Note create/update/restore responses now optionally include `data.review_context`
with authoritative eligibility. The reused form/editor emits the saved note;
confirmed evaluation writes are merged into it. `reviewChanged` upserts that note
in the correct task/occurrence, updates totals and context, and preserves loaded
history. It does not call calendar reload or project summary. Partial/uncertain
save errors can explicitly refresh only the affected review listing.

Occurrence commands keep existing request IDs, receipts and lock-version rules.
Their responses add top-level `task` and `review_context`. `occurrenceChanged`
merges the returned occurrence/status/task/eligibility locally, retaining reviews
and the projection's `is_scheduled`. Project header summary can refresh separately
following a status change. Successful review edits do not request the summary.

Structural task changes affecting displayed rows reload the interval once, without
first fetching the task/occurrence/rules in the child. Creation of a dated one-time
task outside the interval skips that reload. Recurring/persistent structural changes
are conservatively reloaded because they can affect projections/undated placement.
Conflicts (e.g. 409) and explicit retry/reload can read fresh state. They do not
change the recurrence or review rules.

## Manual verification (no automated tests)

1. Open Network, filter Projects requests. Initial calendar load and each week
   navigation must send one `task-calendar` GET and no per-task automatic reads.
2. Select every day of the loaded week, open Reviews, and reveal another task page:
   no reads. Expand details deliberately: existing detail reads are allowed.
3. Create/edit/restore a review: mutation response updates note, snapshots, values,
   badge, author, pagination and eligibility without calendar/summary refetch.
   Load another review page explicitly; no duplicates or missing older notes.
4. Verify Single deleted/live history, Multiple own reservation after deletion,
   other-author access, and TO_DO blocking new reviews but allowing own edits.
5. Verify one-time Done/Undo, persistent Done Today, recurring virtual and recorded
   dates; dates/time/status/lock_version must stay correct on navigation.
6. Simulate a lost command response and retry the same request ID. Also verify 409
   conflict recovery and collaborator access revocation. No duplicate command effect.
7. Create/edit/delete/restore a relevant task or its schedule: one interval reload.
   Create an outside-week dated one-time task: no interval reload. Verify task
   ordering, undated section, archived tasks and all loaded task pages.
8. Verify Task List and Home keep their existing standalone behavior and that
   opening the note/task editors still supports configuration and recurrence loading.

Static checks only: PHP syntax, TypeScript, targeted ESLint and production build.
No automated tests were created, modified or run; no database migration was run.

## Files for this change

Backend:
- `server/routes/api.php`
- `server/app/Http/Controllers/Api/ProjectTaskCalendarController.php` (new)
- `server/app/Services/ProjectTaskCalendarService.php` (new)
- `server/app/Services/ProjectTaskReviewService.php`
- `server/app/Http/Controllers/Api/ProjectTaskExecutionController.php`
- `server/app/Http/Controllers/Api/ProjectProgressNoteController.php`
- `server/app/Http/Resources/ProjectProgressNoteResource.php`

Frontend:
- `mobile/src/components/projects/ProjectTaskCalendar.vue`
- `mobile/src/components/projects/ProjectTaskCard.vue`
- `mobile/src/components/projects/ProjectTaskReviews.vue`
- `mobile/src/components/projects/ProjectTasksTab.vue`
- `mobile/src/components/projects/ProjectTaskForm.vue`
- `mobile/src/components/projects/ProjectProgressNoteEditor.vue`
- `mobile/src/components/projects/ProjectProgressNoteForm.vue`
- `mobile/src/services/projectTasksApi.ts`
- `mobile/src/types/projectTasks.ts`
- `mobile/src/types/projectProgress.ts`
- `mobile/src/utils/projectProgress.ts`

Documentation: `server/docs/perf-01-task-calendar.md` (this file).
Existing working-tree changes from earlier tasks, including the Single migration,
SQL and deletion notification in `projectProgressApi.ts`, were retained.
