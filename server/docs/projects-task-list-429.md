# Projects / Tasks List — HTTP 429 fix

## Confirmed cause and measurements

The list loads three independent paginated groups. Previously every visible card
also mounted a standalone execution reader and Reviews reader. Recurring cards
additionally loaded current schedule + schedule versions. This was HTTP fan-out,
not a new recurrence bug or an infinite reactive loop.

A regression test mounts the real TasksTab/Card/Reviews components with mocked API
responses, with 10 one-time + 10 recurring tasks (no persistent tasks):

- Before the fix: **63 API reads** (3 lists, 20 occurrence reads, 20 review reads,
  10 current schedule reads, 10 schedule-version reads).
- After: **3 list reads**, with per-page context already supplied to children.
- An unrecorded occurrence previously added a fallback task GET on 404.

These are locally measured API method calls, each corresponding to an HTTP read,
not a production HAR capture. Laravel's configured API limiter remains 60/minute
per authenticated user/IP. The unchanged limiter is also tested locally: request 61
returns HTTP 429 with limit 60 and Retry-After. No hosting/proxy/WAF logs or live
response headers were available; the exact issuer of the reported production 429
cannot be independently confirmed from this workspace.

## Changes

The existing task list endpoint supports an opt-in context:

`GET /api/projects/{project}/tasks?...&include_context=1&occurrence_date=YYYY-MM-DD`

Existing `data`, sort, filters, `meta.total` and pagination remain unchanged. Without
`include_context`, the response remains unchanged. Trash does not load execution
context. A date is optional and defaults to the server's configured current day.
New top-level `contexts`, keyed by returned task ID, contains the existing calendar
context shape: task, occurrences, first 20 visible reviews + review context, schedule
metadata, and `occurrence_eligible`. Only the current page is expanded.

`ProjectTaskListContextService` batches relationships, uses the existing recurrence
projection and review aggregator, and never creates occurrences. The eligibility
flag preserves List's existing occurrence GET semantics, including recorded
occurrences outside the current schedule. It does not change the recurrence
algorithm, authorization, status transitions, Single/Multiple, or lock versions.
Calendar/Home consumers have no override and keep their existing behavior.

TasksTab owns these local contexts and delegates explicit history/review-page/date
selection reads. Reviews mutations adopt the existing returned note/context locally.
A task status transition that changes filtered membership refreshes the existing
list/counts; same-task-status occurrence updates merge locally. Structural edits
still refresh through the existing parent event. No per-card refresh cascade.

Each group deduplicates identical in-flight reads, cancels obsolete reads with
AbortController, and guards results by request generation. The API client accepts
an optional signal for GET without changing callers that omit it. Search retains
its existing 300ms debounce and skips whitespace-only changes. Mode/filter watchers
are combined; switching to Calendar/unmounting cancels list requests and pending
search. There is no polling, global cache, automatic 429 retry or limiter change.
Incomplete aggregate responses are shown as a list error rather than falling back
to per-card HTTP fan-out. Deploy **backend first, then frontend**.

## Validation

Frontend tests cover simultaneous one-time/recurring loading, separate status and
priority filters, rapid search, late responses despite ignored abort signals,
project changes, duplicate pagination clicks, repeated List/Calendar switches,
post-edit reload, unmount cancellation, incomplete context and no 429 auto-retry.
Transport tests cover the signal and optional query encoding. Existing shared API
service tests are included in the targeted run.

Backend tests cover the legacy opt-in boundary, unchanged filter/count/pagination
results, virtual recurring dates without writes, collaborator visibility, Single
soft-delete and Multiple reservations, constant query count for 1 vs 10 reviewed
tasks, plus the unchanged 60/minute Laravel limiter. Existing sorting and
collaboration suites are also run.

Targeted commands:

- `vitest run src/components/projects/ProjectTaskListRequests.spec.ts src/services/projectTaskReadCancellation.spec.ts src/services/projectProgressApi.spec.ts src/services/shoppingApi.spec.ts`
- `phpunit tests/Feature/Api/ProjectTaskListContextApiTest.php tests/Feature/Api/ProjectTaskSortingApiTest.php tests/Feature/Api/ProjectCollaborationTaskApiTest.php`
- TypeScript, targeted ESLint, PHP syntax/format checks, production frontend build.

PHPUnit uses the existing in-memory SQLite test configuration, not production.
No migration/schema change was introduced. Earlier unrelated working-tree changes
were preserved; the entire repository test suite was not run.

## Limits / production verification

Cancellation prevents obsolete browser work but cannot undo a throttle hit for a
request already received by the server. Independent legitimate user actions still
consume the unchanged shared limit. Explicit history/pagination/editor actions can
make their normal reads; they are not initial List rendering fan-out.

On the deployed build, inspect Network while opening List, filtering status/priority,
typing quickly, switching modes/projects, loading pages and returning after editing.
Expect three list reads per settled first-page filter load, one per explicit group
page, zero automatic per-card occurrence/review/schedule reads. Calendar retains its
PERF-01 interval endpoint. If 429 remains, capture endpoint, response headers/body,
request count and server/proxy logs to identify the remaining source.

## Files for this fix

- `mobile/src/components/projects/ProjectTasksTab.vue`
- `mobile/src/components/projects/ProjectTaskCard.vue` (optional eligibility override only)
- `mobile/src/services/projectTasksApi.ts`
- `mobile/src/services/api.ts` (optional GET signal)
- `mobile/src/types/projectTasks.ts`
- `mobile/src/components/projects/ProjectTaskListRequests.spec.ts` (new)
- `mobile/src/services/projectTaskReadCancellation.spec.ts` (new)
- `server/app/Http/Controllers/Api/ProjectTaskController.php`
- `server/app/Http/Requests/Project/ListProjectTasksRequest.php`
- `server/app/Services/ProjectTaskListContextService.php` (new)
- `server/tests/Feature/Api/ProjectTaskListContextApiTest.php` (new)
- `server/docs/projects-task-list-429.md` (this file)
