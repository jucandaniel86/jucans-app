# Removing a recipe from a shopping list

`DELETE /api/shopping-lists/{shoppingList}/recipes/{recipe}` requires
`auth:sanctum` and the existing `ShoppingListPolicy::update` permission on the
explicit list. Creators, shared members, and public-list users may remove a recipe
from an open list. Multiple accessible open lists do not make this endpoint ambiguous.

The operation locks the selected list and affected items inside a transaction,
detaches the recipe, and deletes only its source contributions in that list.
Items affected through these sources are deleted when no sources remain,
including checked items and items with quantity overrides. Remaining items reuse
the existing numeric/null quantity aggregation, retaining checked state and
manual overrides. Source-free manual products are never selected or recalculated.

Remaining source units must match the item unit and its canonical Ingredient
unit when that Ingredient still exists. Existing null/empty/`none` equivalence
is preserved. Invalid remaining units reject the complete operation with 422;
no unit conversion or edits to Ingredients or raw recipe ingredients occur.

Success returns 200 with the existing list resource: `data.items` (including
remaining sources and categories), `data.recipes` (remaining recipe resources),
and updated recipe/item/unchecked counts and ownership metadata. The `recipes`
field is conditional on that relationship being loaded, so existing responses
do not gain an unnecessary recipes query.

Unauthenticated requests return 401. Missing lists/recipes or unattached recipes,
including already removed recipes, return 404 without changing state. Denied
modification permissions, including closed lists, return 403.
