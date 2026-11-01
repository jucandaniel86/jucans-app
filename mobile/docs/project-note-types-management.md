# Project Note Types management

Association management was added in the follow-up [Note Type field associations](./note-type-field-associations.md) task. The report below describes the original definition-management delivery.

Project Edit → Settings now displays Note Types above the unchanged Custom Fields Library. The section loads on the first Settings visit and remains mounted across tab changes. It preserves API order, includes archived definitions, displays counts/default/archive badges, and provides loading, empty and error/Retry states. Viewers can read descriptions and expand plain-text templates without mutation controls.

## Behavior

Active-project owners can create/edit definitions, set the default through the dedicated endpoint, archive eligible types after confirmation, and restore original IDs. Archived types cannot become default; default/last-active types have no Archive action. The backend remains authoritative for concurrent changes and 403/409/422 responses. Successful writes refresh only this list. No optimistic multi-row default edits, automatic creation, deletion or association requests occur.

The shared Create/Edit modal handles name, optional description and optional multiline template. It preserves template whitespace, submits nullable empty optional values, PATCHes only changed metadata, and excludes identity/default/archive/association properties. Archived metadata edits follow the existing backend, which permits them. Server validation messages and drafts survive rejected requests; in-flight requests block duplicate submissions and closing. Uncertain create responses block blind retries and refresh the list for inspection. Unsaved close/Escape/navigation uses the existing confirmation dialog; successful or unchanged forms do not warn.

The modal follows the existing field editor's native dialog, focus restoration, body scroll lock, visualViewport and safe-area patterns. Desktop width is capped at 560px with content-driven height. Mobile inputs use 16px text and actions use 44px targets. Short viewports scroll the body while keeping Save visible.

## Compatibility and backend correction

The existing `TrimStrings` middleware removed boundary whitespace/newlines from `template`. A new HTTP regression test reproduced this failure before correction. Excluding `template` from trimming preserves plain-text Note Type formatting on POST/PATCH/GET. Repository request inspection found this property is used by Note Type definitions. Legacy `note_template` handling is unchanged. No routes, API contracts, models, persistence services, database schemas, migrations or SQL scripts changed.

General `projects.note_template` remains independently editable and is never synchronized with Note Type templates. Progress Note editor/template resolution, `saveNoteAndScores`, evaluations, charts, recurrence, Custom Fields and historical associations remain unchanged.

## Initialization

Confirmed: `ProjectNoteConfigurationService::initialize()` is an explicit service entry point, never invoked by project creation, reads or migrations. It creates General with the legacy template only when configuration is absent, returns an existing default unchanged, and rejects pre-existing configuration without a default. The frontend never invokes initialization or creates General automatically. A manually created first type becomes default through existing backend logic.

Deployment consideration: if existing projects should receive General with a copy of the legacy template, initialization must be arranged explicitly through the existing service/deployment process before owners create their own types. No initialization endpoint, migration or SQL script was added or executed.

## Files

Created:

- `mobile/src/components/projects/ProjectNoteTypesLibrary.vue`
- `mobile/src/components/projects/ProjectNoteTypeEditor.vue`
- Corresponding `.spec.ts` files.
- This report.

Modified:

- `mobile/src/views/projects/ProjectFormView.vue`: new section before Custom Fields.
- `mobile/src/views/projects/ProjectFormView.spec.ts`: API mocks and real Settings creation/draft compatibility regression.
- `server/app/Http/Middleware/TrimStrings.php`: preserve `template` whitespace.
- `server/tests/Feature/Api/ProjectNoteConfigurationApiTest.php`: HTTP template round-trip regression.

## Verification and remaining work

- Relevant frontend tests: 171 passed across 12 suites (management/editor tests plus existing Project, Custom Field, Progress Note and API regressions).
- Backend Project tests: 69 passed, 988 assertions.
- TypeScript checking, production build, changed-file ESLint and targeted PHP Pint checks passed.
- Mocked headless Chrome at 320×844, 390×844, 1440×1000 and 390×400: no horizontal overflow, 16px inputs, 44px action controls, 560px desktop cap, reachable Save and scrolling body in the short viewport; no runtime exceptions.

Physical iPhone/Safari/Capacitor keyboard and safe-area checks remain manual. Lost-create-response recovery requires inspection because the existing API has no idempotency keys. Note Type integration into notes/template selection, custom-field associations, values and charts remain future work.
