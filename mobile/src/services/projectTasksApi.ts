import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type { ProjectStatus } from '@/types/projects'
import type {
  ExecutionAction,
  ProjectTask,
  TaskCompletionEvent,
  TaskFilters,
  TaskOccurrence,
  TaskPage,
  TaskPayload,
  TaskSchedule,
  TaskSchedulePayload,
} from '@/types/projectTasks'

const base = (project: number, task?: number) =>
  `/projects/${project}/tasks${task === undefined ? '' : `/${task}`}`
export const projectTasksApi = {
  list(project: number, filters: TaskFilters, trash = false): Promise<TaskPage<ProjectTask>> {
    const query = new URLSearchParams({ sort: 'overdue', per_page: '10' })
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== '') query.set(key, String(value))
    })
    return api.get(`${base(project)}${trash ? '/deleted' : ''}?${query}`)
  },
  get(project: number, task: number): Promise<ResourceItem<ProjectTask>> {
    return api.get(base(project, task))
  },
  create(project: number, payload: TaskPayload): Promise<ResourceItem<ProjectTask>> {
    return api.post(base(project), payload)
  },
  update(
    project: number,
    task: number,
    payload: Partial<TaskPayload> & { status?: ProjectStatus },
  ): Promise<ResourceItem<ProjectTask>> {
    return api.patch(base(project, task), payload)
  },
  delete(project: number, task: number): Promise<void> {
    return api.delete(base(project, task))
  },
  restore(project: number, task: number): Promise<ResourceItem<ProjectTask>> {
    return api.post(`${base(project, task)}/restore`)
  },
  complete(project: number, task: number): Promise<ResourceItem<ProjectTask>> {
    return api.post(`${base(project, task)}/complete`)
  },
  occurrence(project: number, task: number, key: string): Promise<ResourceItem<TaskOccurrence>> {
    return api.get(`${base(project, task)}/occurrences/${key}`)
  },
  occurrences(
    project: number,
    task: number,
    from: string,
    to: string,
    page = 1,
  ): Promise<TaskPage<TaskOccurrence>> {
    return api.get(
      `${base(project, task)}/occurrences?${new URLSearchParams({ from, to, page: String(page), per_page: '100' })}`,
    )
  },
  execute(
    project: number,
    task: number,
    key: string,
    action: ExecutionAction,
    command: { request_id: string; lock_version: number },
  ): Promise<ResourceItem<TaskOccurrence>> {
    return api.post(`${base(project, task)}/occurrences/${key}/${action}`, {
      request_id: command.request_id,
      lock_version: command.lock_version,
    })
  },
  schedule(project: number, task: number): Promise<ResourceItem<TaskSchedule | null>> {
    return api.get(`${base(project, task)}/schedule`)
  },
  scheduleVersions(project: number, task: number): Promise<TaskPage<TaskSchedule>> {
    return api.get(`${base(project, task)}/schedule-versions?per_page=1`)
  },
  saveSchedule(
    project: number,
    task: number,
    payload: TaskSchedulePayload,
    updating: boolean,
  ): Promise<ResourceItem<TaskSchedule>> {
    return updating
      ? api.patch(`${base(project, task)}/schedule`, payload)
      : api.post(`${base(project, task)}/schedule`, payload)
  },
  history(
    project: number,
    task: number,
    from: string,
    to: string,
    page = 1,
  ): Promise<TaskPage<TaskCompletionEvent>> {
    return api.get(
      `${base(project, task)}/execution-history?${new URLSearchParams({ from, to, page: String(page), per_page: '20' })}`,
    )
  },
}
