import { api } from '@/services/api'
import type { DailyStructureResponse, DietDetailResponse, CreatedDietResponse } from '@/types/diets'

export const dietsApi = {
  createDraftDiet(data: { name: string }): Promise<CreatedDietResponse> {
    return api.post('/diets', data)
  },

  getDiet(dietId: number): Promise<DietDetailResponse> {
    return api.get<DietDetailResponse>(`/diets/${dietId}`)
  },

  getDailyStructure(): Promise<DailyStructureResponse> {
    return api.get<DailyStructureResponse>('/diets/daily-structures')
  },
}
