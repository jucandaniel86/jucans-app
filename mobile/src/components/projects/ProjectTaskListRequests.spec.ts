// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ProjectTasksTab from './ProjectTasksTab.vue'
import ProjectTaskCard from './ProjectTaskCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import { projectProgressApi } from '@/services/projectProgressApi'
import type { Project } from '@/types/projects'
import type {
  CalendarTaskData,
  ProjectTask,
  TaskFilters,
  TaskPage,
  TaskSchedule,
} from '@/types/projectTasks'
import { today } from '@/utils/projectTasks'

vi.mock('@/services/projectTasksApi', () => ({
  projectTasksApi: {
    list: vi.fn(),
    calendar: vi.fn(),
    occurrence: vi.fn(),
    schedule: vi.fn(),
    scheduleVersions: vi.fn(),
    get: vi.fn(),
    history: vi.fn(),
    execute: vi.fn(),
  },
}))
vi.mock('@/services/projectProgressApi', () => ({
  onProgressNoteDeleted: () => () => {},
  projectProgressApi: { reviews: vi.fn() },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/stores/notifications', () => ({ useNotificationStore: () => ({ error: vi.fn() }) }))
const project: Project = {
  id: 1,
  name: 'P',
  description: null,
  category_id: 1,
  category: { id: 1, name: 'C', slug: 'c', sort_order: 0 },
  icon: 'folder',
  progress_type: 'completion',
  default_execution_type: 'one_time',
  status: 'active',
  created_by: 1,
  last_activity_at: '',
}
const rule: TaskSchedule = {
  id: 1,
  version: 1,
  recurrence_type: 'daily',
  effective_from: '2026-01-01',
  effective_until: null,
  start_date: null,
  end_date: null,
  scheduled_time: null,
}
const meta = {
  current_page: 1,
  last_page: 2,
  per_page: 10,
  total: 20,
  from: 1,
  to: 10,
  links: [],
  path: '',
}
const emptyReviews = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0 },
  review_context: { mode: 'single' as const, can_create: false, active_count: 0 },
}
function page(
  filters: TaskFilters,
  projectId = 1,
): TaskPage<ProjectTask> & { contexts: Record<number, CalendarTaskData> } {
  const type = filters.execution_type ?? 'one_time'
  const tasks =
    type === 'persistent'
      ? []
      : Array.from({ length: 10 }, (_, i): ProjectTask => ({
          id: (type === 'recurring' ? 100 : 1) + i + ((filters.page ?? 1) - 1) * 10,
          project_id: projectId,
          created_by: 1,
          title: `${filters.search || type}-${i}`,
          description: null,
          execution_type: type,
          review_mode: 'single',
          priority: filters.priority ?? 'medium',
          status: filters.status ?? 'active',
          scheduled_date: today(),
          scheduled_time: null,
          due_date: null,
          due_time: null,
          deleted_at: null,
          show_in_daily_widget: true,
        }))
  const contexts: Record<number, CalendarTaskData> = {}
  for (const task of tasks)
    contexts[task.id] = {
      task,
      has_schedule: type === 'recurring',
      current_rule: type === 'recurring' ? rule : null,
      latest_rule: type === 'recurring' ? rule : null,
      occurrences: [
        {
          id: task.id,
          occurrence_key: type === 'one_time' ? 'single' : today(),
          scheduled_date: today(),
          scheduled_time: null,
          status: 'pending',
          lock_version: 0,
          is_recorded: true,
          is_scheduled: true,
          reviews: structuredClone(emptyReviews),
        },
      ],
    }
  return {
    data: tasks,
    meta: { ...meta, current_page: filters.page ?? 1, total: tasks.length ? 20 : 0 },
    contexts,
  }
}
let wrapper: ReturnType<typeof mount<typeof ProjectTasksTab>>
beforeEach(() => {
  vi.resetAllMocks()
  document.body.innerHTML = '<div id="actions"></div>'
  vi.mocked(projectTasksApi.calendar).mockResolvedValue({
    start_date: today(),
    end_date: today(),
    data: [],
  })
  vi.mocked(projectTasksApi.list).mockImplementation(async (id, filters) => page(filters, id))
  vi.mocked(projectTasksApi.occurrence).mockResolvedValue({
    data: page({}).contexts[1]!.occurrences[0]!,
  })
  vi.mocked(projectTasksApi.schedule).mockResolvedValue({ data: rule })
  vi.mocked(projectTasksApi.scheduleVersions).mockResolvedValue({ data: [rule], meta })
  vi.mocked(projectProgressApi.reviews).mockResolvedValue({
    ...emptyReviews,
    meta: { ...meta, last_page: 1, total: 0 },
  })
})
afterEach(() => {
  if (wrapper?.exists()) wrapper.unmount()
  vi.useRealTimers()
})
async function enterList() {
  wrapper = mount(ProjectTasksTab, {
    attachTo: document.body,
    props: { project, actionsTarget: '#actions' },
  })
  await flushPromises()
  await wrapper.get('[aria-label="Afișează lista de taskuri"]').trigger('click')
  await flushPromises()
}
function reads() {
  return [
    projectTasksApi.list,
    projectTasksApi.occurrence,
    projectTasksApi.schedule,
    projectTasksApi.scheduleVersions,
    projectProgressApi.reviews,
    projectTasksApi.get,
  ].reduce((sum, method) => sum + vi.mocked(method).mock.calls.length, 0)
}
describe('List View request budget', () => {
  it('loads three task pages with no per-card read amplification', async () => {
    await enterList()
    expect(wrapper.findAllComponents(ProjectTaskCard)).toHaveLength(20)
    expect(reads()).toBe(3)
  })
  it.each([
    ['status', 0, 'completed'],
    ['priority', 1, 'high'],
  ] as const)(
    'refreshes each group once after changing %s without card requests',
    async (_, index, value) => {
      await enterList()
      vi.clearAllMocks()
      await wrapper.findAll('.task-filters select')[index]!.setValue(value)
      await flushPromises()
      expect(reads()).toBe(3)
      for (const call of vi.mocked(projectTasksApi.list).mock.calls)
        expect(call[1][index === 0 ? 'status' : 'priority']).toBe(value)
    },
  )
  it('debounces rapid search once and ignores whitespace-only changes', async () => {
    await enterList()
    vi.clearAllMocks()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const input = wrapper.get('.task-filters input')
    for (const value of ['a', 'ab', 'abc']) await input.setValue(value)
    await vi.advanceTimersByTimeAsync(299)
    expect(projectTasksApi.list).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(reads()).toBe(3)
    expect(
      vi.mocked(projectTasksApi.list).mock.calls.every((call) => call[1].search === 'abc'),
    ).toBe(true)
    await input.setValue(' abc ')
    await vi.advanceTimersByTimeAsync(350)
    expect(reads()).toBe(3)
  })
  it('aborts old filters and rejects late results even if transport ignores abort', async () => {
    await enterList()
    vi.clearAllMocks()
    const old: Array<{
      resolve: (value: ReturnType<typeof page>) => void
      filters: TaskFilters
      signal: AbortSignal
    }> = []
    vi.mocked(projectTasksApi.list).mockImplementation((id, filters, _, options) => {
      if (!filters.priority)
        return new Promise((resolve) => old.push({ resolve, filters, signal: options!.signal! }))
      return Promise.resolve(page(filters, id))
    })
    await wrapper.findAll('.task-filters select')[0]!.setValue('completed')
    expect(old).toHaveLength(3)
    await wrapper.findAll('.task-filters select')[1]!.setValue('high')
    await flushPromises()
    expect(old.every((request) => request.signal.aborted)).toBe(true)
    for (const request of old) request.resolve(page({ ...request.filters, search: 'STALE' }))
    await flushPromises()
    expect(
      wrapper
        .findAllComponents(ProjectTaskCard)
        .every((card) => card.props('task').priority === 'high'),
    ).toBe(true)
    expect(wrapper.text()).not.toContain('STALE')
    expect(reads()).toBe(6)
  })
  it('ignores responses from the previous project', async () => {
    await enterList()
    const old: Array<{
      resolve: (value: ReturnType<typeof page>) => void
      filters: TaskFilters
      signal: AbortSignal
    }> = []
    vi.mocked(projectTasksApi.list).mockImplementation((id, filters, _, options) =>
      id === 1
        ? new Promise((resolve) => old.push({ resolve, filters, signal: options!.signal! }))
        : Promise.resolve(page(filters, id)),
    )
    await wrapper.findAll('.task-filters select')[1]!.setValue('high')
    await wrapper.setProps({ project: { ...project, id: 2 } })
    await flushPromises()
    expect(old.every((request) => request.signal.aborted)).toBe(true)
    for (const request of old) request.resolve(page({ ...request.filters, search: 'OLD-PROJECT' }))
    await flushPromises()
    expect(
      wrapper
        .findAllComponents(ProjectTaskCard)
        .every((card) => card.props('task').project_id === 2),
    ).toBe(true)
    expect(wrapper.text()).not.toContain('OLD-PROJECT')
  })
  it('deduplicates an identical in-flight next page and retains both pages', async () => {
    await enterList()
    vi.clearAllMocks()
    let complete!: (value: ReturnType<typeof page>) => void
    vi.mocked(projectTasksApi.list).mockImplementation(
      () =>
        new Promise((resolve) => {
          complete = resolve
        }),
    )
    const button = wrapper
      .findAllComponents(AppButton)
      .find((button) => button.text() === 'Mai multe taskuri')!
    button.vm.$emit('click')
    button.vm.$emit('click')
    expect(projectTasksApi.list).toHaveBeenCalledTimes(1)
    complete(page({ execution_type: 'one_time', page: 2 }))
    await flushPromises()
    expect(wrapper.findAllComponents(ProjectTaskCard)).toHaveLength(30)
    expect(reads()).toBe(1)
  })
  it('keeps repeated List/Calendar switches bounded and clears pending search', async () => {
    await enterList()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await wrapper.get('.task-filters input').setValue('pending')
    for (let i = 0; i < 2; i++) {
      await wrapper.get('[aria-label="Afișează calendarul săptămânal"]').trigger('click')
      await flushPromises()
      await wrapper.get('[aria-label="Afișează lista de taskuri"]').trigger('click')
      await flushPromises()
    }
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(projectTasksApi.calendar).toHaveBeenCalledTimes(3)
    expect(reads()).toBe(9)
  })
  it('does not automatically retry a 429 response', async () => {
    vi.mocked(projectTasksApi.list).mockRejectedValue(new ApiError('Too Many Requests', 429))
    await enterList()
    expect(projectTasksApi.list).toHaveBeenCalledTimes(3)
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    await vi.advanceTimersByTimeAsync(60_000)
    await flushPromises()
    expect(reads()).toBe(3)
    expect(wrapper.text()).toContain('Too Many Requests')
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Încearcă din nou')!
      .trigger('click')
    await flushPromises()
    expect(reads()).toBe(4)
  })
  it('refreshes once after a saved edit without remount request amplification', async () => {
    await enterList()
    vi.clearAllMocks()
    wrapper.findAllComponents(ProjectTaskCard)[0]!.vm.$emit('changed')
    await flushPromises()
    expect(reads()).toBe(3)
    expect(wrapper.findAllComponents(ProjectTaskCard)).toHaveLength(20)
  })
  it('cancels pending list reads on unmount', async () => {
    await enterList()
    const signals: AbortSignal[] = []
    vi.mocked(projectTasksApi.list).mockImplementation((_, __, ___, options) => {
      signals.push(options!.signal!)
      return new Promise(() => {})
    })
    await wrapper.findAll('.task-filters select')[1]!.setValue('high')
    wrapper.unmount()
    expect(signals).toHaveLength(3)
    expect(signals.every((signal) => signal.aborted)).toBe(true)
  })
  it('does not fall back to per-card requests when aggregate data is incomplete', async () => {
    vi.mocked(projectTasksApi.list).mockImplementation(async (id, filters) => ({
      ...page(filters, id),
      contexts: {},
    }))
    await enterList()
    expect(wrapper.findAllComponents(ProjectTaskCard)).toHaveLength(0)
    expect(reads()).toBe(3)
    expect(wrapper.findAll('.projects-error')).toHaveLength(2)
  })
})
