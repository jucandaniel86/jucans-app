# Shopping List expense registration (backend)

POST `/api/shopping-lists/{shoppingList}/expense`, authenticated with Sanctum.

```json
{"project_id":9,"amount":"41.15","close_list":false,"timezone":"Europe/Bucharest"}
```

Prefer exact decimal strings (up to 12 integer digits and 6 decimal places).
Integers and losslessly representable six-place JSON numbers are also accepted.
Amounts are nonnegative and additionally validated against the configured Number
field's min/max/step. Timezone must be a named IANA identifier, not a UTC offset.

Only the owner or an explicit participant with current list access can register.
Public visibility alone is insufficient. An open list requires close_list=true
and the existing owner-only close permission. Historical lists use close_list=false.
Missing closed_at is rejected rather than substituting created_at or today's date.

A single transaction locks the target project before the list, rechecks create
permissions and the Shopping Note Type configuration, optionally closes the list,
then reuses Task, Execution and Note/Review services. The Task is one_time, DONE,
with a Single Review and the configured Number/Select values. It follows the existing DONE Task display behavior. The closure date in the requested timezone supplies scheduled_date,
performed_on and noted_at. Actual creation/completion/audit timestamps remain now.
Additional required Note fields are not invented: existing validation rejects
missing values and rolls everything back.

201 returns a new receipt; 200 returns a matching existing receipt:

```json
{"data":{"shopping_list_id":4,"project_id":9,"task_id":34,"note_id":16,"noted_at":"2026-11-06","note_deleted":false,"list_status":"closed","closed_at":"2026-11-05T22:30:00.000000Z"},"already_registered":false}
```

Uniqueness is (shopping_list_id, created_by), across all projects. A SHA-256 hash
binds normalized amount, project, close_list and timezone. Exact retries (including
41.15 versus 41.150000) return the original receipt without updating the Note.
Different parameters return 409. Retry the same payload after an uncertain result.
Soft-deleted Notes retain the slot and return note_deleted=true on matching replay.
Changes to configuration or Note contents do not rewrite the original request hash.
Current list and project access are still required for replay, but creation-only
configuration/state checks are not rerun for an already committed registration.
The receipt contains only the caller's registration identifiers, not Note contents.

The project/list locks serialize competing requests, including different target
projects. The DB UNIQUE constraint is the final duplicate-write barrier. No cache,
background work, cross-user synchronization or additional expense table is used.
No changes are made to generic Note/Task endpoints, Charts or the frontend.

Deployment order: configuration migration/SQL 000001, then origin migration/SQL
000002 (`sql/shopping-list-project-expense.sql`). Production migrations are not
executed by this implementation. SQLite tests do not validate MySQL lock scheduling.
