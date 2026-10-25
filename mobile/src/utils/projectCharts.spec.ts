import { expect, it, vi } from 'vitest'
import { metricPoints, periodMetrics, chartSegments } from '@/utils/projectCharts'
import { projectChartsApi } from '@/services/projectChartsApi'
import { api } from '@/services/api'
import type { ProgressDay } from '@/types/projectCharts'

vi.mock('@/services/api', () => ({ api: { get: vi.fn() } }))
const day = (date: string, overrides: Partial<ProgressDay> = {}): ProgressDay => ({
  date,
  scheduled_occurrences: 0,
  completed_scheduled_occurrences: 0,
  skipped_scheduled_occurrences: 0,
  completion_percentage: null,
  done_occurrences: 0,
  evaluation_average: null,
  evaluation_count: 0,
  ...overrides,
})

it('weights period means by actual evaluation counts and ratios by scheduled occurrences', () => {
  const result = periodMetrics([
    day('2026-10-01', {
      evaluation_average: 10,
      evaluation_count: 1,
      scheduled_occurrences: 1,
      completed_scheduled_occurrences: 1,
    }),
    day('2026-10-02', {
      evaluation_average: 4,
      evaluation_count: 3,
      scheduled_occurrences: 3,
      skipped_scheduled_occurrences: 1,
    }),
    day('2026-10-03'),
  ])
  expect(result.average).toBe(5.5)
  expect(result.evaluationCount).toBe(4)
  expect(result.percentage).toBe(25)
  expect(periodMetrics([day('2026-10-03')])).toMatchObject({
    average: null,
    percentage: null,
    evaluationCount: 0,
  })
})

it('preserves missing observations, real zero completion and line gaps without inventing execution counts', () => {
  const days = [
    day('2026-10-01', { evaluation_average: 7, evaluation_count: 1, done_occurrences: 2 }),
    day('2026-10-02', { scheduled_occurrences: 1, completion_percentage: 0 }),
    day('2026-10-03', { evaluation_average: 8, evaluation_count: 1 }),
  ]
  const scores = metricPoints(days, 'score')
  expect(scores.map((point) => point.value)).toEqual([7, null, 8])
  expect(chartSegments(scores)).toEqual([[{ index: 0, value: 7 }], [{ index: 2, value: 8 }]])
  expect(metricPoints(days, 'ratio').map((point) => point.value)).toEqual([null, 0, null])
  expect(metricPoints(days, 'done').map((point) => point.value)).toEqual([2, 0, 0])
})

it('requests bounded All pages with a pinned calendar end date for project and task histories', async () => {
  vi.mocked(api.get).mockResolvedValue({ data: {} })
  await projectChartsApi.timeline(4, 'all', 2, '2026-10-25')
  await projectChartsApi.task(4, 9, 'all', 3, '2026-10-25')
  expect(api.get).toHaveBeenNthCalledWith(
    1,
    '/projects/4/progress/timeline?range=all&page=2&date=2026-10-25',
  )
  expect(api.get).toHaveBeenNthCalledWith(
    2,
    '/projects/4/progress/tasks/9?range=all&page=3&date=2026-10-25',
  )
})
