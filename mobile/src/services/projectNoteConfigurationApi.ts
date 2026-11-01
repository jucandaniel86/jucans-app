import { api } from '@/services/api'
import type { ResourceCollection, ResourceItem } from '@/types/food'
import type {
  ProjectCustomField,
  ProjectCustomFieldOption,
  ProjectNoteType,
  ProjectNoteTypeField,
  CreateProjectCustomFieldPayload,
  UpdateProjectCustomFieldPayload,
  CreateProjectCustomFieldOptionPayload,
  UpdateProjectCustomFieldOptionPayload,
  CreateProjectNoteTypePayload,
  UpdateProjectNoteTypePayload,
  AttachProjectNoteTypeFieldPayload,
  CreateAndAttachProjectNoteTypeFieldPayload,
  UpdateProjectNoteTypeFieldPayload,
  ReorderProjectConfigurationPayload,
} from '@/types/projectNoteConfiguration'

const fields = (project: number) => `/projects/${project}/custom-fields`
const options = (project: number, field: number) => `${fields(project)}/${field}/options`
const types = (project: number) => `/projects/${project}/note-types`
const typeFields = (project: number, type: number) => `${types(project)}/${type}/fields`

export const projectNoteConfigurationApi = {
  fields(project: number): Promise<ResourceCollection<ProjectCustomField>> {
    return api.get(fields(project))
  },
  field(project: number, field: number): Promise<ResourceItem<ProjectCustomField>> {
    return api.get(`${fields(project)}/${field}`)
  },
  createField(
    project: number,
    payload: CreateProjectCustomFieldPayload,
  ): Promise<ResourceItem<ProjectCustomField>> {
    return api.post(fields(project), payload)
  },
  updateField(
    project: number,
    field: number,
    payload: UpdateProjectCustomFieldPayload,
  ): Promise<ResourceItem<ProjectCustomField>> {
    return api.patch(`${fields(project)}/${field}`, payload)
  },
  archiveField(project: number, field: number): Promise<ResourceItem<ProjectCustomField>> {
    return api.post(`${fields(project)}/${field}/archive`)
  },
  restoreField(project: number, field: number): Promise<ResourceItem<ProjectCustomField>> {
    return api.post(`${fields(project)}/${field}/restore`)
  },
  options(project: number, field: number): Promise<ResourceCollection<ProjectCustomFieldOption>> {
    return api.get(options(project, field))
  },
  createOption(
    project: number,
    field: number,
    payload: CreateProjectCustomFieldOptionPayload,
  ): Promise<ResourceItem<ProjectCustomFieldOption>> {
    return api.post(options(project, field), payload)
  },
  updateOption(
    project: number,
    field: number,
    option: number,
    payload: UpdateProjectCustomFieldOptionPayload,
  ): Promise<ResourceItem<ProjectCustomFieldOption>> {
    return api.patch(`${options(project, field)}/${option}`, payload)
  },
  reorderOptions(
    project: number,
    field: number,
    payload: ReorderProjectConfigurationPayload,
  ): Promise<ResourceCollection<ProjectCustomFieldOption>> {
    return api.post(`${options(project, field)}/reorder`, payload)
  },
  archiveOption(
    project: number,
    field: number,
    option: number,
  ): Promise<ResourceItem<ProjectCustomFieldOption>> {
    return api.post(`${options(project, field)}/${option}/archive`)
  },
  restoreOption(
    project: number,
    field: number,
    option: number,
  ): Promise<ResourceItem<ProjectCustomFieldOption>> {
    return api.post(`${options(project, field)}/${option}/restore`)
  },
  noteTypes(project: number): Promise<ResourceCollection<ProjectNoteType>> {
    return api.get(types(project))
  },
  noteType(project: number, type: number): Promise<ResourceItem<ProjectNoteType>> {
    return api.get(`${types(project)}/${type}`)
  },
  createNoteType(
    project: number,
    payload: CreateProjectNoteTypePayload,
  ): Promise<ResourceItem<ProjectNoteType>> {
    return api.post(types(project), payload)
  },
  updateNoteType(
    project: number,
    type: number,
    payload: UpdateProjectNoteTypePayload,
  ): Promise<ResourceItem<ProjectNoteType>> {
    return api.patch(`${types(project)}/${type}`, payload)
  },
  setDefaultNoteType(project: number, type: number): Promise<ResourceItem<ProjectNoteType>> {
    return api.post(`${types(project)}/${type}/set-default`)
  },
  archiveNoteType(project: number, type: number): Promise<ResourceItem<ProjectNoteType>> {
    return api.post(`${types(project)}/${type}/archive`)
  },
  restoreNoteType(project: number, type: number): Promise<ResourceItem<ProjectNoteType>> {
    return api.post(`${types(project)}/${type}/restore`)
  },
  // history=true returns all association periods, not a point-in-time snapshot.
  noteTypeFields(
    project: number,
    type: number,
    history = false,
  ): Promise<ResourceCollection<ProjectNoteTypeField>> {
    return api.get(`${typeFields(project, type)}${history ? '?history=1' : ''}`)
  },
  attachField(
    project: number,
    type: number,
    payload: AttachProjectNoteTypeFieldPayload,
  ): Promise<ResourceItem<ProjectNoteTypeField>> {
    return api.post(typeFields(project, type), payload)
  },
  createAndAttachField(
    project: number,
    type: number,
    payload: CreateAndAttachProjectNoteTypeFieldPayload,
  ): Promise<ResourceItem<ProjectNoteTypeField>> {
    return api.post(`${typeFields(project, type)}/create`, payload)
  },
  updateNoteTypeField(
    project: number,
    type: number,
    field: number,
    payload: UpdateProjectNoteTypeFieldPayload,
  ): Promise<ResourceItem<ProjectNoteTypeField>> {
    return api.patch(`${typeFields(project, type)}/${field}`, payload)
  },
  detachField(project: number, type: number, field: number): Promise<void> {
    return api.delete(`${typeFields(project, type)}/${field}`)
  },
  reorderNoteTypeFields(
    project: number,
    type: number,
    payload: ReorderProjectConfigurationPayload,
  ): Promise<ResourceCollection<ProjectNoteTypeField>> {
    return api.post(`${typeFields(project, type)}/reorder`, payload)
  },
}
