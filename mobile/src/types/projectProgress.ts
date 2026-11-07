import type {
  ProjectCustomField,
  ProjectCustomFieldOption,
  ProjectNoteType,
} from '@/types/projectNoteConfiguration'
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
export type NoteFieldValue = string | boolean | number | null
export interface NoteFieldEntry {
  field_id: number
  value: NoteFieldValue
}
export interface NoteConfigurationField {
  field_id: number
  association_id: number
  required: boolean
  sort_order: number
  definition: Pick<
    ProjectCustomField,
    | 'id'
    | 'project_id'
    | 'key'
    | 'name'
    | 'description'
    | 'type'
    | 'unit'
    | 'min'
    | 'max'
    | 'step'
    | 'aggregation'
    | 'archived_at'
  >
  options: Pick<
    ProjectCustomFieldOption,
    'id' | 'field_id' | 'label' | 'sort_order' | 'archived_at'
  >[]
}
export interface NoteConfiguration {
  schema_version: number
  note_type: Pick<
    ProjectNoteType,
    'id' | 'project_id' | 'name' | 'description' | 'template' | 'is_default' | 'archived_at'
  >
  fields: NoteConfigurationField[]
}
export interface NoteCurrentState {
  note_type_archived_at: string | null
  fields: {
    field_id: number
    type: ProjectCustomField['type']
    unit: string | null
    archived_at: string | null
    options: { id: number; archived_at: string | null }[]
  }[]
}
export interface TaskReviewContext {
  task_id: number
  occurrence_key: string
}
export interface ProgressNote {
  review?: (TaskReviewContext & { user_id: number }) | null
  author?: { id: number; username: string }
  id: number
  project_id: number
  created_by: number
  updated_by: number | null
  title: string
  content: string
  noted_at: string
  created_at?: string | null
  updated_at?: string | null
  task_ids: number[]
  tasks: NoteTask[]
  evaluations: TaskEvaluation[]
  deleted_at: string | null
  // Optional only for compatibility with older legacy responses; typed notes require a snapshot.
  note_type_id?: number | null
  configuration?: NoteConfiguration | null
  configuration_captured_at?: string | null
  values?: NoteFieldEntry[]
  current_configuration_state?: NoteCurrentState | null
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
  review_task_id?: number
  review_occurrence_key?: string
  note_type_id?: number | null
  values?: NoteFieldEntry[]
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
