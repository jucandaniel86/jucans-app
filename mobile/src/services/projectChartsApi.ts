import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type { AnalyticsTimeline, ChartRange, TaskAnalytics } from '@/types/projectCharts'

export const projectChartsApi = {
  timeline(
    project: number,
    range: ChartRange,
    page = 1,
    date?: string,
  ): Promise<ResourceItem<AnalyticsTimeline>> {
    const query = new URLSearchParams({ range, page: String(page) })
    if (date) query.set('date', date)
    return api.get(`/projects/${project}/progress/timeline?${query}`)
  },
  task(
    project: number,
    task: number,
    range: ChartRange,
    page = 1,
    date?: string,
  ): Promise<ResourceItem<TaskAnalytics>> {
    const query = new URLSearchParams({ range, page: String(page) })
    if (date) query.set('date', date)
    return api.get(`/projects/${project}/progress/tasks/${task}?${query}`)
  },
}
