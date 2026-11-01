# Custom Fields Library: Create/Edit

The later [Select and lifecycle implementation](custom-fields-select-lifecycle.md) extends this original phase with Select options, Archive/Restore and first-value capability metadata.

The Settings library now provides owner-only Create and compact pencil Edit actions for Number, Text and Boolean. Select definitions remain visible with no Edit action. The page's General draft and `?tab=settings` URL are unchanged by field saves.

## Files

Created:

- `src/components/projects/ProjectCustomFieldEditor.vue`: shared native Create/Edit dialog, field state, validation, API writes and discard protection.
- `src/components/projects/ProjectCustomFieldEditor.spec.ts`: field form and dialog regression tests.
- This document.

Modified:

- `src/components/projects/ProjectCustomFieldsLibrary.vue`: permission checks, compact Add/Edit actions and local list refresh after success.
- `src/components/projects/ProjectCustomFieldsLibrary.spec.ts`: owner/viewer, supported-type and refresh tests.
- `src/views/projects/ProjectFormView.vue`: passes the loaded project to the library for permission checks.
- `src/views/projects/ProjectFormView.spec.ts`: verifies field creation refreshes only the library and preserves the active Settings URL.

Backend, models/API contracts, existing Progress Note editor, Note Types, charts, evaluations and recurrence were not changed. Existing `ProgressNoteConfirmDialog` is reused without modifying it.

## Behavior and permissions

Only the active project's owner sees mutation controls. A matching loaded Project is required; library consumers supplying only `projectId` remain read-only. The editor rechecks ownership, project status and field/project identity before every save. Server authorization remains authoritative, including changes after the page was loaded. A 403 disables further submissions in the open editor.

Create generates a lower-case ASCII key from the name, removes diacritics, replaces separators with underscores and prefixes identifiers beginning with a digit. Manual key input stops automatic updates. Validation never silently normalizes a manual key. Edit displays the original key read-only and excludes it from PATCH.

Number defaults to average aggregation. Unit and aggregation share a row; min/max/step are collapsible and remain exact strings. Edit initially expands existing numeric bounds. Text and Boolean expose only shared definition properties. Switching types retains the unsaved numeric draft locally, but creates no numeric settings for Text/Boolean. An Edit type change explicitly clears incompatible persisted properties with nulls, as required by the backend's merged PATCH validation.

Edit sends only changed properties, preserving unedited definitions and archive state. Backend rules currently allow editing archived definitions on an active owned project; the dialog identifies archived fields without implementing Archive/Restore actions.

Duplicate submissions and closing/navigation during save are blocked. On success, the dialog closes and the existing list endpoint refreshes the library in its returned order. No project-page reload or General-settings write occurs. Failed requests keep all entered values. Close, Escape, backdrop and router navigation check for unsaved changes; pristine and successfully saved forms do not warn. Browser unload uses its standard prompt where supported.

## Validation and contract limitation

Client validation covers required names/keys, backend key syntax/length, description/unit limits, ordinary decimals with at most 12 integer and six fractional digits, min <= max and positive step. Decimal comparisons use scaled BigInt arithmetic, with no floating-point conversion. API payloads retain decimal strings and explicit nulls.

422 field messages appear beside their relevant controls, including duplicate-key and immutability errors. Numeric errors open Advanced options. 403 permission failures, 409 conflicts and network/server failures have distinct handling. Backend numeric-domain/step compatibility validation remains authoritative.

The API now exposes nullable, readonly `first_value_at`. The UI proactively locks type/unit after the first value and locks Select type for any option history. The earlier contract limitation is resolved; see [Select and lifecycle](custom-fields-select-lifecycle.md) for the current behavior.

## Verification

- TypeScript checking and production build passed.
- ESLint passed for changed Vue/TypeScript files; Prettier formatting applied.
- **123 relevant tests passed in 13 files**, including 24 dedicated field-editor tests. Coverage includes string precision, manual keys, numeric validation, API failures, immutable-key/first-value rejection handling, archived state, permissions, local refresh and unsaved changes.
- Headless Chrome with a temporary mocked-data harness checked 320px, 390px and desktop layouts. Inputs were 16px, the library heading stayed 44px high, dialogs had no horizontal overflow and desktop used a content-driven 560px width. A 390×400 short viewport scrolled only the dialog body while retaining its header/footer. No runtime exceptions were observed. No production API requests were made; the harness was removed.

Still verify physical iPhone/Safari/Capacitor keyboard, safe areas, focus restoration and native Back behavior. Chrome viewport checks are not a physical keyboard test.

## Remaining work

Select option management and field Archive/Restore are now implemented in the subsequent phase. Field reordering, Note Types, note field values/Metrics and Chart Builder remain future work.
