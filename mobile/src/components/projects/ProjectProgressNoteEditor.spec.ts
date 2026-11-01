// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ProjectProgressNoteEditor from './ProjectProgressNoteEditor.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { projectProgressApi } from '@/services/projectProgressApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProgressNote } from '@/types/projectProgress'

vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: { noteTypes: vi.fn(), noteTypeFields: vi.fn(), options: vi.fn() },
}))
vi.mock('@/services/projectProgressApi', () => ({
  projectProgressApi: {
    preview: vi.fn(),
    createNote: vi.fn(),
    updateNote: vi.fn(),
    createEvaluation: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/stores/notifications', () => ({ useNotificationStore: () => ({ error: vi.fn() }) }))
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
const hosts: ReturnType<typeof mount>[] = []
async function open(props: Partial<InstanceType<typeof ProjectProgressNoteEditor>['$props']> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/project/:id',
        component: defineComponent({
          setup: () => () =>
            h(ProjectProgressNoteEditor, { project, initialDate: '2026-10-09', ...props }),
        }),
      },
      { path: '/other', component: { template: '<p>Other page</p>' } },
    ],
  })
  await router.push('/project/1')
  const host = mount(RouterView, {
    attachTo: document.body,
    global: { plugins: [router], stubs: { ProjectTaskPicker: true } },
  })
  hosts.push(host)
  await flushPromises()
  return { host, router, editor: host.findComponent(ProjectProgressNoteEditor) }
}
function button(text: string): HTMLButtonElement {
  return Array.from(document.querySelectorAll<HTMLButtonElement>('dialog[open] button')).find(
    (value) => value.textContent?.trim() === text,
  )!
}
function textarea(): HTMLTextAreaElement {
  return document.querySelector('.note-editor textarea')!
}
async function typeObservation(value = 'Changed') {
  textarea().value = value
  textarea().dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.stubGlobal(
    'fetch',
    vi.fn().mockRejectedValue(new Error('Unexpected network request in mocked tests')),
  )
  vi.mocked(projectNoteConfigurationApi.noteTypes).mockResolvedValue({ data: [] })
  vi.mocked(projectProgressApi.createNote).mockResolvedValue({ data: note })
  vi.mocked(projectProgressApi.updateNote).mockResolvedValue({ data: note })
})
afterEach(() => {
  hosts.splice(0).forEach((host) => host.unmount())
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

it('opens a native Add modal, locks background scroll and closes pristine without a warning', async () => {
  const previous = document.body.style.position
  const { editor, host } = await open()
  expect(document.querySelector('.note-editor[open]')).not.toBeNull()
  expect(document.querySelector('.note-editor h2')?.textContent).toBe('Adaugă notă')
  expect(document.body.style.position).toBe('fixed')
  await editor.vm.requestClose()
  expect(editor.emitted('cancel')).toHaveLength(1)
  expect(document.querySelector('.note-editor-confirm[open]')).toBeNull()
  host.unmount()
  hosts.splice(hosts.indexOf(host), 1)
  expect(document.body.style.position).toBe(previous)
})

it('opens Edit with saved content and does not request a template', async () => {
  await open({ note, project: { ...project, note_template: 'Template' } })
  expect(document.querySelector('.note-editor h2')?.textContent).toBe('Editează nota')
  expect(textarea().value).toBe(note.content)
  expect(projectProgressApi.preview).not.toHaveBeenCalled()
})

it('protects dirty close with Keep Editing and Discard Changes', async () => {
  const { editor } = await open()
  await typeObservation()
  document.querySelector<HTMLButtonElement>('.note-editor-close')!.click()
  await flushPromises()
  expect(document.querySelector('.note-editor-confirm[open]')).not.toBeNull()
  button('Continuă editarea').click()
  await flushPromises()
  expect(editor.emitted('cancel')).toBeUndefined()
  expect(textarea().value).toBe('Changed')
  document.querySelector<HTMLButtonElement>('.note-editor-close')!.click()
  await flushPromises()
  button('Renunță la modificări').click()
  await flushPromises()
  expect(editor.emitted('cancel')).toHaveLength(1)
})

it('routes Escape and backdrop close attempts through the same draft confirmation', async () => {
  const { editor } = await open()
  await typeObservation()
  const dialog = document.querySelector<HTMLDialogElement>('.note-editor')!
  const escape = new Event('cancel', { cancelable: true })
  dialog.dispatchEvent(escape)
  await flushPromises()
  expect(escape.defaultPrevented).toBe(true)
  button('Continuă editarea').click()
  await flushPromises()
  dialog.click()
  await flushPromises()
  expect(document.querySelector('.note-editor-confirm[open]')).not.toBeNull()
  button('Renunță la modificări').click()
  await flushPromises()
  expect(editor.emitted('cancel')).toHaveLength(1)
})

it('guards route leave and route-param changes without discarding text on canceled navigation', async () => {
  const { router } = await open()
  await typeObservation()
  const update = router.push('/project/2')
  await flushPromises()
  button('Continuă editarea').click()
  await update
  expect(router.currentRoute.value.path).toBe('/project/1')
  expect(textarea().value).toBe('Changed')
  const leave = router.push('/other')
  await flushPromises()
  button('Renunță la modificări').click()
  await leave
  expect(router.currentRoute.value.path).toBe('/other')
  expect(document.querySelector('.note-editor')).toBeNull()
})

it('uses one save operation for both toolbar/footer actions and blocks closing while saving', async () => {
  let resolve!: (value: { data: ProgressNote }) => void
  vi.mocked(projectProgressApi.createNote).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const { editor } = await open()
  await typeObservation()
  document.querySelector<HTMLButtonElement>('.note-editor-header-save')!.click()
  document.querySelector<HTMLButtonElement>('.note-editor-footer button')!.click()
  await flushPromises()
  expect(projectProgressApi.createNote).toHaveBeenCalledTimes(1)
  expect(document.querySelector<HTMLButtonElement>('.note-editor-close')!.disabled).toBe(true)
  await editor.vm.requestClose()
  expect(editor.emitted('cancel')).toBeUndefined()
  resolve({ data: note })
  await flushPromises()
  expect(editor.emitted('saved')).toHaveLength(1)
  await editor.vm.requestClose()
  expect(editor.emitted('cancel')).toHaveLength(1)
  expect(document.querySelector('.note-editor-confirm[open]')).toBeNull()
})

it('detects title and date changes and restores pristine state when reverted', async () => {
  const { editor } = await open({ note })
  const title = document.querySelector<HTMLInputElement>('.note-editor input[type="text"]')!
  title.value = 'Edited title'
  title.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  const closing = editor.vm.requestClose()
  await flushPromises()
  button('Continuă editarea').click()
  await closing
  title.value = note.title
  title.dispatchEvent(new Event('input', { bubbles: true }))
  const date = document.querySelector<HTMLInputElement>('.note-editor input[type="date"]')!
  date.value = '2026-10-10'
  date.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  const secondClose = editor.vm.requestClose()
  await flushPromises()
  button('Continuă editarea').click()
  await secondClose
  date.value = note.noted_at
  date.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  await editor.vm.requestClose()
  expect(editor.emitted('cancel')).toHaveLength(1)
})

it('retains partial-save recovery and warns that only remaining evaluations will be discarded', async () => {
  vi.mocked(projectProgressApi.createEvaluation).mockRejectedValue(new ApiError('Conflict', 409))
  const { editor } = await open({ initialTasks: [{ id: 9, title: 'Task', deleted_at: null }] })
  await typeObservation()
  const score = document.querySelector<HTMLInputElement>('.note-editor input[type="number"]')!
  score.value = '8'
  score.dispatchEvent(new Event('input', { bubbles: true }))
  document.querySelector<HTMLButtonElement>('.note-editor-footer button')!.click()
  await flushPromises()
  expect(editor.emitted('saved')).toBeUndefined()
  expect(document.querySelector('.note-editor-footer')?.textContent).toContain(
    'Reîncearcă evaluările',
  )
  const close = editor.vm.requestClose()
  await flushPromises()
  expect(document.querySelector('.note-editor-confirm[open]')?.textContent).toContain(
    'Nota este deja salvată',
  )
  button('Continuă editarea').click()
  await close
  expect(editor.emitted('cancel')).toBeUndefined()
})

it('includes custom field drafts in the existing unsaved-change confirmation and preserves them on cancellation', async () => {
  vi.mocked(projectNoteConfigurationApi.noteTypes).mockResolvedValue({
    data: [
      {
        id: 7,
        project_id: 1,
        name: 'Daily',
        description: null,
        template: 'Type template',
        is_default: true,
        archived_at: null,
        created_at: null,
        updated_at: null,
      },
    ],
  })
  vi.mocked(projectNoteConfigurationApi.noteTypeFields).mockResolvedValue({
    data: [
      {
        id: 100,
        note_type_id: 7,
        field_id: 2,
        required: false,
        sort_order: 0,
        activated_at: '2026-10-31',
        deactivated_at: null,
        field: {
          id: 2,
          project_id: 1,
          key: 'text',
          name: 'Custom text',
          description: null,
          type: 'text',
          unit: null,
          min: null,
          max: null,
          step: null,
          aggregation: null,
          first_value_at: null,
          archived_at: null,
          created_at: null,
          updated_at: null,
        },
      },
    ],
  })
  const { editor } = await open()
  const input = document.querySelector<HTMLTextAreaElement>('[data-custom-field="2"] textarea')!
  input.value = 'Unsaved field\n'
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  const closing = editor.vm.requestClose()
  await flushPromises()
  expect(document.querySelector('.note-editor-confirm[open]')).not.toBeNull()
  button('Continuă editarea').click()
  await closing
  expect(editor.emitted('cancel')).toBeUndefined()
  expect(input.value).toBe('Unsaved field\n')
})
