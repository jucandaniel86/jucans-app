// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProjectProgressNoteForm from './ProjectProgressNoteForm.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { projectProgressApi } from '@/services/projectProgressApi'
import { projectTasksApi } from '@/services/projectTasksApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProgressNote, TaskEvaluation } from '@/types/projectProgress'

vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: { noteTypes: vi.fn(), noteTypeFields: vi.fn(), options: vi.fn() },
}))
vi.mock('@/services/projectProgressApi', () => ({
  projectProgressApi: {
    preview: vi.fn(),
    createNote: vi.fn(),
    updateNote: vi.fn(),
    createEvaluation: vi.fn(),
    updateEvaluation: vi.fn(),
  },
}))
vi.mock('@/services/projectTasksApi', () => ({ projectTasksApi: { list: vi.fn() } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1, username: 'Ana' } }) }))
vi.mock('@/stores/notifications', () => ({ useNotificationStore: () => ({ error: vi.fn() }) }))

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
const task = { id: 9, title: 'Task', deleted_at: null }
const note: ProgressNote = {
  id: 40,
  project_id: 1,
  created_by: 1,
  updated_by: null,
  title: 'Progres',
  content: 'Observație salvată',
  noted_at: '2026-10-09',
  task_ids: [9],
  tasks: [task],
  evaluations: [],
  deleted_at: null,
}
const evaluation: TaskEvaluation = {
  id: 7,
  task_id: 9,
  created_by: 1,
  note_id: 40,
  score: 6,
  evaluation_date: '2026-10-09',
  lock_version: 3,
  is_active: true,
  withdrawn_at: null,
}
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((answer) => {
    resolve = answer
  })
  return { promise, resolve }
}
const mounted: ReturnType<typeof mount>[] = []
function editor(
  props: Partial<InstanceType<typeof ProjectProgressNoteForm>['$props']> = {},
  realPicker = false,
) {
  const wrapper = mount(ProjectProgressNoteForm, {
    props: { project, initialTasks: [task], initialDate: '2026-10-09', ...props },
    global: { stubs: realPicker ? {} : { ProjectTaskPicker: true } },
  })
  mounted.push(wrapper)
  return wrapper
}
function clickByText(wrapper: ReturnType<typeof editor>, text: string) {
  return wrapper
    .findAll('button')
    .find((button) => button.text() === text)!
    .trigger('click')
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.stubGlobal(
    'fetch',
    vi.fn().mockRejectedValue(new Error('Unexpected network request in mocked tests')),
  )
  vi.mocked(projectNoteConfigurationApi.noteTypes).mockResolvedValue({ data: [] })
  vi.mocked(projectProgressApi.preview).mockResolvedValue({ data: { content: 'Template generat' } })
  vi.mocked(projectProgressApi.createNote).mockResolvedValue({ data: note })
  vi.mocked(projectProgressApi.updateNote).mockResolvedValue({ data: note })
  vi.mocked(projectProgressApi.createEvaluation).mockResolvedValue({ data: evaluation })
  vi.mocked(projectProgressApi.updateEvaluation).mockResolvedValue({ data: evaluation })
})
afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.unstubAllGlobals()
})

it('automatically applies create-mode template using the preselected task/date and keeps generated defaults pristine', async () => {
  const wrapper = editor()
  await flushPromises()
  expect(projectProgressApi.preview).toHaveBeenCalledExactlyOnceWith(1, '2026-10-09', [9])
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Template generat')
  expect((wrapper.get('input[type="date"]').element as HTMLInputElement).value).toBe('2026-10-09')
  expect(wrapper.vm.dirty).toBe(false)
  expect((wrapper.get('.note-editor-evaluations').element as HTMLDetailsElement).open).toBe(false)
})

it('never automatically applies templates to saved notes and previews do not change saved content', async () => {
  const wrapper = editor({ note })
  await flushPromises()
  expect(projectProgressApi.preview).not.toHaveBeenCalled()
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(note.content)
  await clickByText(wrapper, 'Previzualizează')
  await flushPromises()
  expect(wrapper.text()).toContain('Template generat')
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(note.content)
  expect(wrapper.vm.dirty).toBe(false)
})

it('ignores late automatic template responses after typing, including later date changes', async () => {
  const pending = deferred<{ data: { content: string } }>()
  vi.mocked(projectProgressApi.preview).mockReturnValue(pending.promise)
  const wrapper = editor()
  await wrapper.get('textarea').setValue('Textul meu')
  await wrapper.get('input[type="date"]').setValue('2026-10-10')
  pending.resolve({ data: { content: 'Late template' } })
  await flushPromises()
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Textul meu')
  expect(wrapper.vm.dirty).toBe(true)
})

it('clearing an empty observation invalidates an outstanding template response', async () => {
  const pending = deferred<{ data: { content: string } }>()
  vi.mocked(projectProgressApi.preview).mockReturnValue(pending.promise)
  const wrapper = editor()
  await clickByText(wrapper, 'Golește')
  pending.resolve({ data: { content: 'Late template' } })
  await flushPromises()
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('')
})

it('uses the latest date/task context when automatic template requests race', async () => {
  const first = deferred<{ data: { content: string } }>()
  const second = deferred<{ data: { content: string } }>()
  vi.mocked(projectProgressApi.preview)
    .mockReturnValueOnce(first.promise)
    .mockReturnValueOnce(second.promise)
  const wrapper = editor()
  await flushPromises() // Wait for type loading to start the initial legacy preview.
  await wrapper.get('input[type="date"]').setValue('2026-10-10')
  second.resolve({ data: { content: 'Correct date' } })
  await flushPromises()
  first.resolve({ data: { content: 'Old date' } })
  await flushPromises()
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Correct date')
  expect(projectProgressApi.preview).toHaveBeenLastCalledWith(1, '2026-10-10', [9])
  expect(wrapper.vm.dirty).toBe(true) // date changed, even though generated content is pristine
})

it('clears only observation and retains title, date, task links and evaluation state across collapse', async () => {
  const wrapper = editor({ note: { ...note, evaluations: [evaluation] } })
  const details = wrapper.get('.note-editor-evaluations')
  expect((details.element as HTMLDetailsElement).open).toBe(true)
  await wrapper.get('input[type="checkbox"]').setValue(true)
  await wrapper.get('input[type="number"]').setValue(8)
  ;(details.element as HTMLDetailsElement).open = false
  await details.trigger('toggle')
  ;(details.element as HTMLDetailsElement).open = true
  await details.trigger('toggle')
  await clickByText(wrapper, 'Golește')
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('')
  expect((wrapper.get('input[type="text"]').element as HTMLInputElement).value).toBe(note.title)
  expect((wrapper.get('input[type="date"]').element as HTMLInputElement).value).toBe(note.noted_at)
  expect((wrapper.get('input[type="number"]').element as HTMLInputElement).value).toBe('8')
  expect(wrapper.findComponent({ name: 'ProjectTaskPicker' }).props('modelValue')).toEqual(
    note.tasks,
  )
})

it('selects and removes linked tasks through the existing paginated picker', async () => {
  vi.mocked(projectTasksApi.list).mockResolvedValue({
    data: [task],
    meta: { current_page: 1, last_page: 1 },
  } as Awaited<ReturnType<typeof projectTasksApi.list>>)
  const wrapper = editor({ project: { ...project, note_template: null }, initialTasks: [] }, true)
  const details = wrapper.get('.progress-task-picker details')
  ;(details.element as HTMLDetailsElement).open = true
  await details.trigger('toggle')
  await flushPromises()
  await wrapper.get('.progress-task-option').trigger('click')
  expect(wrapper.vm.dirty).toBe(true)
  expect(wrapper.get('.progress-task-chips').text()).toContain('Task')
  await wrapper.get('[aria-label="Elimină taskul Task"]').trigger('click')
  expect(wrapper.find('.progress-task-chips').exists()).toBe(false)
  expect(wrapper.vm.dirty).toBe(false)
})

it('preserves partial-save checkpoints and retries only failed scores without creating a second note', async () => {
  const secondTask = { ...task, id: 10, title: 'Second task' }
  vi.mocked(projectProgressApi.createEvaluation)
    .mockResolvedValueOnce({ data: evaluation })
    .mockRejectedValueOnce(new ApiError('Conflict', 409))
    .mockResolvedValueOnce({ data: { ...evaluation, task_id: 10 } })
  const wrapper = editor({ initialTasks: [task, secondTask] })
  await flushPromises()
  await wrapper.get('textarea').setValue('Conținut editat')
  await wrapper.findAll('input[type="number"]')[0]!.setValue('8')
  await wrapper.findAll('input[type="number"]')[1]!.setValue('7')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.createNote).toHaveBeenCalledExactlyOnceWith(1, {
    title: 'Progres · 09.10.2026',
    content: 'Conținut editat',
    noted_at: '2026-10-09',
    task_ids: [9, 10],
  })
  expect(wrapper.text()).toContain('Unele evaluări nu au fost salvate')
  expect(wrapper.emitted('saved')).toBeUndefined()
  expect(
    (wrapper.get('textarea').element.closest('fieldset') as HTMLFieldSetElement).disabled,
  ).toBe(true)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.createNote).toHaveBeenCalledTimes(1)
  expect(projectProgressApi.createEvaluation).toHaveBeenCalledTimes(3)
  expect(projectProgressApi.createEvaluation).toHaveBeenLastCalledWith(1, 10, {
    score: 7,
    evaluation_date: '2026-10-09',
    note_id: 40,
  })
  expect(wrapper.emitted('saved')).toHaveLength(1)
  expect(wrapper.vm.dirty).toBe(false)
})

it('updates an existing score only on explicit intent with the original lock_version', async () => {
  const wrapper = editor({ note: { ...note, evaluations: [evaluation] } })
  await wrapper.get('input[type="checkbox"]').setValue(true)
  await wrapper.get('input[type="number"]').setValue(9)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.updateNote).toHaveBeenCalledWith(1, 40, {
    title: note.title,
    content: note.content,
    noted_at: note.noted_at,
  })
  expect(projectProgressApi.updateEvaluation).toHaveBeenCalledExactlyOnceWith(1, 9, 7, {
    score: 9,
    lock_version: 3,
  })
  expect(projectProgressApi.createEvaluation).not.toHaveBeenCalled()
})

it('keeps validation and reveals invalid evaluation inputs', async () => {
  const wrapper = editor({ project: { ...project, note_template: null } })
  await wrapper.get('form').trigger('submit')
  expect(wrapper.text()).toContain('Completează conținutul notei.')
  await wrapper.get('textarea').setValue('Observation')
  await wrapper.get('input[type="number"]').setValue(11)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.text()).toContain('număr întreg între 1 și 10')
  expect((wrapper.get('.note-editor-evaluations').element as HTMLDetailsElement).open).toBe(true)
  expect(projectProgressApi.createNote).not.toHaveBeenCalled()
})

it('retains the uncertain-creation safeguard and never submits a duplicate note', async () => {
  vi.mocked(projectProgressApi.createNote).mockRejectedValue(new ApiError('Offline', 0))
  const wrapper = editor()
  await flushPromises()
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.text()).toContain('Nu putem confirma dacă nota a fost creată')
  expect(wrapper.vm.saveDisabled).toBe(true)
  await wrapper.get('form').trigger('submit')
  expect(projectProgressApi.createNote).toHaveBeenCalledTimes(1)
})

it('requires confirmation before manually replacing existing text', async () => {
  const wrapper = editor({ note })
  await clickByText(wrapper, 'Aplică template')
  await flushPromises()
  expect(wrapper.get('dialog').attributes('open')).toBeDefined()
  await clickByText(wrapper, 'Păstrează textul')
  await flushPromises()
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe(note.content)
  await clickByText(wrapper, 'Aplică template')
  await flushPromises()
  await clickByText(wrapper, 'Aplică')
  await flushPromises()
  expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Template generat')
})

it('detects evaluation-only changes and keeps foreign-authored scores read-only', async () => {
  const wrapper = editor({ note: { ...note, evaluations: [evaluation] } })
  expect(wrapper.vm.dirty).toBe(false)
  await wrapper.get('input[type="checkbox"]').setValue(true)
  expect(wrapper.vm.dirty).toBe(true)
  await wrapper.get('input[type="checkbox"]').setValue(false)
  expect(wrapper.vm.dirty).toBe(false)
  const foreign = editor({
    project: { ...project, created_by: 2 },
    note: { ...note, evaluations: [{ ...evaluation, created_by: 3 }] },
  })
  expect(foreign.find('input[type="checkbox"]').exists()).toBe(false)
  expect((foreign.get('input[type="number"]').element as HTMLInputElement).disabled).toBe(true)
  await foreign.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.updateEvaluation).not.toHaveBeenCalled()
})

it('keeps deleted task links and inactive or unauthorized notes protected', async () => {
  const deleted = editor({ note: { ...note, tasks: [{ ...task, deleted_at: '2026-10-29' }] } })
  expect(deleted.findComponent({ name: 'ProjectTaskPicker' }).props('disabled')).toBe(true)
  await deleted.get('textarea').setValue('Updated text')
  await deleted.get('form').trigger('submit')
  await flushPromises()
  expect(projectProgressApi.updateNote).toHaveBeenCalledWith(1, 40, {
    title: note.title,
    content: 'Updated text',
    noted_at: note.noted_at,
  })
  vi.mocked(projectProgressApi.updateNote).mockClear()
  const inactive = editor({ project: { ...project, status: 'archived' }, note })
  expect(inactive.vm.saveDisabled).toBe(true)
  await inactive.get('form').trigger('submit')
  const unauthorized = editor({
    project: { ...project, created_by: 2 },
    note: { ...note, created_by: 3 },
  })
  expect(unauthorized.vm.saveDisabled).toBe(true)
  await unauthorized.get('form').trigger('submit')
  expect(projectProgressApi.updateNote).not.toHaveBeenCalled()
})
