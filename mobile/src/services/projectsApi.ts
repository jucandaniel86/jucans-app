import { api } from '@/services/api'
import type { ResourceCollection, ResourceItem } from '@/types/food'
import type {
  Project,
  ProjectCategory,
  ProjectListResponse,
  ProjectPayload,
  ProjectProgressSummary,
  ProjectStatus,
} from '@/types/projects'

export const projectsApi = {
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
