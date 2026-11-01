# Custom Fields: Select, options and lifecycle

## Delivered

The existing shared editor now supports all four field types. Select creation writes the field first, then creates options using the existing nested endpoints. The UI supports draft labels, adding/removing unsaved options, and Up/Down ordering. Existing option labels are renamed with PATCH against their original IDs. Archive and Restore are staged until the main Save; archive confirmation and pending-state labels make that distinction visible. Existing archived options remain in the editor and every reorder includes their IDs.

An archived parent field's option controls are disabled until the field is restored. Metadata edits continue to follow the existing backend rules. Select types with any option history, including exclusively archived options, are locked. Type stays locked while the initial history read is pending or failed. Read-only viewers can inspect option labels and archived states through the field disclosure menu, without mutation controls.

Library rows use a compact contextual menu for Edit, Archive/Restore and Select inspection. Field archive confirmation explains that new associations are blocked, definitions/history remain, and restoration is possible. Restore uses the existing field ID and key. No hard-delete path is exposed. Mutations remain restricted to active-project owners; the backend is authoritative.

## Save checkpoints and recovery

- A confirmed field response is retained for the lifetime of the open editor. Once saved, metadata freezes during option recovery; retries never create or update that field again.
- Each confirmed option response supplies a stable ID and updated baseline for its label/archive state. Successful creates become existing rows; they cannot be removed as unsaved drafts. Retries skip their creates and already-applied renames/lifecycle changes.
- Ordering submits the complete local option ID list, including archived options. A failed order leaves its desired order intact. If another writer added IDs, the backend rejects the incomplete list; explicit reload lets the user inspect the current complete configuration.
- A successful full sequence refreshes the option list, closes the editor, and refreshes only the library. The Settings URL and General draft remain intact. Partial success refreshes library definitions without closing or falsely emitting complete success.
- An uncertain field-create response blocks automatic resubmission and asks the user to inspect the library. An uncertain option-create response blocks blind retry; explicit reload/inspection is required. Reload confirms before replacing unsaved option drafts. This avoids pretending that an API without idempotency keys can guarantee exactly-once creation after a lost response.
- Requests are separate transactions, not one atomic multi-request operation. No compensating deletes occur. Closing a partially saved editor warns that the field is already persisted and only unsaved option changes will be abandoned. Checkpoints are in memory and do not survive closing/reloading the page.

## Additive API contract

`ProjectCustomFieldResource` now includes `first_value_at`, using the existing immutable date cast and resource date serialization. It is a nullable, server-owned response property; existing request validation still prohibits setting it and persistence semantics are unchanged. The frontend DTO marks it readonly. Create/update payload types and submitted requests exclude it.

A non-null timestamp disables type and numeric unit controls, with a concise explanation. Numeric bounds, step and aggregation continue to follow existing server validation. Backend 409/422 conflicts remain visible without losing the draft. No migration, SQL script, new endpoint, schema change or dependency was introduced.

## Files

Created:

- `mobile/src/components/projects/ProjectSelectOptionsEditor.vue`
- This document.

Modified:

- `mobile/src/components/projects/ProjectCustomFieldEditor.vue`
- `mobile/src/components/projects/ProjectCustomFieldsLibrary.vue`
- `mobile/src/types/projectNoteConfiguration.ts`
- `mobile/src/components/projects/ProjectCustomFieldEditor.spec.ts`
- `mobile/src/components/projects/ProjectCustomFieldsLibrary.spec.ts`
- `mobile/src/services/projectNoteConfigurationApi.spec.ts`
- `mobile/src/views/projects/ProjectFormView.spec.ts` (field response fixture)
- `server/app/Http/Resources/ProjectCustomFieldResource.php`
- `server/tests/Feature/Api/ProjectNoteConfigurationApiTest.php`
- `mobile/docs/custom-fields-create-edit.md` (superseded capability note)

The existing API client functions are reused unchanged. Note Types, note values, Progress Note editor/persistence, charts, evaluations and recurrence were not modified.

## Verification and limits

Relevant frontend tests cover Select creation, option checkpoints, uncertain responses, validation, rename identity, archived IDs in ordering, option lifecycle failure/retry, archived parents, first-value/history locks, read-only inspection and field lifecycle. Backend tests cover nullable/populated microsecond serialization and prohibited client writes. Type checking, production build, ESLint and relevant regressions were run.

Headless Chrome checks used temporary mocked data at 320px, 390px, desktop (560px dialog width), and a 390×400 short viewport. They confirmed no horizontal overflow, 16px inputs, 44px option targets, archived presentation, Select history locking, content-driven desktop height, and a scrollable body with reachable Save in the short viewport. No production API requests were made. Physical iPhone/Safari/Capacitor keyboard and safe-area checks remain manual.

Remaining scope: Note Types management, custom field values/Metrics, field reordering and Chart Builder. Cross-client list conflicts require explicit reload/inspection; the backend's 1,000-ID reorder request limit remains unchanged.

## Interrupted implementation recovery

The resumed review reproduced a Vue template compilation failure in three library menu click handlers. Menu closing now runs inside the typed action handlers; template bindings contain a single call. A regression assertion verifies that opening Edit closes its menu. Existing Select persistence, retry checkpoints, lifecycle actions and the API contract were retained.

Final verification: 137 frontend tests across 10 relevant suites, 68 backend Project tests (980 assertions), TypeScript checking, production build, changed-file ESLint and Git whitespace checks passed. The mocked browser checks were repeated successfully at all four viewport sizes above.
