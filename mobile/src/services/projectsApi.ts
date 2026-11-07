import { api } from '@/services/api'
import type { ResourceCollection, ResourceItem } from '@/types/food'
import type {
  Project,
  ProjectCategory,
  ProjectCollaborator,
  ProjectShareableUser,
  ProjectListResponse,
  ProjectPayload,
  ProjectProgressSummary,
  ProjectStatus,
} from '@/types/projects'

export const projectsApi = {
  shareableUsers(id: number): Promise<ResourceCollection<ProjectShareableUser>> {
    return api.get(`/projects/${id}/shareable-users`)
  },
  collaborators(id: number): Promise<ResourceCollection<ProjectCollaborator>> {
    return api.get(`/projects/${id}/collaborators`)
  },
  addCollaborator(id: number, userId: number): Promise<ResourceItem<ProjectCollaborator>> {
    return api.post(`/projects/${id}/collaborators`, { user_id: userId, sharing_mode: 'all_tasks' })
  },
  removeCollaborator(id: number, collaboratorId: number): Promise<void> {
    return api.delete(`/projects/${id}/collaborators/${collaboratorId}`)
  },
  categories(): Promise<ResourceCollection<ProjectCategory>> {
    return api.get('/project-categories')
  },
  list(status: 'active' | 'inactive', page = 1): Promise<ProjectListResponse> {
    return api.get(`/projects?status=${status}&page=${page}&per_page=30`)
  },
  get(id: number): Promise<ResourceItem<Project>> {
    return api.get(`/projects/${id}`)
  },
  create(payload: ProjectPayload): Promise<ResourceItem<Project>> {
    return api.post('/projects', payload)
  },
  update(id: number, payload: ProjectPayload): Promise<ResourceItem<Project>> {
    return api.patch(`/projects/${id}`, payload)
  },
  status(id: number, status: ProjectStatus): Promise<ResourceItem<Project>> {
    return api.patch(`/projects/${id}/status`, { status })
  },
  summary(id: number): Promise<ResourceItem<ProjectProgressSummary>> {
    return api.get(`/projects/${id}/progress/summary`)
  },
}
