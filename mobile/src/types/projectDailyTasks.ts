import type { Project } from '@/types/projects'
import type { ProjectTask, TaskOccurrence } from '@/types/projectTasks'

export type DailySection = 'overdue' | 'today' | 'persistent' | 'completed'
export interface DailyTaskRow {
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
  pagination: { page: number; last_page: number; total: number }
}
