import { api } from '@/services/api'
import type {
  DailyStructureResponse,
  DietDetailResponse,
  CreatedDietResponse,
  DietDailyStructureResponse,
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
}
