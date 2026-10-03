import type { RecipeSummary, ShoppingCategory, PaginationMeta } from '@/types/food'

export interface ShoppingListsResponse {
  data: ShoppingListSummary[]
  meta: PaginationMeta
}

export interface ShoppingItem {
  id: number
  ingredient_id: number | null
  name: string
  calculated_quantity: string | null
  quantity: string | null
  unit: string | null
  is_checked: boolean
  quantity_overridden: boolean
  shopping_category: ShoppingCategory | null
  sources?: { id: number; recipe_id: number; quantity: string | null; unit: string | null }[]
}

export interface ShoppingListSummary {
  id: number
  name: string | null
  status: string
  visibility: string
  created_by: number
  closed_at: string | null
  created_at: string | null
  recipes_count: number
  items_count: number
  unchecked_items_count: number
}

export interface ActiveShoppingList extends ShoppingListSummary {
  items: ShoppingItem[]
}

export interface ShoppingRecipeResult extends ShoppingListSummary {
  recipe: Omit<RecipeSummary, 'creator' | 'tags'>
  already_present: boolean
  items: ShoppingItem[]
}
