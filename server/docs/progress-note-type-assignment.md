# Assigning a Note Type to a saved untyped note

Use the existing authenticated note PATCH endpoint with `note_type_id` and
`values: [{field_id, value}]`. Existing partial-update fields remain optional.
No new endpoint, migration, dependency, environment change or SQL operation.

The existing update transaction locks the parent project, reloads/locks the
note, and authorizes the update before configuration capture. Existing owner/
author collaborator visibility and active-project rules remain in force.
A still-untyped note captures an active same-project type's current applicable
configuration, using exactly the typed-creation capture/validation path.
Capture time is UTC assignment time, never observation/creation time.
Required-field validation and all values, context, activity and first-value
markers participate in that transaction. Failures roll everything back.
First-value markers retain the existing UTC microsecond format and
`whereNull(first_value_at)` never-overwrite semantics. Context-only assignment
also counts as a note update, including types with no fields.

A typed note rejects explicit null or a different type with HTTP 409.
Resubmission of the same type follows ordinary historical value editing:
it never looks up/reselects the active type or captures a new snapshot. Thus
an uncertain response can be retried even if the type was subsequently archived;
normal field lifecycle restrictions still apply. Concurrent different-type
assignments serialize, with the later request rejected after reloading the
already typed note. Concurrent same-type edits retain existing last-write
semantics; this adds no optimistic versioning or request idempotency token.

The deployed Phase 2 MySQL trigger freezes context only when OLD.note_type_id
is non-null; note_context_check requires all three context columns together.
SQLite's context-update trigger similarly freezes already typed context and
checks complete context, valid JSON and project scope. Both already permit
a complete null-to-typed transition, so no forward migration is needed and
no deployed migration was edited. Phase 1/2 code and schema must already be
present when publishing the changed service/frontend. Do not replay migrations.

In the existing saved-untyped editor, “Atribuie tip de notă” starts a draft.
It loads active types and the existing field controls. Switching assignment
types can clear field drafts after confirmation, but never changes text,
title, date, tasks or evaluation drafts. Cancel clears only the assignment
and field values. No template is automatically applied; template actions
are hidden during assignment and earlier asynchronous previews invalidated.
Assignment persists only through the main Save, notePayload and the existing
saveNoteAndScores checkpoint. A confirmed save displays the fixed saved type;
evaluation retry skips note persistence entirely.

On an uncertain network/server response the draft is preserved and the type
picker, field reload and assignment cancellation are locked. Retry with the
same type, or close/reload the note before choosing another type/canceling.
Validation/lifecycle/permission failures also retain drafts. Existing value
validation against a captured snapshot may reject a retry if configuration
changed between loading the draft and a successful but unconfirmed capture;
reopen the saved note to inspect its authoritative historical configuration.

No tests were created, changed or run; no browser verification, manual scenarios,
database changes, migration execution or production deployment were performed.
