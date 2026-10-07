import type { RecipeSummary, ShoppingCategory, PaginationMeta, FoodTag } from '@/types/food'

export type ShoppingListTab = 'shopping' | 'recipes'
export type ShoppingListVisibility = 'private' | 'shared' | 'public'

export interface ShoppingListUser {
  id: number
  name: string
  username: string
  avatar: string | null
}

export interface ShoppingListRecipe {
  id: number
  name: string
  image_url?: string | null
  url?: string | null
  tags?: FoodTag[]
}

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

export interface ManualShoppingItemInput {
  name: string
  quantity: number | null
  unit: string | null
}

export interface ShoppingItemUpdateInput {
  is_checked?: boolean
  name?: string
  quantity?: number | null
  unit?: string | null
  reset_quantity?: boolean
}

export interface ShoppingListSummary {
  id: number
  name: string | null
  status: string
  visibility: ShoppingListVisibility
  created_by: number
  creator: ShoppingListUser
  is_creator: boolean
  is_shared_with_me: boolean
  closed_at: string | null
  created_at: string | null
  recipes_count: number
  items_count: number
  unchecked_items_count: number
}

export interface ActiveShoppingList extends ShoppingListSummary {
  items: ShoppingItem[]
  recipes?: ShoppingListRecipe[]
}

export interface ShoppingListRemovalResult extends ActiveShoppingList {
  recipes: ShoppingListRecipe[]
}

export interface ShoppingRecipeResult extends ShoppingListSummary {
  recipes: ShoppingListRecipe[]
  recipe: Omit<RecipeSummary, 'creator' | 'tags'>
  already_present: boolean
  items: ShoppingItem[]
}
