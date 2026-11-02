# Backend Phase 2: Note values and historical configuration

Follow-up: actual MySQL 8.2.0 migration, constraint and concurrency verification is documented in [Phase 2 MySQL verification](phase2-mysql-verification.md), including the timestamp precision correction and retained isolated test setup. Earlier verification limits below describe the original delivery.

## Implemented storage

Migration: `2026_10_31_000001_add_progress_note_values.php`.

`project_progress_notes` gains nullable `note_type_id`, JSON `configuration_snapshot` and microsecond `configuration_captured_at`. Existing notes remain null/untyped. There is no default assignment, initialization, data backfill or historical reconstruction from observation dates.

`project_progress_note_values` stores a unique row per `(note_id, field_id)`, project identity, captured type, nullable Number/Text/Boolean/Select columns and microsecond timestamps. Every captured field has a row, including empty optional fields. Clearing resets the typed column to null; it does not delete definitions, periods or value rows. Number uses MySQL DECIMAL(20,6); API validation preserves the existing 12-integer/6-fractional-digit domain. SQLite uses string storage because NUMERIC affinity would round large decimals to binary floats. API output always canonicalizes numbers to six-place strings.

Project-scoped foreign keys protect note/field ownership. A composite `(field_id, select_option_id)` FK references the new options `(field_id,id)` unique index. All deletion references are restrictive; none cascade historical values away. The note's Note Type FK is project-scoped on MySQL. Laravel 10 cannot add that composite FK to an existing SQLite table, so SQLite uses a native identity FK and project-scope triggers, including protection against moving a referenced Note Type to another project.

Database checks/triggers enforce complete-or-null configuration context, immutable context once typed, valid type-column combinations and Boolean range. Required fields, snapshot membership, exact numeric syntax/bounds/step, JSON snapshot structure and current lifecycle rules are validated in the service; they are not misrepresented as database-enforced rules. SQLite additionally validates snapshot JSON syntax through its context triggers.

## Historical semantics

Capture happens server-side, inside the existing shared project lock and note transaction. The snapshot contains schema version 1; Note Type ID/name/description/template/default/archive state; field IDs and association-period IDs; Required state/order; field labels/key/type/unit/description/numeric settings/aggregation; and all Select option IDs, labels/order and captured archive timestamps.

Only currently active associated field definitions enter a NEW note's applicable snapshot. An archived field is excluded even if its retained current association says Required. Options, including archived options, are recorded for identity/history, but an archived option cannot be newly selected while archived. An archived Note Type cannot be used for new notes.

`noted_at` remains an editable date. `configuration_captured_at` is the actual UTC capture instant. Later Required/order/association/label/template/validation changes do not modify the snapshot. Its JSON and capture/type reference are also frozen by database update triggers once typed.

Historical edits:

- Required validation checks the resulting merged state against the original snapshot, not today's associations.
- Detaching or reattaching a field does not remove it from an old note's snapshot or prevent editing it.
- A currently archived field's unchanged value can be retained. An optional value can be cleared. A new/replacement non-null value is rejected with 409 until restoration.
- An unchanged historical Select option remains valid even if currently archived. Newly choosing it while archived is rejected with 422. Restoring a captured option re-enables selection, including an option that was archived when captured. Options introduced after capture are not selectable on that historical note.
- New/replacement values must match the captured validation settings. Current type/unit incompatibility produces 409. Unchanged values and unrelated title/content/date edits remain allowed.
- Archived Note Types remain readable and their existing notes remain editable under the normal note policy. Their current active captured fields may still receive compatible historical value changes. Restoration allows new notes again; it does not rewrite old snapshots.
- Saved notes cannot change Note Type, become untyped or convert from legacy to typed. Supplying the same Note Type ID is allowed. Rebinding produces 409.

## Implemented API contract

Existing endpoints are extended; no new routes were added:

- `POST /api/projects/{project}/notes`
- `PATCH /api/projects/{project}/notes/{note}`
- Existing show/list/trash/restore resources expose the additional properties.

Creation optionally accepts `note_type_id` and `values`. Omitting/nulling the type creates a legacy note, never the default type. Non-empty values on a legacy note produce 422.

Each values entry is exactly `{ "field_id": integer, "value": typed-value-or-null }`. Duplicate normalized IDs, unknown/foreign/unassociated fields, extra entry properties, missing values and invalid shapes are rejected. Up to 1,000 entries are accepted per request.

| Input | Meaning |
| --- | --- |
| `values` omitted | Preserve existing values |
| `values: []` | No value changes |
| Listed entry with `value: null` | Clear that optional value |
| Partial list | Merge only listed changes, preserving omitted fields |

New typed notes still validate all required fields with omitted or empty values. Required Boolean accepts `false`; required Number accepts `0`. Required Text rejects blank/whitespace-only text. Optional Text accepts empty strings and preserves whitespace/newlines. The note-value paths bypass the global trim/empty-to-null transforms; other request behavior is unchanged.

Number accepts exact decimal strings and supported JSON numbers, validates captured bounds/step using scaled integers (origin: captured minimum or zero), and returns six-place strings. Clients should send strings to avoid JSON floating-point precision loss. Boolean requires JSON true/false. Select requires one positive integer option ID belonging to that captured field; no multi-select is introduced. Text must be a JSON string of at most 100,000 characters.

Client-supplied configuration/snapshot/capture time/first-value properties are prohibited when non-empty and never used. Response additions:

- `note_type_id`: nullable stable ID.
- `configuration_captured_at`: nullable UTC ISO timestamp with microseconds.
- `configuration`: immutable captured Note Type and ordered field definitions.
- `values`: field-ID/typed-value pairs, including null optional values.
- `current_configuration_state`: current Note Type archive state and current field type/unit/archive and option archive states. This is live information, deliberately separate from captured state. Render applicable fields/options from `configuration`; do not union newly introduced options from current state into the historical form.

Legacy responses retain existing properties and add null configuration/type/current-state properties and an empty values list. Values/current lifecycle relations are eager-loaded; a regression test verifies that listing additional notes adds no per-note queries.

## Exact examples

These files were generated through the actual HTTP kernel, authenticated API, validation, services and resources using SQLite `:memory:` only, with a frozen UTC clock. IDs refer to that isolated fixture; substitute real project-owned IDs in clients.

- `POST /api/projects/1/notes`: [request](examples/note-values-create.request.json), [201 response](examples/note-values-create.response.json).
- `PATCH /api/projects/1/notes/1`: [request](examples/note-values-patch.request.json), [200 response](examples/note-values-patch.response.json).

The POST demonstrates all four types, zero, false, whitespace-preserving text and backdating. The PATCH clears only optional Text while retaining other values and the original snapshot.

## Transactions, first value and authorization

ProjectProgressNoteService acquires the same parent project row lock used by configuration writers. Context reads, validation, note/value persistence, first-value markers and project activity updates share its transaction. The update flow also locks the note. Failed validation, lifecycle conflicts, failed task synchronization or late database failures roll everything in that note operation back.

After non-null values have been persisted, a batched `WHERE first_value_at IS NULL` update sets server-controlled UTC markers. Existing markers are never overwritten or cleared, including after clearing values, deleting/restoring notes or changing observation dates. Empty optional rows do not set markers.

Existing note policies remain authoritative: permitted collaborators can create notes and edit their own visible notes; owners can manage visible project notes. Read-only users cannot edit another author's private general note. Inactive projects reject note mutations.

Evaluation services/endpoints and frontend `saveNoteAndScores` are unchanged. A note plus its values/context is atomic. The subsequent evaluation requests remain separate transactions with their existing checkpoint/retry behavior; there is no claim of note-and-score atomicity.

## Verification

Before database tests, the effective test connection was verified as environment `testing`, SQLite 3.44.2, database `:memory:`, with foreign keys enabled. Runs explicitly pin those environment variables and clear database URLs. The new suite also checks the effective isolated configuration before RefreshDatabase can migrate.

- Relevant Project/configuration/note/evaluation suites: **95 tests, 1,457 assertions passed** (26 new Phase 2 tests).
- Full backend suite: **266 passed, 5 failed**. Four existing DietGeneralInfoApiTest cases receive 405 because the expected Diet update route is unavailable; RecipeApiTest's list query count is 5 against a budget of 4. The same five failures reproduce with the original HTTP Kernel/TrimStrings classes loaded from temporary copies of HEAD, without resetting any workspace files.
- Targeted Laravel Pint and PHP syntax checks passed; Git whitespace checks passed.
- Migration up/down/reapply was tested against isolated SQLite with an existing legacy note. Constraint tests cover duplicates, typed-column/Boolean violations, project scope, Select option ownership, immutable context and restrictive deletes. Tests also cover rollback after actual marker writes, stale configuration at capture, all historical/lifecycle/value semantics, collaboration and eager loading.

SQLite does not demonstrate MySQL row-lock scheduling. No live MySQL migration, CHECK/trigger execution or multi-connection concurrency run was possible because the configured MySQL server version could not be read. This remains a deployment/staging verification requirement, not a claim that MySQL has been exercised.

## Deployment prerequisites and command — not executed

1. Review/back up the target and rehearse on a dedicated MySQL staging/test database. Reconcile migration records if Phase 1 was previously applied through manual SQL; do not replay table creation over an existing manually installed schema.
2. Require MySQL **8.0.16+**, InnoDB for existing note/configuration tables, enabled foreign-key checks, UTC connection/time conventions and permissions to CREATE/ALTER/INDEX/REFERENCES/TRIGGER. The migration checks the actual server version/engine/enforcement before its first DDL statement and rejects older MySQL, unverified MariaDB, other drivers and unsuitable SQLite versions.
3. Verify Phase 1 prerequisite unique keys `(project_id,id)` on notes, fields and Note Types. The migration adds the option `(field_id,id)` prerequisite. Verify a staging round trip, exact decimals, constraints/triggers and concurrent configuration/note saves on the actual target MySQL version.
4. During a later explicitly authorized deployment, run from `server`:

```sh
php artisan migrate --force
```

MySQL DDL is not transactionally reversible as a complete migration; check privileges and prerequisites first. The migration's down method intentionally drops Phase 2 storage and must not be used as a data-preserving production rollback.

No development/production migration or data initialization was run. No frontend controls, chart/metric functionality, evaluation behavior, recurrence or new dependency was added. A subsequent explicit request added the manual SQL export described below; it was not executed. There is no optimistic version property for competing edits to the same value: project/note locking serializes writes, partial merges preserve unrelated values, and the last committed write to the same field wins.

## Files changed in this phase

Created:

- `database/migrations/2026_10_31_000001_add_progress_note_values.php`
- `app/Models/ProjectProgressNoteValue.php`
- `app/Services/ProjectProgressNoteValuesService.php`
- `app/Http/Middleware/ConvertEmptyStringsToNull.php`
- `tests/Feature/Api/ProjectProgressNoteValuesApiTest.php`
- This report and the four exact JSON examples linked above.

Modified:

- `app/Models/ProjectProgressNote.php`: casts and relations.
- `app/Services/ProjectProgressNoteService.php`: integrate values under existing transactions and eager loading.
- `app/Http/Requests/Project/StoreProjectProgressNoteRequest.php`: additive validation inherited by Update.
- `app/Http/Resources/ProjectProgressNoteResource.php`: snapshot, values and separate live state.
- `app/Http/Middleware/TrimStrings.php`, `app/Http/Kernel.php`: preserve typed text without altering unrelated transformations.

Pre-existing working-tree changes were retained. Before frontend integration, use the snapshot as the form schema, merge PATCH values, preserve decimal strings, send strict Booleans/option IDs, and handle 409/422 without replacing the original historical configuration.


## Manual SQL export (follow-up request)

The authorized MySQL equivalent is [project-note-values-phase-2.sql](sql/project-note-values-phase-2.sql), exported from this migration's MySQL branch through Laravel grammar in pretend mode with a connection factory that refuses any real database connection. It contains 14 DDL statements, including the immutable-context trigger, plus read-only verification statements. It is not idempotent and has not been executed on MySQL.

First run [project-note-values-phase-2-preflight.sql](sql/project-note-values-phase-2-preflight.sql) separately against the deliberately selected database. Every check must report PASS, no SQL error may occur, and the migration-history query must return no row. The checks require MySQL >=8.0.16 (excluding MariaDB), strict SQL mode, enabled foreign keys, four prerequisite InnoDB tables, required unique project identity keys, and no existing/partially installed Phase 2 objects. These SELECTs do not automatically stop an import: inspect their results before importing the DDL file.

Back up and rehearse on staging, then run the DDL once with writes paused using a client that understands `DELIMITER` (for example the MySQL CLI). Stop on the first error; do not retry blindly or use the mysql client's `--force` option. DDL is not one rollback-safe transaction. The file ends with a commented migration-history INSERT to execute separately only after all statements succeeded and SHOW CREATE output was verified. Record the migration before a later Laravel deployment can replay it. Do not run both the SQL and the corresponding Laravel migration.
