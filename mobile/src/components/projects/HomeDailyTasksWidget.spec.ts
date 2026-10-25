// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import HomeDailyTasksWidget from './HomeDailyTasksWidget.vue'
import { projectDailyTasksApi } from '@/services/projectDailyTasksApi'
import { projectsApi } from '@/services/projectsApi'
import type { DailyTasks } from '@/types/projectDailyTasks'
import type { Project } from '@/types/projects'

vi.mock('vue-router', () => ({
  onBeforeRouteLeave: vi.fn(),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/stores/notifications', () => ({ useNotificationStore: () => ({ success: vi.fn() }) }))
vi.mock('@/services/projectDailyTasksApi', () => ({ projectDailyTasksApi: { list: vi.fn() } }))
vi.mock('@/services/projectsApi', () => ({ projectsApi: { list: vi.fn() } }))

it('limits initial tasks, lazy-loads completed, and reuses note/task forms with today and project context', async () => {
  const project: Project = {
    id: 1,
    name: 'Health',
    description: null,
    category_id: 1,
    category: { id: 1, name: 'Health', slug: 'health', sort_order: 0 },
    icon: 'folder',
    progress_type: 'completion',
    default_execution_type: 'one_time',
    status: 'active',
    created_by: 1,
    last_activity_at: '',
  }
  const data: DailyTasks = {
    date: '2026-10-25',
    timezone: 'UTC',
    summary: { scheduled: 6, completed: 0 },
    counts: { overdue: 0, today: 6, persistent: 0, completed: 0 },
    projects: [project],
    pagination: { page: 1, last_page: 1, total: 6 },
    rows: Array.from({ length: 6 }, (_, index) => ({
      section: 'today',
      task: {
        id: index + 1,
        project_id: 1,
        created_by: 1,
        title: `Task ${index + 1}`,
        description: null,
        execution_type: 'one_time',
        priority: 'high',
        status: 'active',
        scheduled_date: '2026-10-25',
        scheduled_time: null,
        due_date: null,
        due_time: null,
        deleted_at: null,
        show_in_daily_widget: true,
      },
      occurrence: {
        id: null,
        occurrence_key: 'single',
        status: 'pending',
        lock_version: 0,
        is_recorded: false,
        scheduled_date: '2026-10-25',
        scheduled_time: null,
      },
    })),
  }
  vi.mocked(projectDailyTasksApi.list).mockImplementation(async (_page, completed) => ({
    data: completed ? { ...data, rows: [], pagination: { page: 1, last_page: 1, total: 0 } } : data,
  }))
  vi.mocked(projectsApi.list).mockResolvedValue({
    data: [project],
    meta: { current_page: 1, last_page: 1 },
  } as Awaited<ReturnType<typeof projectsApi.list>>)
  const wrapper = mount(HomeDailyTasksWidget, {
    global: {
      stubs: { ProjectTaskCard: true, ProjectProgressNoteForm: true, ProjectTaskForm: true },
    },
  })
  await flushPromises()
  expect(wrapper.text()).toContain('0 din 6 taskuri programate astăzi finalizate')
  expect(wrapper.findAllComponents({ name: 'ProjectTaskCard' })).toHaveLength(5)
  expect(projectDailyTasksApi.list).toHaveBeenCalledTimes(1)
  await wrapper
    .findAll('button')
    .find((button) => button.text().startsWith('Vezi toate'))!
    .trigger('click')
  expect(wrapper.findAllComponents({ name: 'ProjectTaskCard' })).toHaveLength(6)
  const card = wrapper.findComponent({ name: 'ProjectTaskCard' })
  expect(card.props('selectedDate')).toBe('2026-10-25')
  card.vm.$emit('progress', data.rows[0]!.task, '2026-10-24')
  await flushPromises()
  const noteForm = wrapper.findComponent({ name: 'ProjectProgressNoteForm' })
  expect(noteForm.props('initialDate')).toBe('2026-10-25')
  expect(noteForm.props('initialTasks')).toEqual([{ id: 1, title: 'Task 1', deleted_at: null }])
  const calls = vi.mocked(projectDailyTasksApi.list).mock.calls.length
  noteForm.vm.$emit('saved')
  await flushPromises()
  expect(wrapper.findComponent({ name: 'ProjectProgressNoteForm' }).exists()).toBe(false)
  expect(projectDailyTasksApi.list).toHaveBeenCalledTimes(calls + 1)
  await wrapper
    .findAll('button')
    .find((button) => button.text() === '+ Task')!
    .trigger('click')
  await flushPromises()
  await wrapper.get('select').setValue('1')
  expect(wrapper.findComponent({ name: 'ProjectTaskForm' }).props('initialDate')).toBe('2026-10-25')
  expect(wrapper.findComponent({ name: 'ProjectTaskForm' }).props('project').id).toBe(1)
  const details = wrapper.get('.daily-tasks__completed')
  expect((details.element as HTMLDetailsElement).open).toBe(false)
  ;(details.element as HTMLDetailsElement).open = true
  await details.trigger('toggle')
  await flushPromises()
  expect(projectDailyTasksApi.list).toHaveBeenLastCalledWith(1, true)
  wrapper.unmount()
})
