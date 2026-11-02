import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type { FieldSeries, FieldSeriesInterval } from '@/types/projectFieldSeries'

export const projectFieldSeriesApi = {
  series(
    project: number,
    field: number,
    filters: {
      from: string
      to: string
      interval: FieldSeriesInterval
      note_type_id: string
      page: number
    },
  ): Promise<ResourceItem<FieldSeries>> {
    const query = new URLSearchParams({
      from: filters.from,
      to: filters.to,
      interval: filters.interval,
      page: String(filters.page),
      per_page: '50',
    })
    if (filters.note_type_id) query.set('note_type_id', filters.note_type_id)
    return api.get(`/projects/${project}/custom-fields/${field}/series?${query}`)
  },
}
