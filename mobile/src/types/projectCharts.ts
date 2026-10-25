import type { ProjectExecutionType, ProjectProgressType } from '@/types/projects'

export type ChartRange = '7' | '30' | '90' | 'all'
export interface ProgressDay {
  date: string
  scheduled_occurrences: number
  completed_scheduled_occurrences: number
  skipped_scheduled_occurrences: number
  completion_percentage: number | null
  done_occurrences: number
  evaluation_average: number | null
  evaluation_count: number
}
export interface AnalyticsMetadata {
  project_id: number
  progress_type: ProjectProgressType
  timezone: string
  filters: { task_id: number | null; execution_type: ProjectExecutionType | null }
}
export interface AnalyticsTimeline extends AnalyticsMetadata {
  range: {
    from: string
    to: string
    selection: ChartRange
    page: number
    last_page: number
    next_page: number | null
  }
  series: ProgressDay[]
}
export interface TaskAnalytics extends AnalyticsTimeline {
  evaluations: { id: number; date: string; score: number; lock_version: number }[]
  executions: {
    date: string
    scheduled_date: string | null
    occurrence_key: string
    status: 'pending' | 'done' | 'skipped'
    is_recorded: boolean
    is_scheduled: boolean
    lock_version: number
  }[]
}
export interface ChartPoint {
  date: string
  value: number | null
  detail: string
}
