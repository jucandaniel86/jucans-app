# Shopping list access and sharing

All endpoints require `auth:sanctum`. Access is centralized in
`ShoppingList::scopeAccessibleTo` and `ShoppingListPolicy`:

- Private: creator only, even when sharing relationships remain in the pivot.
- Shared: creator and attached users.
- Public: every authenticated user.
- Contents can only be modified while the list is open.
- Only the creator can manage sharing, change visibility, or close a list.

## Sharing API

- `PATCH /api/shopping-lists/{shoppingList}/visibility`: `{ "visibility": "private|shared|public" }`.
  Returns the list resource. Existing pivot rows are retained.
- `GET /api/shopping-lists/{shoppingList}/users`: attached users in a `data` array.
- `POST /api/shopping-lists/{shoppingList}/users`: `{ "user_id": 123 }`.
  Returns the user resource, with 201 for a new attachment or 200 if already attached.
  The creator and nonexistent users are rejected with 422. Visibility is unchanged.
- `DELETE /api/shopping-lists/{shoppingList}/users/{user}`: idempotent detachment, 204.

List resources include `creator` (`id`, `name`, `username`, `avatar`), `is_creator`,
and `is_shared_with_me`, alongside existing status, visibility, and counts.
`name` uses the existing account's `username`; no new account identity is introduced.
`is_shared_with_me` means the list is currently shared, belongs to someone else,
and the requester is attached. It is false for public/private and owned lists.
The index includes every accessible list, open or closed, without duplicate rows.
Creator data is eager-loaded and membership is selected with an EXISTS subquery.

## Existing active-list assumptions

Previously, all active routes selected the highest-ID accessible open list, and
pivot membership granted access regardless of visibility. Shared users could
also close another user's list. These behaviors were unsafe once multiple
accessible open lists coexist.

No uniqueness constraint is imposed on accessible open lists. The index and
explicit list detail endpoint expose each list independently. Policies accept
an explicit list and support viewing and modifying shared/public lists without
depending on active-list selection.

Until explicit current-list selection is implemented, the legacy active resolver:

- Returns the sole accessible open list when there is exactly one.
- Returns null when none exist; the existing create/recipe flow may create a private list.
- Returns **409 Conflict** when two or more candidates exist. It does not pick one.

This guard applies to active reads, manual additions, checking/unchecking,
recipe additions, closing, and the existing create-or-return endpoint
`POST /api/shopping-lists`. Ambiguous writes do not mutate any list.
The create-or-return endpoint still reuses the sole accessible open list, even
if it is shared or public; it is not a separate "create my own list" operation.
Explicit item-mutation/recipe-addition routes and a stored current-list choice remain future work.
Recipe removal uses an explicit list ID; see [recipe removal](shopping-list-recipe-removal.md).
The unchanged frontend may show its existing error state for a 409 response.

New lists no longer attach their creator to the sharing pivot: ownership already
grants access. Existing creator pivot rows are left intact and do not determine
ownership or visibility. Visibility changes never delete sharing relationships.
