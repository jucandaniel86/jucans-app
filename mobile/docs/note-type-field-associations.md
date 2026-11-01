# Note Type field associations

## Delivered

`ProjectNoteTypeFields.vue` adds a compact Fields section below the Note Type template. It displays the current association count and API order, field names/types, Required/Optional state and archived field status. Loading, empty and error/Retry states are included. The Note Types Library also provides lazy, read-only association inspection for viewers and owners.

The searchable existing-field picker excludes foreign-project fields, archived definitions and fields already actively associated. Required is chosen before attaching. Association updates save immediately and independently of Note Type metadata; the UI states this explicitly. All mutations use the existing typed client, with no new endpoints or stores.

Required changes PATCH only the association's `required` property. Updated history-period IDs are accepted while field IDs remain stable row keys. Up/Down sends the complete current field-ID list, including archived definitions. A failed order remains visible, blocks conflicting mutations and can be retried unchanged or replaced through confirmed reload. No global library ordering is changed.

Detach requires confirmation that the field remains in the library and association history is retained. It calls only the existing detach endpoint and refreshes current associations. Archived fields remain visible in current configurations; detached historical periods remain on the backend. This task does not add a raw association-history browser.

## Create and Attach

The existing field editor accepts an optional typed create callback; its standalone behavior, validation, permissions and Select option checkpoint implementation remain intact.

- Number, Text and Boolean use the existing atomic endpoint with `{ field: definition, required }`. Nested `field.*` validation messages are mapped back to the shared editor. Successful atomic creation never calls a second attach operation.
- Select uses existing field creation and option checkpoints, because the atomic endpoint does not accept options. Attachment follows only after the option flow succeeds. A confirmed created field ID survives attachment failure. Retry first reads current associations, then attaches only if that ID is absent. Lost attachment responses are reconciled without duplicate creates or unnecessary repeated attaches.
- Uncertain create results use the shared editor's existing blocked-retry/inspection behavior. Partial Select option success is retained; there are no compensating deletes or claims of multi-request atomicity.

Creating a definition refreshes only the sibling Custom Fields Library. Existing association inspections refresh when mutations succeed. Note Type and General metadata drafts and the Settings URL remain intact.

## Permissions and state

Only active-project owners can mutate associations on active Note Types. Viewers and archived Note Types receive read-only configuration. Existing associations to archived fields are never silently removed; archived fields are excluded from the picker. Backend 403/409/422/network errors remain visible with useful validation details and explicit reload/retry paths.

An unsaved Note Type has no association component or API calls: a short prompt instructs saving it first and reopening it. No type is silently created. Parent closing/saving is blocked during association writes or nested field creation. Failed ordering and pending attachment participate in the parent's unsaved-state handling; confirmation explains that already saved associations and created definitions persist.

## Files

Created:

- `mobile/src/components/projects/ProjectNoteTypeFields.vue`
- `mobile/src/components/projects/ProjectNoteTypeFields.spec.ts`
- This report.

Modified:

- `ProjectCustomFieldEditor.vue`: optional create callback, retaining the existing flow.
- `ProjectNoteTypeEditor.vue`: association section, save-first guidance, state guards and change events.
- `ProjectNoteTypesLibrary.vue`: lazy read-only association inspection and refresh events.
- `ProjectFormView.vue`: refresh the sibling field library when definitions change.
- Corresponding Note Type editor/library and Project Form tests: association mocks, viewer inspection, metadata/tab/draft preservation.

## Verification and limitations

- 205 relevant frontend tests across 13 suites passed, including 30 focused association tests and existing Note Types, Custom Fields, Progress Notes and API regressions.
- TypeScript checking, production build, changed-file ESLint and Git whitespace checks passed.
- Mocked headless Chrome checks at 320×844, 390×844, 1440×1000 and 390×400 confirmed no horizontal overflow, 44px row controls, a 560px desktop cap, body scrolling where needed and a reachable Save footer. The searchable picker and reused nested field editor also passed overflow/font/footer checks at all four sizes.

No backend, schema, migration, SQL, note persistence/editor, evaluation, recurrence or chart changes were made. No dependencies were added. Physical iPhone/Safari keyboard and safe-area checks remain manual. Client checkpoints live only while the editor is open; after closing/reloading, users inspect persisted library definitions and associations. Backend concurrency conflicts require explicit reload. Note values, metrics and charts remain outside this task.
