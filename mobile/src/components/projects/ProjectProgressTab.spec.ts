// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, expect, it, vi } from 'vitest'
import ProjectProgressTab from './ProjectProgressTab.vue'
import { projectProgressApi } from '@/services/projectProgressApi'
import type { Project } from '@/types/projects'
import type { ProgressNote } from '@/types/projectProgress'

vi.mock('@/services/projectProgressApi', () => ({ projectProgressApi: { notes: vi.fn() } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({ success: vi.fn(), error: vi.fn() }),
}))
const project: Project = {
  id: 1,
  name: 'Project',
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
const note: ProgressNote = {
  id: 40,
  project_id: 1,
  created_by: 1,
  updated_by: null,
  title: 'Saved title',
  content: 'Saved observation',
  noted_at: '2026-10-09',
  task_ids: [],
  tasks: [],
  evaluations: [],
  deleted_at: null,
}
const stubs = {
  ProjectProgressNoteEditor: true,
  ProjectTaskPicker: true,
  ProjectTaskEvaluations: { template: '<div />', methods: { refresh: vi.fn() } },
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(projectProgressApi.notes).mockResolvedValue({
    data: [note],
    meta: { current_page: 1, last_page: 1 },
  } as Awaited<ReturnType<typeof projectProgressApi.notes>>)
})
it('opens Add and Edit in the shared editor and refreshes the journal only after saved', async () => {
  const wrapper = mount(ProjectProgressTab, { props: { project }, global: { stubs } })
  await flushPromises()
  await wrapper
    .findAll('button')
    .find((value) => value.text().includes('Adaugă progres'))!
    .trigger('click')
  let editor = wrapper.findComponent({ name: 'ProjectProgressNoteEditor' })
  expect(editor.exists()).toBe(true)
  expect(editor.props('note')).toBeUndefined()
  editor.vm.$emit('cancel')
  await flushPromises()
  expect(wrapper.findComponent({ name: 'ProjectProgressNoteEditor' }).exists()).toBe(false)
  await wrapper
    .findAll('button')
    .find((value) => value.text() === 'Editează nota')!
    .trigger('click')
  editor = wrapper.findComponent({ name: 'ProjectProgressNoteEditor' })
  expect(editor.props('note')).toEqual(note)
  editor.vm.$emit('saved')
  await flushPromises()
  expect(wrapper.findComponent({ name: 'ProjectProgressNoteEditor' }).exists()).toBe(false)
  expect(projectProgressApi.notes).toHaveBeenCalledTimes(2)
  expect(wrapper.emitted('changed')).toHaveLength(1)
  wrapper.unmount()
})
it('preserves task/calendar date context when opening directly and consumes the entry intent', async () => {
  const task = { id: 9, title: 'Task', deleted_at: null }
  const wrapper = mount(ProjectProgressTab, {
    props: { project, initialTask: task, initialDate: '2026-10-02' },
    global: { stubs },
  })
  await flushPromises()
  const editor = wrapper.findComponent({ name: 'ProjectProgressNoteEditor' })
  expect(editor.props('initialTasks')).toEqual([task])
  expect(editor.props('initialDate')).toBe('2026-10-02')
  expect(wrapper.emitted('consumed')).toHaveLength(1)
  wrapper.unmount()
})
it('keeps inactive projects read-only', async () => {
  const wrapper = mount(ProjectProgressTab, {
    props: { project: { ...project, status: 'archived' } },
    global: { stubs },
  })
  await flushPromises()
  expect(wrapper.findComponent({ name: 'ProjectProgressNoteEditor' }).exists()).toBe(false)
  expect(
    wrapper
      .findAll('button')
      .some((value) => value.text().includes('Adaugă progres') || value.text() === 'Editează nota'),
  ).toBe(false)
  wrapper.unmount()
})
