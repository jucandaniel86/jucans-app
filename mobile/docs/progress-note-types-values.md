# Progress Notes: Note Types and Custom Field values

## Delivered

The existing Add/Edit Progress Note form now supports explicit Note Type selection and all four value types. No configuration CRUD, backend changes, database migrations, charts, aggregation, recurrence changes, stores or dependencies were added.

New notes load the project's active Note Types and preselect its configured active default when available. The selection is an unsaved draft. Without an active default/types, the existing untyped flow remains available. A failed types read shows Retry plus an explicit Continue without type path; it does not silently choose a type or create a definition.

A selected type loads current ordered associations through the existing client and options through the existing option endpoints. Archived fields are excluded, including retained Required associations, matching the backend's new-note snapshot rule. Archived options are unavailable for new selection. Failed/incomplete configuration loading blocks typed saving and offers retry or switching to the untyped flow. New-note configuration may change between these GETs and save; the backend's locked capture/validation remains authoritative.

## Templates and safe switching

Typed notes use their selected type's plain-text template; saved typed notes use the captured template. No new template language/interpolation is introduced. Untyped notes retain the existing project-template preview endpoint and date/task substitutions.

Generated defaults stay pristine and never overwrite content entered while types/fields/templates load. Changing a new draft's type after manually editing observation content or entering values requires confirmation. Cancellation retains the type, text and values. Confirmation discards type-specific text/values, applies the new template when safe, and retains title/date/task/evaluation drafts. No values are carried between types. Selecting or switching a type performs only GETs.

Re-reading current fields on a new typed draft confirms before replacing entered values and preserves observation text. Saved notes never reload today's associations into their historical form.

## Values and validation

- Number uses text inputs with decimal keyboard hints. Decimal strings never pass through Number/parseFloat. Bounds, step and semantic equality use scaled BigInt arithmetic with the captured minimum-or-zero step origin.
- Text preserves exact whitespace/newlines. Empty text remains distinct from explicit null. Its 100,000-character limit counts Unicode code points, matching backend character counting rather than JavaScript surrogate-pair length.
- Boolean has Unset/Yes/No choices mapping to null/true/false. Required false and Number zero are supplied values.
- Select is single-choice using captured/project option IDs, with stable labels and unavailable-option indicators.

Optional fields have an explicit Clear action producing null. Historical fields can restore their original draft value before saving. Labels use captured units; incompatible live units are identified in the restriction hint. Numeric hints display the applicable captured/current-new-note bounds and step. Inline local/server errors remain associated with their field IDs. Backend error indices are mapped using the actual submitted merge list, not display indices.

## Historical forms and display

Saved typed forms use only `configuration.fields` and `values`. Note Type, labels, order, Required flags, units, settings and option labels are frozen. Current Note Type associations/options are never fetched to build historical forms. `current_configuration_state` is used only for lifecycle/type/unit compatibility.

Unchanged archived values/options remain readable and retainable. Archived/incompatible fields reject replacement input but allow clearing optional values and retaining/restoring the original local value. Original archived options can be retained; other currently archived or unavailable options cannot be newly selected. Restored captured options become available again. Current-only options never enter the historical picker. Detached compatible captured fields remain editable, including on archived Note Types.

Note Type is fixed for every saved note. Legacy notes stay untyped without fetching current definitions. A typed note missing its frozen snapshot blocks saving and asks for a journal reload rather than inventing a replacement configuration.

The journal preserves its card density through a compact expandable type/value disclosure. It displays the captured type, exact values, zero, false, unset state, units and archived Note Type status. Field Text remains escaped plain text with whitespace preservation; existing legacy observation rendering is unchanged. Existing note permissions remain in place: collaborators can enter notes and edit their own permitted notes; other viewers receive no journal edit actions. Configuration owner-only mutation rules are not applied to note entry.

## Save and retry contract

The existing `notePayload` and `saveNoteAndScores` remain the persistence path. Creation adds `note_type_id` and field-ID/value entries only for typed notes. Untyped/legacy requests omit new properties. Historical edits send only semantically changed field values; unchanged entries are omitted and optional clearing sends null. No client snapshot or capture time is submitted.

Note plus values/configuration is one backend operation. Evaluation requests remain subsequent independent operations. A saved note ID and successful evaluation checkpoints survive later evaluation failures. The note/type/value draft freezes during evaluation recovery; retry skips the confirmed note write and completed evaluations. Complete success is emitted only after all intended operations finish.

409/422/network failures preserve drafts. Uncertain creation (network/5xx or unreadable response after an actual create attempt) blocks blind resubmission and reuses the existing close-and-inspect-journal reconciliation path. Local pre-request validation errors do not mark creation uncertain. The API has no idempotency token or lookup-by-request endpoint: there is no exactly-once claim across lost responses or closing/reloading the editor. Checkpoints live in the open form.

Historical current-state information is as fresh as the note's last read. Concurrent external lifecycle changes can still produce 409/422; retain/correct the draft or close and reload the journal. Frozen historical fields are never rebound to resolve such conflicts automatically.

Observation text keeps its existing trim/save behavior. Exact boundary whitespace preservation applies to Custom Field Text values, not a replacement of legacy observation persistence.

## Files

Created:

- `src/composables/useProgressNoteConfiguration.ts`: per-editor read/configuration/value draft state, current-definition reads for new notes, historical merge entries and field error mapping.
- `src/utils/progressNoteFields.ts` and `.spec.ts`: exact validation/comparison, lifecycle restrictions and value formatting.
- `src/components/projects/ProgressNoteFieldValues.vue`: accessible typed controls.
- `src/components/projects/ProgressNoteValuesDisplay.vue`: compact read-only saved disclosure.
- `src/components/projects/ProjectProgressNoteValues.spec.ts`: integration coverage.
- `src/services/projectProgressApi.spec.ts`: mocked HTTP contract checks using the actual generated backend response example.
- This report.

Modified:

- `src/types/projectProgress.ts`: snapshot/current-state/value types and additive payload/response properties. Phase 2 response properties are optional only to support existing legacy response fixtures/clients; typed editing requires the actual snapshot.
- `src/components/projects/ProjectProgressNoteForm.vue`: selection/templates/controls/merge payloads and uncertain-create guard.
- `src/components/projects/ProjectProgressNoteEditor.vue`: keep focused select controls visible during viewport changes.
- `src/components/projects/projectProgressNoteEditor.css`: compact observation height, type selector styling and touch targets.
- `src/components/projects/ProjectProgressTab.vue`: saved disclosure integration.
- Existing note form/editor tests: configuration read mocks, request isolation, updated asynchronous template-race setup and custom-value discard confirmation.

Existing API request functions and note/evaluation checkpoint implementation are reused unchanged. Unrelated working-tree changes were preserved.

## Verification

- **250 relevant frontend tests across 17 suites passed**, covering existing Project settings/configuration/notes/evaluations plus new value/API/helper scenarios. Final runs use mocked HTTP and an explicit unused loopback API base.
- TypeScript checking, production build, changed-file ESLint, Prettier and Git whitespace checks passed. Focused affected tests were rerun after the final current-unit hint refinement.
- Mocked headless Chrome exercised new and historical notes at **320×844, 390×844, 1440×1000 and 390×400**. All eight scenes had no horizontal overflow, 16px inputs, scrollable long forms and reachable Save footers. Visible new controls/header Save meet 44px height. Historical scenes kept the type fixed, archived Text input disabled and Boolean false distinct, with zero current-configuration reads. No remote API requests or runtime exceptions occurred in these checks.
- Temporary browser fixtures were removed. No backend or database tests/migrations were run for this frontend task.

Physical iPhone/Safari/Capacitor keyboard and safe-area checks remain manual. Native selectors can differ from headless Chrome. Very large libraries still require separate option-list requests per applicable Select field because that is the existing API contract.

## Manual verification

Perform these steps only against an explicitly selected development/staging backend **after its Phase 1/2 migrations and matching backend code are deployed**:

1. Open a project's Add Note modal. Confirm the active default/template and field order. Switch to Without type and confirm the legacy template flow. Repeat with no active types.
2. Enter a large exact decimal, zero, whitespace/newlines in Text, Boolean false and a Select option. Save and inspect the journal disclosure. Confirm values and the selected type round-trip correctly.
3. Edit the note, clear one optional value and save. Confirm other values remain. Note Type must stay fixed. A legacy note must remain untyped.
4. Edit content/values on a new draft, switch type and cancel. Confirm drafts remain; then approve switching and confirm type-specific drafts reset while title/date/tasks/scores remain.
5. Change Required/order/labels/associations after saving a note. Reopen it: its historical form must retain original configuration. Archive a field/option and verify retain/optional-clear restrictions; restore a captured option and verify selection becomes available. Do not expect new options/fields to appear on old notes.
6. With a controlled mocked/staging failure, let the note save and an evaluation fail. Retry: there must be one note and only remaining evaluation writes. Simulate an uncertain create result and verify Save is blocked until explicit journal inspection.
7. Verify collaborator ownership and read-only journal behavior, discard confirmation for a field-only draft, and scrolling/Save reachability at 320px, 390px, desktop and a short/keyboard viewport.

**Development/production migration deployment remains a separate, explicitly authorized step and is required before live end-to-end use.** This task did not deploy the backend or apply migrations.
