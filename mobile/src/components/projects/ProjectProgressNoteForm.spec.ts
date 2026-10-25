// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProjectProgressNoteForm from './ProjectProgressNoteForm.vue'
import { projectProgressApi } from '@/services/projectProgressApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProgressNote, TaskEvaluation } from '@/types/projectProgress'

vi.mock('@/services/projectProgressApi', () => ({
  projectProgressApi: {
    preview: vi.fn(),
    createNote: vi.fn(),
    createEvaluation: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1, username: 'Ana' } }) }))
vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({ confirm: vi.fn().mockResolvedValue(true), error: vi.fn() }),
}))

it('saves edited template content with a numeric optional score and retries partial failure without duplicating the note', async () => {
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
    note_template: 'Original {date}',
  }
  const note: ProgressNote = {
    id: 40,
    project_id: 1,
    created_by: 1,
    updated_by: null,
    title: 'Progres',
    content: 'Conținut editat',
    noted_at: '2026-10-09',
    task_ids: [9],
    tasks: [{ id: 9, title: 'Task', deleted_at: null }],
    evaluations: [],
    deleted_at: null,
  }
  vi.mocked(projectProgressApi.preview).mockResolvedValue({ data: { content: 'Template generat' } })
  vi.mocked(projectProgressApi.createNote).mockResolvedValue({ data: note })
  vi.mocked(projectProgressApi.createEvaluation)
    .mockRejectedValueOnce(new ApiError('Conflict', 409))
    .mockResolvedValueOnce({ data: { id: 7 } as TaskEvaluation })
  const wrapper = mount(ProjectProgressNoteForm, {
    props: { project, initialTasks: note.tasks, initialDate: '2026-10-09' },
    global: { stubs: { ProjectTaskPicker: true } },
  })
  const textarea = wrapper.get('textarea')
  vi.spyOn(textarea.element, 'scrollIntoView').mockImplementation(() => {})
  expect((wrapper.get('input[type="date"]').element as HTMLInputElement).value).toBe('2026-10-09')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.text()).toContain('Completează conținutul notei.')
  expect(projectProgressApi.createNote).not.toHaveBeenCalled()
  await wrapper
    .findAll('button')
    .find((button) => button.text() === 'Aplică template')!
    .trigger('click')
  await flushPromises()
  expect((textarea.element as HTMLTextAreaElement).value).toBe('Template generat')
  await textarea.setValue('Conținut editat')
  await wrapper.get('input[type="number"]').setValue('8')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.createNote).toHaveBeenCalledExactlyOnceWith(1, {
    title: 'Progres · 09.10.2026',
    content: 'Conținut editat',
    noted_at: '2026-10-09',
    task_ids: [9],
  })
  expect(wrapper.text()).toContain('Unele evaluări nu au fost salvate')
  expect(wrapper.emitted('saved')).toBeUndefined()
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.createNote).toHaveBeenCalledTimes(1)
  expect(projectProgressApi.createEvaluation).toHaveBeenCalledTimes(2)
  expect(projectProgressApi.createEvaluation).toHaveBeenLastCalledWith(1, 9, {
    score: 8,
    evaluation_date: '2026-10-09',
    note_id: 40,
  })
  expect(wrapper.emitted('saved')).toHaveLength(1)
  expect(project.note_template).toBe('Original {date}')
  wrapper.unmount()
})
