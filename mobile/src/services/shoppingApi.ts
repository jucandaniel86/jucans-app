import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type {
  ActiveShoppingList,
  ShoppingRecipeResult,
  ShoppingItem,
  ShoppingListsResponse,
  ShoppingListSummary,
  ManualShoppingItemInput,
  ShoppingItemUpdateInput,
  ShoppingListRemovalResult,
  ShoppingListUser,
  ShoppingListVisibility,
} from '@/types/shopping'

export const shoppingApi = {
  getOpen(): Promise<{ data: ShoppingListSummary[] }> {
    return api.get('/shopping-lists/open')
  },
  createOwn(): Promise<ResourceItem<ShoppingListSummary>> {
    return api.post('/shopping-lists')
  },
  getShareableUsers(): Promise<{ data: { id: number; name: string }[] }> {
    return api.get('/shopping-lists/shareable-users')
  },
  list(page = 1): Promise<ShoppingListsResponse> {
    return api.get(`/shopping-lists?page=${page}`)
  },
  getList(id: number): Promise<ResourceItem<ActiveShoppingList>> {
    return api.get(`/shopping-lists/${id}`)
  },
  setVisibility(
    id: number,
    visibility: ShoppingListVisibility,
  ): Promise<ResourceItem<ShoppingListSummary>> {
    return api.patch(`/shopping-lists/${id}/visibility`, { visibility })
  },
  getUsers(id: number): Promise<{ data: ShoppingListUser[] }> {
    return api.get(`/shopping-lists/${id}/users`)
  },
  attachUser(id: number, userId: number): Promise<ResourceItem<ShoppingListUser>> {
    return api.post(`/shopping-lists/${id}/users`, { user_id: userId })
  },
  detachUser(id: number, userId: number): Promise<void> {
    return api.delete(`/shopping-lists/${id}/users/${userId}`)
  },
  closeList(listId: number): Promise<ResourceItem<ShoppingListSummary>> {
    return api.post(`/shopping-lists/${listId}/close`)
  },

  addRecipe(listId: number, recipeId: number): Promise<ResourceItem<ShoppingRecipeResult>> {
    return api.post(`/shopping-lists/${listId}/recipes/${recipeId}`)
  },

  removeRecipe(listId: number, recipeId: number): Promise<ResourceItem<ShoppingListRemovalResult>> {
    return api.delete(`/shopping-lists/${listId}/recipes/${recipeId}`)
  },

  addManualItem(
    listId: number,
    input: ManualShoppingItemInput,
  ): Promise<ResourceItem<ShoppingItem>> {
    return api.post(`/shopping-lists/${listId}/items`, input)
  },

  setItemChecked(
    listId: number,
    itemId: number,
    checked: boolean,
  ): Promise<ResourceItem<ShoppingItem>> {
    return api.patch(`/shopping-lists/${listId}/items/${itemId}`, { is_checked: checked })
  },

  updateItem(
    listId: number,
    itemId: number,
    input: ShoppingItemUpdateInput,
  ): Promise<ResourceItem<ShoppingItem>> {
    return api.patch(`/shopping-lists/${listId}/items/${itemId}`, input)
  },

  deleteItem(listId: number, itemId: number): Promise<void> {
    return api.delete(`/shopping-lists/${listId}/items/${itemId}`)
  },

  exportText(listId: number): Promise<{ text: string; status: number }> {
    return api.getText(`/shopping-lists/${listId}/export`)
  },
}
