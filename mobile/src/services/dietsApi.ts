import { api } from '@/services/api'
import type {
  DailyStructureResponse,
  DietDetailResponse,
  CreatedDietResponse,
  DietDailyStructureResponse,
  DietSource,
  DietSourcesResponse,
  DietIngredientsResponse,
  DietIngredient,
} from '@/types/diets'

export const dietsApi = {
  createDraftDiet(data: { name: string }): Promise<CreatedDietResponse> {
    return api.post('/diets', data)
  },

  getDiet(dietId: number): Promise<DietDetailResponse> {
    return api.get<DietDetailResponse>(`/diets/${dietId}`)
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

  saveIngredient(dietId: number, payload: DietIngredient): Promise<DietIngredientsResponse> {
    return api.patch<DietIngredientsResponse>(`/diets/${dietId}/ingredients/${payload.id}`, payload)
  },
}
