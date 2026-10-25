// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectTaskForm from './ProjectTaskForm.vue'
import ProjectTaskCard from './ProjectTaskCard.vue'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import type { Project } from '@/types/projects'
import type { ProjectTask, TaskOccurrence, TaskSchedule } from '@/types/projectTasks'
import { today } from '@/utils/projectTasks'

vi.mock('@/services/projectTasksApi', () => ({
  projectTasksApi: {
    create: vi.fn(),
    update: vi.fn(),
    scheduleVersions: vi.fn(),
    saveSchedule: vi.fn(),
    occurrence: vi.fn(),
    get: vi.fn(),
    execute: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({ confirm: vi.fn().mockResolvedValue(true), error: vi.fn() }),
}))
const project: Project = {
  id: 1,
  name: 'Project',
  description: null,
  category_id: 1,
  category: { id: 1, name: 'Health', slug: 'health', sort_order: 0 },
  icon: 'folder',
  progress_type: 'completion',
  default_execution_type: 'recurring',
  status: 'active',
  created_by: 1,
  last_activity_at: '',
}
const task: ProjectTask = {
  id: 9,
  project_id: 1,
  title: 'Task',
  description: null,
  created_by: 1,
  execution_type: 'one_time',
  priority: 'medium',
  status: 'active',
  scheduled_date: null,
  scheduled_time: null,
  due_date: null,
  due_time: null,
  deleted_at: null,
  show_in_daily_widget: true,
}
const occurrence: TaskOccurrence = {
  id: 2,
  occurrence_key: 'single',
  status: 'pending',
  lock_version: 4,
  is_recorded: true,
  scheduled_date: null,
  scheduled_time: null,
}

describe('Project task critical flows', () => {
  beforeEach(() => vi.resetAllMocks())
  it('retries a failed initial recurrence without creating a second task', async () => {
    vi.mocked(projectTasksApi.create).mockResolvedValue({
      data: { ...task, execution_type: 'recurring' },
    })
    vi.mocked(projectTasksApi.scheduleVersions).mockResolvedValue({
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        from: null,
        to: null,
        total: 0,
        per_page: 1,
        links: [],
        path: '',
      },
    })
    vi.mocked(projectTasksApi.saveSchedule)
      .mockRejectedValueOnce(new ApiError('Invalid schedule', 422))
      .mockResolvedValueOnce({
        data: {
          id: 1,
          version: 1,
          recurrence_type: 'daily',
          effective_from: '2026-01-01',
          effective_until: null,
          start_date: null,
          end_date: null,
          scheduled_time: null,
        } as TaskSchedule,
      })
    const wrapper = mount(ProjectTaskForm, { props: { project } })
    await wrapper.get('input[type="text"]').setValue('Task')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('a fost salvat, dar recurența nu a fost salvată')
    expect(wrapper.emitted('saved')).toBeUndefined()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(projectTasksApi.create).toHaveBeenCalledTimes(1)
    expect(projectTasksApi.saveSchedule).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('saved')).toHaveLength(1)
    wrapper.unmount()
  })
  it('reuses the exact idempotency key and expected version after an uncertain execution response', async () => {
    vi.mocked(projectTasksApi.occurrence).mockResolvedValue({ data: occurrence })
    vi.mocked(projectTasksApi.get).mockResolvedValue({ data: { ...task, status: 'completed' } })
    vi.mocked(projectTasksApi.execute)
      .mockRejectedValueOnce(new ApiError('Offline', 0))
      .mockResolvedValueOnce({ data: { ...occurrence, status: 'done', lock_version: 5 } })
    const wrapper = mount(ProjectTaskCard, { props: { project, task, compact: true } })
    await flushPromises()
    // List cards use the current date; calendar cards supply their selected date.
    await wrapper.get('.task-quick--note').trigger('click')
    expect(wrapper.emitted('progress')?.[0]).toEqual([task, today()])
    await wrapper.setProps({ selectedDate: '2026-10-09' })
    await flushPromises()
    await wrapper.get('.task-quick--note').trigger('click')
    expect(wrapper.emitted('progress')?.[1]).toEqual([task, '2026-10-09'])
    expect(projectTasksApi.execute).not.toHaveBeenCalled()
    await wrapper.get('.task-quick--done').trigger('click')
    await flushPromises()
    const retry = wrapper
      .findAll('button')
      .find((button) => button.text().startsWith('Reîncearcă done'))!
    expect(retry.exists()).toBe(true)
    vi.mocked(projectTasksApi.occurrence).mockResolvedValue({
      data: { ...occurrence, status: 'done', lock_version: 5 },
    })
    await retry.trigger('click')
    await flushPromises()
    const calls = vi.mocked(projectTasksApi.execute).mock.calls
    expect(calls).toHaveLength(2)
    expect(calls[1]).toEqual(calls[0])
    expect(calls[0]![4].lock_version).toBe(4)
    expect(wrapper.text()).toContain('Undo')
    expect(wrapper.find('.task-quick--note').exists()).toBe(true)
    await wrapper.setProps({ project: { ...project, status: 'archived' } })
    expect(wrapper.find('.task-quick--note').exists()).toBe(false)
    wrapper.unmount()
  })
})
