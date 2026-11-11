# PERF-02 — Homepage Daily Tasks

Deploy backend before frontend. No migration, schema change, new endpoint, dependency,
global cache, or recurrence change. The calendar endpoint/PERF-01 are unchanged.

## Daily endpoint contract

Existing endpoint: `GET /api/projects/daily-tasks`.

- `section=active&page=1&per_page=20`: first **up to 10 overdue** plus first page of
  today's/persistent tasks. `pagination` now describes today's/persistent tasks
  only. `overdue_pagination` describes all overdue tasks (page size 10).
- `section=active&page=2&per_page=20`: next today's/persistent page, without repeating
  the initial overdue rows.
- `section=overdue&page=N&per_page=10`: explicit overdue page, always 10 per page.
  The existing `pagination` describes this section; no artificial cap on total rows.
- `section=completed&page=N&per_page=20`: existing completed pagination, with reviews.

`counts` and `summary` retain the true totals computed before pagination.
`overdue_pagination` contains `page`, `per_page`, `last_page`, `total`.
Every returned `rows[]` retains `section`, `task`, `occurrence` and adds:

```json
{
  "has_schedule": false,
  "current_rule": null,
  "latest_rule": null,
  "reviews": {
    "data": [],
    "meta": { "current_page": 1, "last_page": 1, "total": 0 },
    "review_context": { "mode": "single", "can_create": false, "active_count": 0 }
  }
}
```

Reviews use existing note resources (including author, snapshot, custom values,
review/occurrence association). Rules use existing schedule-version resources.
There is always an explicit empty review snapshot for virtual/unreviewed occurrences.

`ProjectTaskReviewService::forOccurrences()` follows the PERF-01 batching pattern:
load all reservation metadata for the returned occurrences, filter note visibility,
select the first 20 visible notes per occurrence, eager-load their existing relations,
and call the existing `canCreate()` with preloaded reservations. Queries are batched,
not one query per task/review. Single excludes deleted notes from active eligibility;
Multiple keeps its existing author reservations. No two-author assumption is made.
Other authors' deleted notes are not exposed. Review history remains paginated.

## Widget state

The widget stores returned rows and reviews. It passes both `calendarReviews` and
existing calendar metadata props to TaskCard, including on carousel remounts.
The existing child watchers adopt these props; they make no automatic reads.
Arrows and swipes only select loaded rows. Returning to a previous row makes no
request. Initial loading no longer loops through all active pages.

An explicit button loads another overdue page. Today's/persistent and completed
pagination remain separate. No hidden extra pages are fetched on mount or at an
arrow boundary. Existing carousel pending-command and busy guards remain in place.

Existing `reviewChanged` upserts a confirmed note into the matching project/task/
occurrence row and adopts authoritative backend eligibility. This covers create,
edit and restore with no daily-list refetch. Deleted notes retain history. DELETE
still returns 204: the existing successful-deletion notification triggers a targeted
Reviews GET for a known affected occurrence; task status and daily lists are untouched.
Review pagination/retry and execution history reads remain explicit delegated actions.
Stale review reads cannot overwrite newer confirmed mutations.

Existing execution responses provide task, occurrence and review context. Ordinary
Done/Undo/Skip transitions are applied locally, including section membership,
counts, completion summary and review eligibility. Request IDs, lock versions,
backend command receipts and retry behavior are unchanged. If a response cannot
be safely classified (day rollover, changed definition/access, an explicitly selected
outside-day occurrence), the existing daily refresh is used instead.

Offset pagination can shift when a status moves a task between sections. The next
explicit page action first revalidates the last loaded page and deduplicates rows;
a later page action advances normally. This may add fewer than a full page on that
click, but avoids skipping a boundary row. It never makes an arrow perform a read.

The existing execution-date picker remains an explicit action: recurring date
selection uses the existing occurrence-range endpoint for that date, while other
tasks use the occurrence endpoint, with a targeted review read. These reads are
unrelated to carousel navigation. Day rollover and visible-foreground refresh remain
lifecycle-driven, guarded against in-progress widget operations.

## Limits

The existing daily report still classifies visible tasks/occurrences before slicing
pages. This change bounds expanded review payloads to returned rows but does not
replace that report computation or introduce caching. Data can change on another
device between reads; mutation endpoints remain authoritative and existing conflicts
are handled. Full author-owned Single soft-delete history can exceed 20 notes and
requires explicit review pagination. Task List and Projects calendar are not changed.

## Manual checks (no automated tests)

1. With >10 overdue tasks, reload Home and inspect Network. One initial daily GET,
   at most 10 overdue rows, full `counts.overdue`, no per-card review/rule reads.
2. Navigate forward/back and swipe between loaded overdue tasks, including revisits:
   zero HTTP requests. Open Reviews: supplied data appears without a read.
3. Load overdue page 2 explicitly and repeat navigation. Verify all remaining pages
   remain accessible; today's/persistent and completed page controls still work.
4. Create/edit/restore review: local note/context updates, no daily-list read. Delete
   through existing UI: only affected Reviews refresh; status unchanged. Verify
   Single active/deleted history and Multiple reservations with >2 authors.
5. Done/Undo/Skip: local sections, counters, review permissions and completion summary
   agree with a subsequent fresh load. After moving a paginated row, load further
   pages and ensure no missing/duplicate boundary tasks.
6. Check virtual occurrences, recurring execution date selection, lost-response retry,
   409 conflicts, revoked collaborator access, day rollover and foreground refresh.
7. Check Projects calendar still uses PERF-01 and has no changed request behavior.

Static verification only: PHP syntax/formatting, TypeScript, targeted ESLint, build.
No automated tests were created, modified or run.

## Files

- `server/app/Services/ProjectDailyTasksService.php`
- `server/app/Services/ProjectTaskReviewService.php`
- `server/app/Http/Requests/Project/ListDailyProjectTasksRequest.php`
- `mobile/src/components/projects/HomeDailyTasksWidget.vue`
- `mobile/src/services/projectDailyTasksApi.ts`
- `mobile/src/types/projectDailyTasks.ts`
- `server/docs/perf-02-daily-tasks.md`

Earlier uncommitted TaskCard/date-input/REC-01 changes were preserved.
