import type { TaskPage } from '@/types/projectTasks'

export interface NoteTask {
  id: number
  title: string
  deleted_at: string | null
}
export interface TaskEvaluation {
  id: number
  task_id: number
  evaluation_date: string
  score: number
  created_by: number
  note_id: number | null
  lock_version: number
  is_active: boolean
  withdrawn_at: string | null
}
export interface ProgressNote {
  id: number
  project_id: number
  created_by: number
  updated_by: number | null
  title: string
  content: string
  noted_at: string
  task_ids: number[]
  tasks: NoteTask[]
  evaluations: TaskEvaluation[]
  deleted_at: string | null
}
export interface EvaluationRevision {
  id: number
  evaluation_id: number
  actor_id: number
  previous_score: number | null
  new_score: number | null
  recorded_at: string
}
export interface NotePayload {
  title: string
  content: string
  noted_at: string
  task_ids?: number[]
}
export interface NoteFilters {
  search?: string
  task_id?: number
  from?: string
  to?: string
  general_only?: boolean
  page?: number
}
export type ProgressPage<T> = TaskPage<T>
