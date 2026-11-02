# Project note configuration: backend Phase 1

This phase adds project configuration only. It does not change progress-note or evaluation persistence, recurrence, chart aggregation, frontend contracts, `projects.note_template`, or its preview endpoint. No new dependencies are required.

## Files

Created:

- `database/migrations/2026_10_30_000001_create_project_note_configuration_tables.php`
- Models: `ProjectCustomField`, `ProjectCustomFieldOption`, `ProjectNoteType`, `ProjectNoteTypeField`.
- Service: `ProjectNoteConfigurationService`.
- API controllers: `ProjectCustomFieldController`, `ProjectCustomFieldOptionController`, `ProjectNoteTypeController`, `ProjectNoteTypeFieldController`.
- Requests: `ProjectConfigurationRequest`, `SaveProjectCustomFieldRequest`, `SaveProjectFieldOptionRequest`, `SaveProjectNoteTypeRequest`, `SaveProjectNoteTypeFieldRequest`, `ReorderProjectConfigurationRequest`.
- Resources: `ProjectCustomFieldResource`, `ProjectCustomFieldOptionResource`, `ProjectNoteTypeResource`, `ProjectNoteTypeFieldResource`.
- Feature tests: `tests/Feature/Api/ProjectNoteConfigurationApiTest.php`.
- This document.

Modified: `app/Models/Project.php` (relationships only), `routes/api.php` (new routes/imports only).

## Persistence and invariants

The migration creates four tables: `project_custom_fields`, `project_custom_field_options`, `project_note_types`, and `project_note_type_fields`. It changes no existing table and performs no data initialization.

- Field keys are immutable lower-case identifiers, unique within a project, including archived definitions.
- Types: `number`, `select`, `boolean`, `text`. Numeric aggregation: `average` (default), `sum`, `min`, `max`, `latest`.
- Numeric settings use `DECIMAL(20,6)` and are returned as fixed-precision strings. Inputs accept ordinary decimal notation, up to 12 integer digits and six fractional digits. Step must be positive; minimum cannot exceed maximum. Numeric settings and units are rejected for other types.
- `first_value_at` is a server-controlled future integration marker, not a note-value implementation. Phase 2 must set it under the same project lock when writing the first value and must never clear it. After it is set, type/unit are immutable. Bounds may only expand or be removed. Steps may be removed or refined if the old step is an integer multiple of the new step and the origins remain congruent. Origin is minimum, or zero without a minimum. Arithmetic uses scaled integers.
- Because Phase 1 has no values to query, these checks conservatively preserve the entire previously valid domain. Checking actual stored values to allow additional safe restrictions belongs in Phase 2.
- A select field with any option history cannot change type, even before note values exist. Option IDs persist across rename/reorder/archive/restore.
- Archive state is represented by nullable `archived_at`. Public hard-delete endpoints are absent.
- The first explicitly created Note Type becomes default. Subsequent creation does not change the default. Default selection uses a nullable unique slot: `is_default = '1'` or `NULL` in storage; resources always return a boolean. Exactly one default and at least one active type are preserved by the application write paths after initialization. A database unique constraint independently enforces at most one default.
- Default types cannot be archived. Select another active default first. Archived types cannot become default or have their field associations edited until restored. Templates are nullable editable text, without versions in this phase.
- Associations have a surrogate ID and dated half-open periods `[activated_at, deactivated_at)`. A nullable unique `active_slot` allows only one active period per type/field. Detaching closes a period; reattaching creates a new one. Required/order changes also close and replace periods, preserving prior configuration. Zero-length periods from same-timestamp changes contain no time and are unambiguous under half-open interpretation.
- Composite foreign keys enforce that association fields and Note Types belong to the same project. Field/type names and keys have project-scoped uniqueness constraints as applicable.
- Archiving a field retains current associations and all history. Archived fields remain in configuration responses and cannot be newly attached. Archiving a type preserves its associations. Phase 2 must distinguish preserved configuration from fields eligible for new note values.

## Authorization and concurrency

All routes require Sanctum authentication. Reads authorize the existing ProjectPolicy `view` ability, including collaborators and inactive projects. All writes authorize ProjectPolicy `update`: active project, owner only. No new competing policy is introduced. Unauthorized project reads/writes return 403; foreign or unknown nested resource IDs return 404 once the project is authorized. Invalid configuration returns 422; lifecycle/default/duplicate-association conflicts return 409.

Every controller write runs inside `ProjectNoteConfigurationService::write`, which locks and reloads the project, reauthorizes its current status, and uses a transaction with up to three deadlock attempts. This serializes default switches, definition changes, options and association operations with existing parent-locking services on databases supporting row locks. Default clearing/setting, archive operations, create-and-attach and complete reorder operations are transactional.

The service's mutation helpers are intended to be called only inside `write`; `initialize` wraps itself. Future value writers must use the same lock discipline.

## API

All paths below are relative to `/api/projects/{project}`. Collections return `{ "data": [...] }`, including archived records, in deterministic order. Singles return `{ "data": {...} }`. Creates return 201, updates/actions return 200, association detach returns 204. IDs and archive timestamps allow clients to filter active records without hiding historical definitions.

| Method | Path | Purpose |
| --- | --- | --- |
| GET, POST | `/custom-fields` | List / create fields |
| GET, PATCH | `/custom-fields/{field}` | Read / update definition |
| POST | `/custom-fields/{field}/archive` | Archive |
| POST | `/custom-fields/{field}/restore` | Restore |
| GET, POST | `/custom-fields/{field}/options` | List / create Select options |
| PATCH | `/custom-fields/{field}/options/{option}` | Rename / update order |
| POST | `/custom-fields/{field}/options/reorder` | Reorder all options |
| POST | `/custom-fields/{field}/options/{option}/archive` | Archive option |
| POST | `/custom-fields/{field}/options/{option}/restore` | Restore option |
| GET, POST | `/note-types` | List / create types |
| GET, PATCH | `/note-types/{type}` | Read / update type |
| POST | `/note-types/{type}/archive` | Archive type |
| POST | `/note-types/{type}/restore` | Restore type |
| POST | `/note-types/{type}/set-default` | Select default |
| GET | `/note-types/{type}/fields` | List current associations, including archived fields |
| GET | `/note-types/{type}/fields?history=1` | List all association periods |
| POST | `/note-types/{type}/fields` | Attach an existing field |
| POST | `/note-types/{type}/fields/create` | Create a field and attach atomically |
| PATCH | `/note-types/{type}/fields/{field}` | Update required status / order, preserving previous period |
| POST | `/note-types/{type}/fields/reorder` | Reorder all current associations |
| DELETE | `/note-types/{type}/fields/{field}` | Close association; preserve definition/history |

Options require a select field. Restore the parent field before modifying its options. Reorder bodies contain every current ID exactly once, including archived options or fields retained in current associations; unknown, foreign, duplicate or missing IDs are rejected. Reorders accept at most 1,000 IDs per request. `sort_order` is zero-based when reordered; individually supplied orders need not be contiguous or unique, with ID as the tie-breaker.

### Example payloads

Create a numeric field (`POST /custom-fields`):

```json
{
  "name": "Energy",
  "key": "energy",
  "description": "Self-reported energy",
  "type": "number",
  "unit": "points",
  "min": 0,
  "max": 10,
  "step": 0.5,
  "aggregation": "average"
}
```

Create a type (`POST /note-types`):

```json
{"name":"Therapy","description":"Session notes","template":"Observations for {date}"}
```

Create a Select field, then an option:

```json
{"name":"Mood","key":"mood","type":"select"}
```

```json
{"label":"Calm","sort_order":0}
```

Attach an existing field (`POST /note-types/{type}/fields`):

```json
{"field_id":12,"required":true,"sort_order":0}
```

Create and attach (`POST /note-types/{type}/fields/create`):

```json
{"field":{"name":"Comment","key":"comment","type":"text"},"required":false}
```

Update an association (`PATCH /note-types/{type}/fields/{field}`):

```json
{"required":true}
```

Reorder options or associated fields (`POST .../reorder`; IDs are option IDs or field IDs respectively):

```json
{"ids":[12,8,15]}
```

PATCH omission preserves values; explicit `null` clears nullable properties. `is_default`, archive timestamps, project identity and the first-value marker are server-controlled; use lifecycle action endpoints instead.

## Explicit, idempotent initialization

There is no automatic initializer, production migration backfill, model hook or read-side initialization. Existing projects remain uninitialized until explicitly configured. For a selected project, future authorized application/admin code can call:

```php
$default = app(\App\Services\ProjectNoteConfigurationService::class)
    ->initialize($owner, $project);
```

This checks owner/active-project permissions under lock, creates `General` with the current `projects.note_template` only when no types exist, and returns an existing default unchanged on repeated calls. Existing configured defaults and edited templates are not overwritten. Inconsistent configuration with types but no default is rejected for explicit repair. No notes, evaluations, task state or project columns are rewritten.

## Verification

Validation result: `php artisan test --filter=Project` passed **67 tests / 966 assertions**, including 18 new configuration tests and 49 existing project regression tests. Migrations ran only in the isolated SQLite test database; no application database migration or production initialization was executed. Changed PHP implementation files were formatted with Laravel Pint, while existing route formatting was preserved. `git diff --check` passed.

Run `php artisan test --filter=Project` from `server`. The new feature suite covers authorization, inactive projects, foreign resources, numeric/type validation, option lifecycle, reusable fields, independent configuration, association periods, default invariants, database constraints, atomic create-and-attach, rollback of failed default/reorder changes, initialization and legacy API/data compatibility.

Tests use SQLite in memory. They verify uniqueness and transaction rollback, but cannot exercise concurrent production row locks. A real concurrent-writer integration run on the deployment database remains necessary before rollout; no production concurrency result is claimed here.

## Phase 2

- Add typed note-value persistence and atomically maintain `first_value_at`.
- Validate actual existing values when considering narrower configuration changes.
- Link notes to Note Types and define historical-schema/required-field rules using association history.
- Handle archived definitions/options in historical rendering and new-value eligibility.
- Decide schema/version semantics beyond association periods; templates remain unversioned here.
- Extend note resources, editing and frontend configuration interfaces.
- Add visibility-safe numeric analytics with explicit aggregation and no multi-task duplication.
- Preserve independent 1–10 task evaluations and their existing revision/concurrency rules.
