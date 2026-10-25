import type { PaginationMeta } from '@/types/food'

export type ProjectStatus = 'active' | 'completed' | 'archived'
export type ProjectProgressType = 'completion' | 'daily_completion' | 'scored'
export type ProjectExecutionType = 'one_time' | 'recurring' | 'persistent'

export interface ProjectCategory {
  id: number
  name: string
  slug: string
  sort_order: number
}

export interface Project {
  id: number
  name: string
  description: string | null
  category_id: number
  category: ProjectCategory
  icon: string
  progress_type: ProjectProgressType
  default_execution_type: ProjectExecutionType
  note_template?: string | null
  status: ProjectStatus
  created_by: number
  owner?: { id: number; username: string; avatar: string | null }
  last_activity_at: string
}

export interface ProjectPayload {
  name: string
  description: string | null
  category_id: number
  icon: string
  progress_type: ProjectProgressType
  default_execution_type: ProjectExecutionType
  note_template?: string | null
}

export interface ProjectListResponse {
  data: Project[]
  meta: PaginationMeta
}

export interface ProjectProgressSummary {
  timezone: string
  completion: {
    total_tasks: number
    completed_tasks: number
    remaining_tasks: number
    archived_tasks: number
    by_execution_type?: Record<
      ProjectExecutionType,
      {
        completed_tasks: number
        remaining_tasks: number
        archived_tasks: number
      }
    >
  }
  daily: {
    date: string
    scheduled_occurrences: number
    completed_scheduled_occurrences: number
    completion_percentage: number | null
    evaluation_average: number | null
    evaluation_count: number
  }
}
