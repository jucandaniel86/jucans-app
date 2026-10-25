import type { PaginationMeta } from '@/types/food'
import type { ProjectExecutionType, ProjectStatus } from '@/types/projects'

export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent'
export type ExecutionAction = 'done' | 'undo' | 'skip'
export interface ProjectTask {
  id: number
  project_id: number
  created_by: number
  title: string
  description: string | null
  execution_type: ProjectExecutionType
  priority: TaskPriority
  status: ProjectStatus
  scheduled_date: string | null
  scheduled_time: string | null
  due_date: string | null
  due_time: string | null
  show_in_daily_widget: boolean
  deleted_at: string | null
}
export interface TaskPayload {
  title: string
  description: string | null
  execution_type?: ProjectExecutionType
  priority: TaskPriority
  scheduled_date: string | null
  scheduled_time: string | null
  due_date: string | null
  due_time: string | null
  show_in_daily_widget: boolean
}
export interface TaskPage<T> {
  data: T[]
  meta: PaginationMeta
}
export interface TaskOccurrence {
  id: number | null
  occurrence_key: string
  scheduled_date: string | null
  scheduled_time: string | null
  status: 'pending' | 'done' | 'skipped'
  lock_version: number
  is_recorded: boolean
  is_scheduled?: boolean
}
export interface TaskSchedulePayload {
  recurrence_type: 'daily' | 'every_n_days' | 'weekdays' | 'monthly'
  interval_days?: number
  weekdays?: number[]
  month_day?: number
  start_date: string | null
  end_date: string | null
  scheduled_time: string | null
}
export interface TaskSchedule extends TaskSchedulePayload {
  id: number
  version: number
  effective_from: string
  effective_until: string | null
}
export interface TaskCompletionEvent {
  id: number
  action: ExecutionAction
  actor_id: number
  performed_on: string
  recorded_at: string
}
export interface TaskFilters {
  execution_type?: ProjectExecutionType
  status?: ProjectStatus
  priority?: TaskPriority
  search?: string
  page?: number
  per_page?: number
}
