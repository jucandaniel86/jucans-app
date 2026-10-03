import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type {
  ActiveShoppingList,
  ShoppingRecipeResult,
  ShoppingItem,
  ShoppingListsResponse,
  ShoppingListSummary,
} from '@/types/shopping'

export const shoppingApi = {
  list(page = 1): Promise<ShoppingListsResponse> {
    return api.get(`/shopping-lists?page=${page}`)
  },
  getList(id: number): Promise<ResourceItem<ActiveShoppingList>> {
    return api.get(`/shopping-lists/${id}`)
  },
  closeActive(): Promise<ResourceItem<ShoppingListSummary> | void> {
    return api.post('/shopping-lists/active/close')
  },
  getActive(): Promise<ResourceItem<ActiveShoppingList | null>> {
    return api.get('/shopping-lists/active')
  },

  addRecipe(recipeId: number): Promise<ResourceItem<ShoppingRecipeResult>> {
    return api.post(`/shopping-lists/active/recipes/${recipeId}`)
  },

  setItemChecked(itemId: number, checked: boolean): Promise<ResourceItem<ShoppingItem>> {
    return api.patch(`/shopping-lists/active/items/${itemId}`, { is_checked: checked })
  },
}
