# Progress Note editor v2

## Delivered UX

One editor replaces the inline note form in the Progress journal and Home daily-task widget. Project task-list/calendar actions still pass task/date context through ProjectDetailView into the Progress tab. Journal note actions open Edit mode. Home now preserves the date emitted by the task card instead of replacing it with the widget date.

The editor is fullscreen below 768px and a centered, rounded modal up to 860px wide and 90dvh tall on desktop. It uses the existing Jucans tokens and components, native dialogs, safe-area spacing, an independently scrolling body, and persistent header/footer actions. Background scroll and focus restoration follow the shopping-list overlay pattern. Input fonts are at least 16px; visual viewport resize handling keeps the mobile workspace within the keyboard-visible area.

Title/date share a row where space permits. Observation is the primary section, with a Clear action that changes observation text only. Linked task chips support removal and an expandable version of the existing paginated/searchable picker. Evaluations use a separate collapsible section; it starts collapsed for new notes and opens for editing existing evaluations or recovering failed scores.

New notes automatically request the existing project template preview with the initial task/date context. Saved notes never automatically apply it. Request sequence, context and content-revision checks prevent late template responses from replacing typed/cleared content. Manual replacement of nonempty text requires confirmation. Changing context after a template has been applied does not silently rewrite the observation; users can explicitly preview/apply again.

Dirty checks cover title, date, observation, linked task IDs and evaluation choices. Generated template defaults do not mark a pristine editor dirty. Close, Escape, backdrop and Vue Router leave/update attempts share the same discard check. Browser unload uses its standard confirmation where supported. Local native confirmation dialogs remain visible above the editor's browser top layer.

No Custom Fields, Note Types, Metrics, backend persistence or chart functionality was added.

## Files

Created:

- `src/components/projects/ProjectProgressNoteEditor.vue`: modal lifecycle, shared save actions, route/unload guards, scroll/focus and viewport handling.
- `src/components/projects/ProgressNoteConfirmDialog.vue`: native confirmations for discarding a draft and replacing observation text.
- `src/components/projects/projectProgressNoteEditor.css`: responsive editor/form styles scoped by editor classes.
- `src/components/projects/ProjectProgressNoteEditor.spec.ts`: modal, close, navigation, saving and partial-recovery tests.
- `src/components/projects/ProjectProgressTab.spec.ts`: Add/Edit entry points, calendar context, refresh and read-only tests.
- This document.

Modified:

- `src/components/projects/ProjectProgressNoteForm.vue`: retains business state/save handling; compact sections, safe template defaults, Clear, dirty state and exposed toolbar controls.
- `src/components/projects/ProjectTaskPicker.vue`: opt-in compact chips with removal; existing consumers retain the default presentation.
- `src/components/projects/ProjectProgressTab.vue`: launches the modal in place of the inline editor.
- `src/components/projects/HomeDailyTasksWidget.vue`: launches the same modal and preserves the supplied date.
- `src/components/projects/ProjectProgressNoteForm.spec.ts`: expanded form/template/save/permission coverage.
- `src/components/projects/HomeDailyTasksWidget.spec.ts`: shared editor and supplied-date expectations.

## Save compatibility

The actual persistence helper is `src/utils/projectProgress.ts` (`saveNoteAndScores`), with API access in `src/services/projectProgressApi.ts`. Both remain unchanged.

The form still saves the note first and then individual task evaluations. It retains `noteSaved` and per-task `finished` checkpoints. On partial failure, note fields remain frozen, confirmed scores are not resent, remaining evaluation inputs can be corrected, and both Save controls invoke the same retry handler. Complete success is emitted only when all intended writes succeed. An uncertain create response still prevents duplicate note creation and directs the user to inspect the journal.

Existing title/date/content and integer 1–10 score validation, explicit evaluation-update intent, original lock versions, deleted-task association preservation and owner/author restrictions remain in place. Existing parent refresh/success behavior runs after a successful save; partial changes trigger refresh without reporting complete success.

Observation and secondary sections remain separate, allowing a future Metrics section between Observation and Linked Tasks without adding placeholder UI now.

## Verification

- `npm run build`: production build and TypeScript checking passed.
- `npm run test:unit -- src/components/projects src/utils/projectProgress.spec.ts src/utils/projectCharts.spec.ts src/utils/projectLocalDate.spec.ts`: **35 tests passed in 9 files**.
- ESLint passed for changed Vue/TypeScript files; changed source files were formatted with Prettier.
- `git diff --check` passed.
- Headless Chrome with a temporary mocked preview harness: 320×568 and 390×844 were fullscreen, 1440×1000 used a centered 860×900 modal. No page/editor horizontal overflow, 16px inputs, background scroll lock, and visible header/footer were confirmed. At 320px the body scrolled independently. No runtime exceptions were observed. The harness was removed after verification; no backend requests or production writes were involved.
- A broader frontend suite run before the final two permission tests reported **208 passing / 16 failing tests across 50 files**, plus three unhandled errors. The same 16 failures reproduced in a separate checkout of unchanged Git HEAD using the same dependencies. They concern recipe ingredient mapping/add-recipe/discovery, AppDrawer route expectations, diet-list router/store setup and a pending diet-save route state. These unrelated failures were not modified.

## Manual verification checklist

Still required on a physical iPhone/Safari/Capacitor build:

1. Open from Home, project task list, selected calendar date and journal Add/Edit; confirm correct date/task context.
2. Check notch/home-indicator spacing in portrait and landscape. Open the keyboard for title, observation, task search and score inputs; ensure the caret and Save stay reachable, without page zoom or background movement.
3. Type a long observation, expand the task picker and evaluations, then scroll; confirm the toolbar/footer stay visible and no horizontal scrolling appears.
4. Change each editable value, try closing/back navigation, choose Keep Editing and then Discard. Close a pristine editor without a warning. Check scroll/focus restoration after closing.
5. With slow connectivity, type or Clear before a template response arrives; confirm the text is preserved. Edit an existing note and confirm its text is unchanged on opening.
6. Save a note and several scores; simulate a failed score request and retry. Confirm one note, no repeated successful scores, and accurate partial-success messaging. Check the uncertain-create safeguard while offline.

Desktop:

1. Confirm the modal is centered and not fullscreen; check keyboard Tab focus confinement and focus restoration.
2. Verify Escape/backdrop/navigation draft protection and the template replacement confirmation.
3. Exercise both Save controls where visible and attempt repeated submission; verify one operation and disabled conflicting actions.
4. Edit a scored note and verify explicit score updates, permissions, conflict handling, and unchanged dates for existing evaluations.

Physical iOS keyboard, safe-area and native-back behavior were not device-tested. Chrome viewport checks do not replace those checks. The task adds no persistent draft storage and no custom Capacitor navigation behavior.
