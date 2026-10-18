import { api } from '@/services/api'
import type {
  DietRecipesResponse,
  DietRecipePriority,
  DailyStructureResponse,
  DietDetailResponse,
  CreatedDietResponse,
  DietDailyStructureResponse,
  DietSource,
  DietSourcesResponse,
  DietIngredientsResponse,
  DietIngredientUpdate,
  DietGeneralInfoInput,
  DietListResponse,
  DietStatus,
} from '@/types/diets'

export const dietsApi = {
  getDiets(): Promise<DietListResponse> {
    return api.get<DietListResponse>('/diets')
  },

  deleteDiet(dietId: number): Promise<void> {
    return api.delete<void>(`/diets/${dietId}`)
  },

  createDraftDiet(data: { name: string }): Promise<CreatedDietResponse> {
    return api.post('/diets', data)
  },

  getDiet(dietId: number): Promise<DietDetailResponse> {
    return api.get<DietDetailResponse>(`/diets/${dietId}`)
  },

  updateDiet(dietId: number, input: DietGeneralInfoInput): Promise<DietDetailResponse> {
    const formData = new FormData()
    formData.append('_method', 'PATCH')
    formData.append('name', input.name)
    formData.append('description', input.description ?? '')
    formData.append('notes', input.notes ?? '')
    if (input.thumbnail) formData.append('thumbnail', input.thumbnail)
    if (input.removeThumbnail) formData.append('remove_thumbnail', '1')
    return api.post<DietDetailResponse>(`/diets/${dietId}`, formData)
  },

  updateDietStatus(dietId: number, status: DietStatus, name: string): Promise<DietDetailResponse> {
    const formData = new FormData()
    formData.append('_method', 'PATCH')
    formData.append('name', name)
    formData.append('status', status)
    return api.post<DietDetailResponse>(`/diets/${dietId}`, formData)
  },

  getDailyStructureConfig(): Promise<DailyStructureResponse> {
    return api.get<DailyStructureResponse>('/diets/daily-structures')
  },

  addDailyStructure(
    dietId: number,
    data: { daily_structure_id: number | null },
  ): Promise<DietDailyStructureResponse> {
    return api.post<DietDailyStructureResponse>(`/diets/${dietId}/daily-structure`, data)
  },

  changeDailyStructureOrder(
    dietId: number,
    itemId: number,
    direction: 'up' | 'down',
  ): Promise<DietDailyStructureResponse> {
    return api.patch<DietDailyStructureResponse>(
      `/diets/${dietId}/daily-structure/${itemId}/order`,
      {
        direction,
      },
    )
  },

  deleteDailyStructure(dietId: number, itemId: number): Promise<DietDailyStructureResponse> {
    return api.delete<DietDailyStructureResponse>(`/diets/${dietId}/daily-structure/${itemId}`)
  },

  addSource(dietId: number, payload: DietSource): Promise<DietSourcesResponse> {
    return api.post<DietSourcesResponse>(`/diets/${dietId}/sources`, payload)
  },

  updateSource(
    dietId: number,
    sourceId: number,
    payload: DietSource,
  ): Promise<DietSourcesResponse> {
    return api.patch<DietSourcesResponse>(`/diets/${dietId}/sources/${sourceId}`, payload)
  },

  deleteSource(dietId: number, sourceId: number): Promise<DietSourcesResponse> {
    return api.delete<DietSourcesResponse>(`/diets/${dietId}/sources/${sourceId}`)
  },

  addIngredient(dietId: number, ingredientId: number): Promise<DietIngredientsResponse> {
    return api.post<DietIngredientsResponse>(`/diets/${dietId}/ingredients`, {
      ingredient_id: ingredientId,
    })
  },

  deleteIngredient(dietId: number, ingredientId: number): Promise<DietIngredientsResponse> {
    return api.delete<DietIngredientsResponse>(`/diets/${dietId}/ingredients/${ingredientId}`)
  },

  saveIngredient(dietId: number, payload: DietIngredientUpdate): Promise<DietIngredientsResponse> {
    return api.patch<DietIngredientsResponse>(`/diets/${dietId}/ingredients/${payload.id}`, payload)
  },

  addRecipe(
    dietId: number,
    recipeId: number,
    priority: DietRecipePriority = 'normal',
  ): Promise<DietRecipesResponse> {
    return api.post<DietRecipesResponse>(`/diets/${dietId}/recipes`, {
      recipe_id: recipeId,
      priority,
    })
  },

  saveRecipe(
    dietId: number,
    recipeId: number,
    priority: DietRecipePriority,
  ): Promise<DietRecipesResponse> {
    return api.patch<DietRecipesResponse>(`/diets/${dietId}/recipes/${recipeId}`, { priority })
  },

  deleteRecipe(dietId: number, recipeId: number): Promise<DietRecipesResponse> {
    return api.delete<DietRecipesResponse>(`/diets/${dietId}/recipes/${recipeId}`)
  },
}
