# Projects — Add task to phone calendar (v1)

## Scope and dependency

`@ebarooni/capacitor-calendar` is pinned to **8.10.0** (MIT), peer dependency
`@capacitor/core >=8.0.0`. The app uses Capacitor **8.5.2**, iOS deployment target
15.0 and Android minSdk 24 / compileSdk 36, matching the plugin manifests.

Primary reference: https://ebarooni.github.io/capacitor-calendar/

Add/Edit exposes one unchecked checkbox using the existing `task-check` style.
TaskCard also exposes “Adaugă în calendar” in its existing menu for any user who
can view the task, including read-only collaborators. Both call the same service.
These actions appear only on iOS/Android when the native plugin is registered.
Browser behavior is unchanged; the plugin's web/ICS path is unused.
No backend, API, database or recurrence-rule changes are required.

## Save and native behavior

Task save and any changed recurrence save finish first. Only then is the explicit
checkbox consumed and the native event editor opened via `createEventWithPrompt`.
The parent form remains busy until the editor completes; a module-level guard also
prevents concurrent editor launches. Failed or uncertain Jucans saves never open
Calendar. Partial recurrence-save failures do not open Calendar either.

The event uses the saved task title/description. No project data is sent to a new
service. On iOS, write-only access is requested only after this successful save and
only for the checked action; pre-iOS 17 the plugin uses the legacy EventKit grant.
No reminder/full-access keys are added. Denied permission or native failures produce
an explicit warning that the task is saved in Jucans but the event is unconfirmed.
They do not enter the task/recurrence save-error handler.

On iOS a returned event ID confirms creation; no ID is reported as cancelled or
unconfirmed. On Android the external INSERT activity returns no event ID/save
status, so the app explicitly asks the user to verify the event rather than showing
a false success. No READ_CALENDAR or WRITE_CALENDAR Android permissions are needed
for this intent-only flow, and no direct calendar-provider APIs are called.

## Date, time and recurrence

- One-time tasks use the saved task/occurrence scheduled date. TaskCard exports
  recurring and persistent tasks for the displayed contextual date, using the
  already loaded occurrence's scheduled time and deadline where available.
  Add/Edit uses its explicit occurrence date or saved scheduled date.
- Start always comes from `scheduled_date` / `scheduled_time`, never from the
  current clock or the recurrence's `start_date`. Missing dates block export.
- End uses `due_date` / `due_time`, including the selected recurring occurrence's
  snapshot. The recurring rule's `end_date` is never an event boundary.
- For timed events, an end time without an end date belongs to the start date.
  A date-only deadline includes that whole day (exclusive next local midnight).
  A missing end, or an end not strictly later than start, defaults to a one-hour
  draft. The user can adjust it in the native editor; task data is not changed.
- Without a start time the event is all-day. `due_date` is its inclusive last
  day; absent/earlier deadlines fall back to a single day. A deadline time alone
  does not invent a start time. iOS receives local midnight to exclusive next
  midnight. Android INSERT receives UTC boundaries constructed from the same
  calendar date components, not converted from local midnight.
- Local component parsing rejects invalid dates/times, including DST gaps.
  Calendar-day arithmetic is used for all-day and date-only end boundaries.
- TaskCard requires an eligible recurring context: a scheduled virtual occurrence
  or an existing recorded occurrence. It passes that snapshot without HTTP reads.
  Add/Edit retains its existing point lookup after a successful save to verify
  the concrete recurring occurrence. No series, future enumeration or recurrence
  parameter is sent to the native editor.
- Existing snapshots retain their historical time/deadline. Neither export path
  modifies task status, permissions, recurrence, or external event identifiers.

## Duplicates and limitations

The checkbox always starts unchecked and is consumed before a single native call.
Ordinary edits do not recreate events. Each TaskCard menu invocation is a new
explicit export and can create a duplicate; there is no deduplication. Cancellation/failure is not retried
implicitly; reopening/editing requires checking the box explicitly again.

This is an export action, not synchronization. Intentionally checking the box again
can create another event for the same task. There is no event-ID database storage,
no cross-device deduplication, no reading calendar contents, and no updates/deletes
of previously exported events when a Jucans task changes or is removed. Check the
phone calendar before explicitly exporting the same occurrence again.

## Native setup / builds

Already applied in this workspace:

- `mobile/package.json` and `package-lock.json`: pinned dependency.
- `ios/App/App/Info.plist`: `NSCalendarsUsageDescription` (iOS 15/16) and
  `NSCalendarsWriteOnlyAccessUsageDescription` (iOS 17+), with Romanian descriptions.
- Capacitor sync registers the plugin in iOS `CapApp-SPM/Package.swift` and Android
  `capacitor.settings.gradle` / `app/capacitor.build.gradle`. Generated native config
  files and copied web assets are ignored by Git and regenerated by sync.
- AndroidManifest is unchanged because only the system INSERT editor is used.

On another checkout, from `mobile/`:

```sh
npm ci
npm run build
npx cap sync ios
npx cap sync android
npx cap open ios
# or: npx cap open android
```

Build/run with Xcode or Android Studio. A web-only deployment/refresh does not
install this native plugin into an older binary. Do not hand-edit generated plugin
registration files. No migrations or manual SQL are needed.

## Validation and manual iPhone verification

TypeScript, targeted ESLint, Vite build, plist syntax validation, and Capacitor sync
for iOS/Android passed. An unsigned iOS simulator app build via `xcodebuild ... build`
also passed with the calendar plugin linked and its permission descriptions present.
No automated tests were created, modified or run. Android native compilation and
runtime behavior on physical phones remain manual verification.

1. New task and Edit: checkbox defaults off. Save unchecked; no native prompt or
   permissions request. Browser: checkbox absent, ordinary save unchanged.
2. Check it and force a Jucans validation/network failure. Calendar must not open.
   Verify the existing uncertain-creation/recurrence-retry behavior is unchanged.
3. Save a dated/timed task checked. Verify title, description, exact local start,
   then save in the native editor. Verify the confirmation and event in Calendar.
4. Save a date-only task checked. Verify exactly that all-day date, including dates
   near DST boundaries; repeat in a negative-offset time zone if available.
5. Refuse permission, cancel the native editor, or use a device without a usable
   calendar. Jucans task must remain saved and the message must not claim a new event.
6. Save without a scheduled date checked: task saves, warning, no event today.
7. Edit an ordinary task without checking: no second event. Rapid repeated Save
   taps while checked must open at most one editor.
8. Recurring rule creation without a concrete date: no series/export. From a dated
   recurring card, edit/save checked: only that verified occurrence is prefilled.
   Try an unscheduled date and a failed recurrence save: no guessed event.
9. Change/delete the task afterward: existing phone event is untouched. Explicitly
   export again only if you intend a second event.
10. Android manual check: verify date-only/timed drafts, cancellation, no calendar
    app, and the neutral unconfirmed-result message.
