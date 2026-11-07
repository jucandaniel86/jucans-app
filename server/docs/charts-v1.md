# Jucans Charts v1

Project Settings → Charts configures Pie / Line / Bar definitions. Project → Charts
displays only active definitions, ordered by `priority ASC, id ASC`, in one column
at every screen width. Opening the viewer resets its global inclusive date range
to the first day of the current month through today, using the device's calendar
timezone. Dates are sent literally as `YYYY-MM-DD`; UTC timestamps/history are not
rewritten. Bar overrides are view-only and reset when the viewer is reopened.

## Persistence and installation

New migration: `2026_11_04_000001_create_project_charts_tables.php`.
Equivalent standalone MySQL script: [sql/project-charts-v1.sql](sql/project-charts-v1.sql).
Apply the migration **or** the SQL script manually, once, before using the endpoints.
Neither was executed during implementation. The SQL script does not register a row
in Laravel's migrations ledger. Existing tables/observations are not modified.

- `project_charts`: project FK, title, type, signed integer priority, active,
  optional DECIMAL(20,6) total/Y limits, timestamps. Priority duplicates are allowed.
- `project_chart_fields`: chart/field associations with position; unique chart+field.
  Composite FKs ensure both the chart and field belong to the same project. Deleting
  a chart cascades only its associations; field deletion is restricted. Archived
  fields remain referenced and readable, but cannot be newly selected.
- Existing project-owner update policy and configuration parent-lock transaction
  protect all writes, including inactive-project read-only behavior. Members may
  read chart definitions/data only while they retain access to the project.

Charts depend on the existing custom-field/note-values schema. Numeric aggregation
requires the already-used PHP BCMath extension in the **HTTP** PHP runtime. If it
is missing, numeric charts display an error; Boolean and SELECT charts can still render.

## API

All routes require Sanctum, under `/api/projects/{project}`:

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/charts` | Definitions, including inactive charts and selected field metadata |
| POST | `/charts` | Owner creates a definition |
| PATCH | `/charts/{chart}` | Owner edits a definition; omitted properties are retained |
| DELETE | `/charts/{chart}` | Owner deletes the definition and its associations |
| GET | `/charts/data` | Aggregated data for all active charts in display order |

Create example (field 8 must be a numeric SUM field of this project):

```json
{
  "title": "Principal rambursat",
  "type": "pie",
  "priority": 0,
  "active": true,
  "field_ids": [8],
  "total": "60000",
  "y_min": null,
  "y_max": null
}
```

Line/Bar accept multiple distinct numeric `field_ids`; Pie accepts exactly one
Boolean, SELECT or numeric SUM field. Numeric Pie total is a positive manually-entered
constant, never inferred from other values. Line Y bounds are optional, with strict
min < max when both exist. Boolean and SELECT Pie have no manually configured total. The frontend accepts a decimal
comma in the editor and sends decimal strings with a dot to preserve precision.

Data request: `?start_date=2026-11-01&end_date=2026-11-04`.
Optional `group_by[chart_id]=auto|day|week|month` affects only Bar presentation.
Response `data` contains `range` and `charts`. Each chart has metadata, an optional
`error`, `observation_count`, and either `segments` or `series`. Numerical values are
decimal strings; missing series values are null. Series points include `date`,
inclusive `from`/`to`, `value`, and the contributing observation `count`.
An incompatible/unavailable source yields a per-chart error, without failing other
charts. Inactive definitions are absent from this response.

## Calculation rules

- Every value comes from an existing note's `noted_at` DATE, across all Note Types.
  Null/missing values are ignored. Notes use the existing `visibleTo` and soft-delete
  scopes; associated-task visibility is checked there. No hidden note content or
  values enter the report. Values are never multiplied by task associations.
- Snapshot field type/unit compatibility is checked before aggregation, matching
  the existing numeric-series convention. Changing aggregation intentionally changes
  chart calculations; saved notes and their configuration snapshots remain intact.
- Numeric Pie: previous is SUM before Start; current is SUM from Start through End;
  remaining is max(total − previous − current, 0). Values after End are excluded.
  Excess is returned/displayed separately. Actual previous/current values are not
  rescaled to force the configured total. If those sums are negative, exact values
  are shown without drawing a misleading negative-area donut.
- Boolean Pie: counts explicit true/false only inside the selected interval;
  null/missing is never treated as false.
- SELECT Pie: counts valid non-null option IDs inside the inclusive interval, once
  per note/field (the existing unique note+field constraint prevents duplicates).
  IDs must belong to both the captured field options and the current field.
  Segments use current option labels and configuration order, including archived
  options with historical values. Invalid selections and zero-count options are
  omitted. `total`/`observation_count` are the valid note count; each segment includes
  `option_id`, `value` (count), and `percentage` rounded to two decimals. No manual
  total, new schema, or BCMath dependency is needed for SELECT.
- Line: one series per field, with every inclusive calendar day represented. Each
  day uses that field's SUM/AVG/MIN/MAX/LAST. Missing days remain null. SVG paths join
  actual observations across gaps while preserving their daily x positions. A single
  observation is a dot. The accessible date selector/tap values never interpolate.
- Bar: Auto selects day for 1–31 days, ISO Monday-start week for 32–90, otherwise
  month. Aggregation is over the actual values in each bucket, not daily means.
  First/last partial buckets use only values inside the global date range.
- LAST ordering is observation date, then note `created_at`, then note ID. The model
  has no separate observation-time column, so creation time is the deterministic
  intra-day tie-breaker; `updated_at` does not reorder historical observations.
- Numeric sums/comparisons/averages use BCMath; averages follow the existing six-place
  rounding convention. Decimal-to-JavaScript-number conversion is only for SVG
  coordinates; exact values remain available as strings in labels/selectors.

## Bounds and performance

The selected interval is limited to 366 inclusive days, matching the existing
custom-field series limit. Up to 12 fields per chart provide bounded point counts
and distinct series colors. Pie previous sums still include all visible compatible
history before Start, even beyond 366 days. Reads never write occurrences or notes.

The report is read in a transaction; definitions and fields are eager-loaded.
A single visibility-scoped note stream,
batched by 200, aggregates all required fields/groupings. No per-field/chart query
loop and no unbounded raw-history response. Numeric Pie may need to scan substantial
pre-range history to compute its exact cumulative sum.

## Manual review

The user performs functional review. No automatic tests were created, changed or run.
Compilation/static checks only: PHP syntax, TypeScript, relevant ESLint, Vite build.

Suggested manual checks after applying the schema:

- Numeric Pie: total 60000, previous 20000, current 5000 → remaining 35000; add a value
  after End and confirm it is excluded. Exceed total and check remaining 0 plus excess.
- Boolean: true, false and missing → exactly one Yes and one No.
- Line: observations on days 1/3/6 connect across nulls; a real zero remains visible.
- Bar AVG: one value 10 on day 1, three values 4 on day 2 → bucket average 5.5, not 7.
  Check 31/32/90/91-day grouping boundaries and partial ISO weeks/months.
- Check owner CRUD, inactive projects, hidden notes, removed collaborators, archived
  fields, priority ties, and inactive charts. Review compact layout at 320px/390px
  and desktop; charts must remain in a single column.

## File manifest

Backend additions: `ProjectChart` model, `ProjectChartController`, save/data Requests,
`ProjectChartResource`, configuration/report Services, migration and standalone SQL.
Modified backend files: `Models/Project.php`, `routes/api.php`, this documentation
and the link in `docs/projects.md`.

Frontend additions: `ProjectChartsSettings.vue`, `ProjectChartEditor.vue`,
`ProjectConfiguredChart.vue`, `projectChartDefinitions.css`, matching API/types.
Modified frontend files: `ProjectChartsTab.vue` and `ProjectFormView.vue`.
Legacy progress/field-series endpoints and their source files remain unchanged.

## Pie Select COUNT / SUM extension

After the base Charts schema, apply **either**
`2026_11_07_000001_add_pie_select_aggregation_to_project_charts.php` **or** the separate
[manual MySQL script](sql/project-charts-pie-select-aggregation.sql), once, before
publishing the updated backend/frontend. No migrations or SQL were executed during
implementation. Production table definitions supplied by the owner confirmed
InnoDB, matching BIGINT UNSIGNED keys, and `custom_fields_project_identity` on
`project_custom_fields(project_id,id)` before defining the new composite FK.

New nullable columns in `project_charts`:

- `pie_aggregation`: `count|sum`, NULL means legacy Select COUNT.
- `pie_value_field_id`: nullable numeric Custom Field reference, protected by
  composite project+field FK. Existing `project_chart_fields` remains Group By.

Existing POST/PATCH routes accept these additional properties only as Pie Select
configuration. SUM requires a Number field from the same project, regardless of
that field's Line/Bar aggregation setting. Newly selecting archived fields is
rejected; an already referenced archived field remains valid for read/edit.
Omitted PATCH properties retain their saved values; explicit COUNT clears Value
Field. Changing to Boolean/Number Pie or Line/Bar clears the Select-specific config
and keeps those chart modes' previous UI/calculation rules. No new endpoints.

The Select editor adds Group By, COUNT/SUM and conditional required Value Field.
The report adds `pie_aggregation`, numeric-field `unit`, decimal-string `total`,
segments and an optional `pie_message`. COUNT still reports note counts. SUM labels
show exact values and numeric-field units, with percentages calculated from SUM.

SUM reads the same visibility-scoped, soft-delete-aware stream of notes in batches
of 200. Both fields are loaded together; no per-note queries or joins to tasks,
reviews, recurrence instances or task association tables are introduced. Each note
contributes once per chart only when both its Select value and Number value pass
snapshot ID/project/type/unit/option validation. Pairing is by note and aggregation
by option ID, not label or distinct task. Different charts can share a Group By and
use different Number fields without mixing buckets. Typed ordinary notes and review
notes both contribute, as before. The inclusive period uses `noted_at`; Text fields
such as "Data plății" and recurrence scheduled dates do not redefine that period.
Pre-period values loaded for cumulative numeric Pie never contribute to Select SUM.

Missing/null/invalid pairs are skipped. Zero is valid and contributes an observation.
BCMath preserves six-place decimal sums. Negative category sums remain unchanged in
the legend/total; the donut and percentages are suppressed with an explanation.
A zero total also shows an explanation instead of a donut/division by zero. A zero
category with other positive categories remains in the legend with 0%. The server
must have BCMath enabled in the HTTP PHP runtime; only numeric charts depend on it.

Manual verification (no automated tests created, modified or run):

1. Existing Select Pie without explicit aggregation still COUNTs correctly. Boolean
   Pie, cumulative Number Pie, Line and Bar must render and edit as before.
2. Create SUM using Locație and Suma plătită with unit RON. Values 200+300 in București
   and 700 in Bolintin Deal must give 500 RON / 700 RON and 41.67% / 58.33%.
3. Add distinct reviews, including two for one occurrence and reviews on different
   recurrence dates. Each note contributes once; editing one updates its value.
4. Missing category, missing number, or incompatible snapshot values contribute
   nothing; zero contributes an observation. Check notes of different Note Types
   cannot combine values across notes. Check two SUM charts with different numbers.
5. Check start/end boundaries by noted_at, older cumulative numeric Pie history,
   collaborator-hidden notes and soft-deleted notes. No extra SUM contributions.
6. Verify negative category sums, an all-zero total and ordinary zero categories:
   exact totals/legend remain, no misleading circle or undefined percentages.
7. Save/reopen; PATCH only title/active preserves SUM and Value Field. SUM→COUNT
   clears Value Field. Cross-project/non-Number IDs return validation errors.
8. Preserve existing archived references; disallow newly selecting archived fields.
   Verify the compact editor and legend on iPhone at 320px/390px.
