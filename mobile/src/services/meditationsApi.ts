import { api } from '@/services/api'
import type { ResourceCollection } from '@/types/food'
import type { Meditation } from '@/types/meditation'

export const meditationsApi = {
  list(): Promise<ResourceCollection<Meditation>> {
    return api.get('/admin/meditations')
  },
}
