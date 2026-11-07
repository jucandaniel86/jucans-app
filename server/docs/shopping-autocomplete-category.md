# Shopping List — Autocomplete + Category

Uses existing `ingredients.default_unit`, `ingredients.normalized_name`,
`ingredients.shopping_category_id`, `shopping_list_items.shopping_category_id`
and `shopping_categories`; no new category system, no category backfill and no
new ingredients are created by selecting a suggestion.

## Deployment

1. Existing Shopping List migrations must already be installed.
2. Run **either** migration `2026_11_06_000001_add_pharmacy_shopping_category.php`
   **or** paste `sql/shopping-autocomplete-category.sql` into phpMyAdmin/cPanel.
   Both insert Farmacie with emoji 💊 and sort order 100 only when absent.
   Existing categories/items are untouched. SQL does not register a Laravel
   migration-ledger entry. Migration rollback intentionally preserves the category.
3. Deploy backend routes/services and rebuild/sync the Capacitor frontend normally.

No database commands or migrations were executed during implementation.

## API

Authenticated routes:

- `GET /api/shopping-categories`: reuses the existing read-only category controller
  and resource for ordinary authenticated users; administration remains unchanged.
- `GET /api/shopping-items/suggestions?query=...`: returns `data`, at most eight
  `{name, unit, category: {id, name, emoji, sort_order}|null, source}` records.
  `source` is `ingredients` or `shopping_list_items`. Empty query returns no rows.
- Existing item POST/PATCH additionally accept optional nullable
  `shopping_category_id`, validated against `shopping_categories.id`.
  Existing `shopping_category` resource includes the saved category. Omitted
  category leaves old item category unchanged; explicit null clears it.

Suggestions normalize case, Romanian diacritics and whitespace, prioritize prefix
matches, and deduplicate normalized names. Ingredients override history metadata.
For a history-only name, the newest accessible item by `updated_at`, then ID, is
used. History queries apply `ShoppingList::accessibleTo` on candidate and
latest-metadata aggregation subqueries, so inaccessible private/shared lists cannot influence
results or metadata. Closed accessible lists are included as history.

Queries bound candidate sets before materializing them (eight per source), eager
load categories, and do not issue per-item queries. Substring/history normalization
uses SQL expressions without a new stored column; this can scan many rows on very
large histories. SQL whitespace normalization covers ordinary spaces and ASCII
whitespace; PHP additionally handles Unicode whitespace. This is intended for
MySQL/MariaDB production, not a portable SQLite query.

## Frontend and manual verification

The product input is reused in manual Add/Edit. It debounces 280 ms, discards stale
responses, and applies unit/category only on explicit selection; quantity is never
changed. The existing AppSelect renders category. Suggestions are absolute,
anchored to the product input, portalled outside clipping ancestors and kept within
the current modal when present. VisualViewport events resize/reposition the menu
around the iOS keyboard. At most four 48px suggestions are visible, with internal
scrolling for the remaining results. No main-list scroll calls are introduced.

No automated tests were created, modified or run. Static type/lint/build and PHP
syntax checks are separate from functional verification.

On iPhone verify:

1. Add with no suggestion, no category, then with Farmacie. Reopen and edit/clear
   category; check grouping and persistence after reload.
2. Search mixed case and Romanian diacritics; prefix matches come first. A product
   in both sources appears once with ingredient metadata. Repeated history-only
   products use the most recently edited accessible item.
3. Set quantity before selecting; it stays unchanged. Override unit/category,
   type more while an earlier query is pending, and verify no metadata overwrite.
4. Check private/shared/public access with two users, including membership removal.
5. With keyboard open in list detail, shopping overlay and floating list: tap a
   suggestion, scroll its remaining options, close by outside tap/Escape, and test
   rotation. Verify no clipping, main-list jumps, focus zoom or double-tap zoom.
6. Check keyboard arrows/Enter, manual Edit, recipe-item category editing, checkbox
   movement, deletion, recipe contribution quantities and sharing.
