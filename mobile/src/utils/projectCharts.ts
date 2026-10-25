import type { ChartPoint, ProgressDay } from '@/types/projectCharts'

export const chartNumber = (value: number | null) =>
  value === null ? '—' : new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 2 }).format(value)

export function periodMetrics(days: ProgressDay[]) {
  let count = 0,
    totalScores = 0,
    scheduled = 0,
    completed = 0,
    done = 0
  for (const day of days) {
    if (day.evaluation_average !== null && day.evaluation_count > 0) {
      totalScores += day.evaluation_average * day.evaluation_count
      count += day.evaluation_count
    }
    scheduled += day.scheduled_occurrences
    completed += day.completed_scheduled_occurrences
    done += day.done_occurrences
  }
  return {
    evaluationCount: count,
    average: count ? totalScores / count : null,
    scheduled,
    completed,
    done,
    percentage: scheduled ? (100 * completed) / scheduled : null,
  }
}

// Missing observations split the line. Their dates retain their original x positions.
export function chartSegments(points: ChartPoint[]): { index: number; value: number }[][] {
  const groups: { index: number; value: number }[][] = []
  let current: { index: number; value: number }[] = []
  points.forEach((point, index) => {
    if (point.value === null) {
      if (current.length) groups.push(current)
      current = []
    } else current.push({ index, value: point.value })
  })
  if (current.length) groups.push(current)
  return groups
}

export function metricPoints(
  days: ProgressDay[],
  metric: 'score' | 'ratio' | 'done',
): ChartPoint[] {
  return days.map((day) => {
    if (metric === 'score')
      return {
        date: day.date,
        value: day.evaluation_count ? day.evaluation_average : null,
        detail:
          day.evaluation_count && day.evaluation_average !== null
            ? `${chartNumber(day.evaluation_average)}/10 · ${day.evaluation_count} evaluări`
            : 'Fără evaluări',
      }
    if (metric === 'ratio')
      return {
        date: day.date,
        value: day.scheduled_occurrences ? day.completion_percentage : null,
        detail: day.scheduled_occurrences
          ? `${day.completed_scheduled_occurrences}/${day.scheduled_occurrences} · ${chartNumber(day.completion_percentage)}%`
          : 'Fără execuții programate',
      }
    return {
      date: day.date,
      value: day.done_occurrences,
      detail: `${day.done_occurrences} execuții Done`,
    }
  })
}
