import { api } from '@/services/api'
import type { ResourceCollection, ResourceItem } from '@/types/food'
import type {
  ProjectChartDefinition,
  ProjectChartPayload,
  ConfiguredChartsReport,
  BarGrouping,
} from '@/types/projectChartDefinitions'

const base = (project: number) => `/projects/${project}/charts`
export const projectChartDefinitionsApi = {
  list(project: number): Promise<ResourceCollection<ProjectChartDefinition>> {
    return api.get(base(project))
  },
  create(
    project: number,
    payload: ProjectChartPayload,
  ): Promise<ResourceItem<ProjectChartDefinition>> {
    return api.post(base(project), payload)
  },
  update(
    project: number,
    chart: number,
    payload: ProjectChartPayload,
  ): Promise<ResourceItem<ProjectChartDefinition>> {
    return api.patch(`${base(project)}/${chart}`, payload)
  },
  delete(project: number, chart: number): Promise<void> {
    return api.delete(`${base(project)}/${chart}`)
  },
  data(
    project: number,
    start: string,
    end: string,
    grouping: Record<number, BarGrouping>,
  ): Promise<ResourceItem<ConfiguredChartsReport>> {
    const query = new URLSearchParams({ start_date: start, end_date: end })
    Object.entries(grouping).forEach(([id, value]) => {
      if (value !== 'auto') query.set(`group_by[${id}]`, value)
    })
    return api.get(`${base(project)}/data?${query}`)
  },
}
