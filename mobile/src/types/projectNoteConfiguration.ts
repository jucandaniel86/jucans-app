export type ProjectCustomFieldType = 'number' | 'select' | 'boolean' | 'text'
export type ProjectNumericAggregation = 'average' | 'sum' | 'min' | 'max' | 'latest'

export interface ProjectCustomField {
  id: number
  project_id: number
  name: string
  key: string
  description: string | null
  type: ProjectCustomFieldType
  unit: string | null
  // DECIMAL values are strings on the wire. Preserve precision and trailing zeros.
  min: string | null
  max: string | null
  step: string | null
  aggregation: ProjectNumericAggregation | null
  readonly first_value_at: string | null
  archived_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface ProjectCustomFieldOption {
  id: number
  field_id: number
  label: string
  sort_order: number
  archived_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface ProjectNoteType {
  id: number
  project_id: number
  name: string
  description: string | null
  template: string | null
  is_default: boolean
  archived_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface ProjectNoteTypeField {
  // This is a history-period ID; association mutation routes use field_id instead.
  id: number
  note_type_id: number
  field_id: number
  sort_order: number
  required: boolean
  activated_at: string
  deactivated_at: string | null
  // All current association endpoints eager-load this relationship.
  field: ProjectCustomField
}

export interface CreateProjectCustomFieldPayload {
  name: string
  key: string
  type: ProjectCustomFieldType
  description?: string | null
  unit?: string | null
  // The backend accepts numbers too; prefer strings for exact decimal input.
  min?: string | number | null
  max?: string | number | null
  step?: string | number | null
  aggregation?: ProjectNumericAggregation | null
}

// A field key is immutable. Omitted PATCH properties remain unchanged.
export type UpdateProjectCustomFieldPayload = Partial<Omit<CreateProjectCustomFieldPayload, 'key'>>

export interface CreateProjectCustomFieldOptionPayload {
  label: string
  sort_order?: number
}
export type UpdateProjectCustomFieldOptionPayload = Partial<CreateProjectCustomFieldOptionPayload>

export interface CreateProjectNoteTypePayload {
  name: string
  description?: string | null
  template?: string | null
}
export type UpdateProjectNoteTypePayload = Partial<CreateProjectNoteTypePayload>

export interface UpdateProjectNoteTypeFieldPayload {
  required?: boolean
  sort_order?: number
}
export interface AttachProjectNoteTypeFieldPayload extends UpdateProjectNoteTypeFieldPayload {
  field_id: number
}
export interface CreateAndAttachProjectNoteTypeFieldPayload extends UpdateProjectNoteTypeFieldPayload {
  field: CreateProjectCustomFieldPayload
}

export interface ReorderProjectConfigurationPayload {
  // Full ordered list: option IDs for options; field IDs for Note Type fields.
  ids: number[]
}
