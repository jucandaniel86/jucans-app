import type { Project } from '@/types/projects'
import type {
  CalendarReviews,
  TaskSchedule,
  ProjectTask,
  TaskOccurrence,
} from '@/types/projectTasks'

export type DailySection = 'overdue' | 'today' | 'persistent' | 'completed'
export interface DailyTaskRow {
  // Optional for older responses; PERF-02 always supplies these fields.
  reviews?: CalendarReviews
  has_schedule?: boolean
  current_rule?: TaskSchedule | null
  latest_rule?: TaskSchedule | null
  section: DailySection
  task: ProjectTask
  occurrence: TaskOccurrence
}
export interface DailyTasks {
  date: string
  timezone: string
  summary: { scheduled: number; completed: number }
  counts: Record<DailySection, number>
  rows: DailyTaskRow[]
  projects: Project[]
  pagination: { page: number; last_page: number; total: number; per_page?: number }
  overdue_pagination?: { page: number; last_page: number; total: number; per_page: number }
}
