# Shopping list sharing UI

The list index uses `GET /shopping-lists` without filtering out lists owned by
other accounts. Rows and details show visibility, creator, and
`is_shared_with_me`. Only `is_creator` enables sharing controls and closing.
Closed lists remain read-only, but their creator can still manage sharing.

Sharing uses the existing endpoints:

- `GET /shopping-lists/{id}/users` loads attached accounts when the dialog opens.
- `POST /shopping-lists/{id}/users` sends `{ user_id }` for an existing account.
- `DELETE /shopping-lists/{id}/users/{userId}` removes an account after confirmation.
- `PATCH /shopping-lists/{id}/visibility` sends `{ visibility }`.

Adding a recipient to a private list attaches the account first, then sets
visibility to `shared`. A failed visibility change retains the attachment and
reports that the list is still private. Public lists remain public when accounts
are added or removed. Changing visibility never detaches accounts. The metadata
response is merged with existing items and recipes rather than replacing them.

## Existing backend limits

There is no account lookup endpoint. The dialog therefore accepts the existing
numeric account ID; each signed-in user can see their own ID on the list index.
No account IDs or family members are hardcoded.

Item additions, item checking, recipe additions, and closing still use the
legacy `active` endpoints. Shared recipients can edit the sole accessible open
list, while only its creator can close it. When multiple open lists are
accessible, the backend returns 409. The UI clears stale active state, keeps
explicit list details visible, and disables item editing. It does not silently
select a list or redirect a write to another list.

`POST /shopping-lists` reuses the sole accessible open list and returns 409 for
multiple candidates. It cannot independently create another owned list. This
UI does not present that endpoint as a "create my own list" action. Completing
the multiple-list family workflow requires backend support for explicit list
mutations and independent creation; username selection also requires account
lookup support.

## Verification

The component/API/view tests cover private-to-shared attachment, partial
failure and retry, invalid accounts, visibility retention, duplicate membership,
removal confirmation and retry, owner-only management, recipient item checking,
metadata merging, accessible list discovery, and 409 read-only details.

For manual verification, sign in as the recipient and note their account ID
on the list index. From the creator's list detail, open Partajare and add that
ID. In the recipient's account, reopen the list index and enter the shared list.
With exactly one accessible open list, check items and add a manual item.
The recipient must not see sharing or close controls. Remove the recipient as
the creator and reload recipient details to verify revoked access.
