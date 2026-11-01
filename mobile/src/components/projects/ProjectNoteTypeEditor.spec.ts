// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ProjectNoteTypeEditor from './ProjectNoteTypeEditor.vue'
import { projectNoteConfigurationApi as api } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProjectNoteType } from '@/types/projectNoteConfiguration'
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: {
    createNoteType: vi.fn(),
    noteTypeFields: vi.fn(),
    updateNoteTypeField: vi.fn(),
    updateNoteType: vi.fn(),
  },
}))
const project = {
  id: 12,
  created_by: 1,
  status: 'active',
  note_template: 'Legacy untouched',
} as Project
const type: ProjectNoteType = {
  id: 7,
  project_id: 12,
  name: 'General',
  description: null,
  template: 'Context:\nObservații:\n',
  is_default: true,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const hosts: ReturnType<typeof mount>[] = []
async function open(props: Partial<InstanceType<typeof ProjectNoteTypeEditor>['$props']> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/projects/:id/edit',
        component: defineComponent({
          setup: () => () => h(ProjectNoteTypeEditor, { project, ...props }),
        }),
      },
      { path: '/other', component: { template: '<p>Other</p>' } },
    ],
  })
  await router.push('/projects/12/edit?tab=settings')
  const host = mount(RouterView, { attachTo: document.body, global: { plugins: [router] } })
  hosts.push(host)
  await flushPromises()
  return { editor: host.findComponent(ProjectNoteTypeEditor), router }
}
function control(name: string) {
  return document.querySelector<HTMLInputElement | HTMLTextAreaElement>(
    `.note-type-editor [data-field="${name}"] input, .note-type-editor [data-field="${name}"] textarea`,
  )!
}
async function set(name: string, value: string) {
  const el = control(name)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
async function submit() {
  document
    .querySelector('.note-type-editor form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await flushPromises()
}
async function close() {
  document.querySelector<HTMLButtonElement>('[aria-label="Închide editorul de tip"]')!.click()
  await flushPromises()
}
async function answer(text: string) {
  Array.from(
    document.querySelectorAll<HTMLButtonElement>(
      'dialog[open] .note-editor-confirm__actions button',
    ),
  )
    .find((b) => b.textContent === text)!
    .click()
  await flushPromises()
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(api.noteTypeFields).mockResolvedValue({ data: [] })
  vi.mocked(api.createNoteType).mockResolvedValue({ data: type })
  vi.mocked(api.updateNoteType).mockResolvedValue({ data: type })
})
afterEach(() => {
  hosts.splice(0).forEach((h) => h.unmount())
})
it('creates metadata with exact multiline template and trusts the returned first default', async () => {
  const { editor } = await open()
  const template = ' Context:\n\nObservații:  \n'
  await set('name', '  General  ')
  await set('description', 'Description')
  await set('template', template)
  await submit()
  expect(api.createNoteType).toHaveBeenCalledExactlyOnceWith(12, {
    name: 'General',
    description: 'Description',
    template,
  })
  expect(editor.emitted('saved')?.[0]).toEqual([type])
  expect(project.note_template).toBe('Legacy untouched')
  await close()
  expect(document.querySelector('.note-editor-confirm[open]')).toBeNull()
})
it('sends nullable optional properties and never sends default or archive status', async () => {
  await open()
  await set('name', 'New')
  await submit()
  expect(api.createNoteType).toHaveBeenCalledExactlyOnceWith(12, {
    name: 'New',
    description: null,
    template: null,
  })
})
it('patches only changed metadata on the original ID, retaining default and relationships', async () => {
  await open({ noteType: type })
  await set('name', 'Changed')
  await submit()
  expect(api.updateNoteType).toHaveBeenCalledExactlyOnceWith(12, 7, { name: 'Changed' })
  expect(api.createNoteType).not.toHaveBeenCalled()
})
it('allows archived metadata editing according to backend rules and clears template with null', async () => {
  await open({ noteType: { ...type, archived_at: '2026-10-30' } })
  await set('template', '')
  await submit()
  expect(api.updateNoteType).toHaveBeenCalledExactlyOnceWith(12, 7, { template: null })
})
it('validates required names and limits before sending', async () => {
  await open()
  await set('name', '   ')
  await submit()
  expect(api.createNoteType).not.toHaveBeenCalled()
  expect(document.body.textContent).toContain('Completează numele')
  await set('name', 'x'.repeat(256))
  await submit()
  expect(document.body.textContent).toContain('255')
  expect(api.createNoteType).not.toHaveBeenCalled()
})
it.each([403, 409, 422])(
  'preserves failed save drafts and backend messages for %s',
  async (status) => {
    vi.mocked(api.updateNoteType).mockRejectedValue(
      new ApiError('Backend conflict', status, { name: ['Duplicate name'] }),
    )
    const { editor } = await open({ noteType: type })
    await set('name', 'Draft')
    await set('template', 'Draft\ntext\n')
    await submit()
    expect(control('name').value).toBe('Draft')
    expect(control('template').value).toBe('Draft\ntext\n')
    expect(document.body.textContent).toContain('Duplicate name')
    expect(editor.emitted('saved')).toBeUndefined()
  },
)
it('retries a rejected validation request without losing the template', async () => {
  vi.mocked(api.createNoteType).mockRejectedValueOnce(
    new ApiError('Invalid', 422, { name: ['Duplicate name'] }),
  )
  await open()
  await set('name', 'Duplicate')
  await set('template', 'Text\n')
  await submit()
  await set('name', 'Unique')
  await submit()
  expect(api.createNoteType).toHaveBeenCalledTimes(2)
  expect(api.createNoteType).toHaveBeenLastCalledWith(12, {
    name: 'Unique',
    description: null,
    template: 'Text\n',
  })
})
it('blocks blind retries when create has an uncertain network result', async () => {
  vi.mocked(api.createNoteType).mockRejectedValue(new ApiError('Network', 0))
  const { editor } = await open()
  await set('name', 'New')
  await submit()
  await submit()
  expect(api.createNoteType).toHaveBeenCalledTimes(1)
  expect(editor.emitted('uncertain')).toHaveLength(1)
  expect(control('name').value).toBe('New')
  expect(document.body.textContent).toContain('nu este confirmată')
})
it('prevents duplicate submission and closing while saving', async () => {
  let resolve!: (value: { data: ProjectNoteType }) => void
  vi.mocked(api.createNoteType).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const { editor } = await open()
  await set('name', 'New')
  await submit()
  await submit()
  await close()
  expect(api.createNoteType).toHaveBeenCalledTimes(1)
  expect(editor.emitted('cancel')).toBeUndefined()
  resolve({ data: type })
  await flushPromises()
  expect(editor.emitted('saved')).toHaveLength(1)
})
it('closes an unchanged form without confirmation', async () => {
  const { editor } = await open({ noteType: type })
  await close()
  expect(editor.emitted('cancel')).toHaveLength(1)
  expect(document.querySelector('.note-editor-confirm[open]')).toBeNull()
})
it('confirms unsaved changes and allows returning to editing', async () => {
  const { editor } = await open()
  await set('name', 'Draft')
  await close()
  expect(document.querySelector('.note-editor-confirm[open]')).not.toBeNull()
  await answer('Continuă editarea')
  expect(editor.emitted('cancel')).toBeUndefined()
  expect(control('name').value).toBe('Draft')
  await close()
  await answer('Renunță la modificări')
  expect(editor.emitted('cancel')).toHaveLength(1)
})
it('guards navigation with the existing confirmation pattern', async () => {
  const { router } = await open()
  await set('name', 'Draft')
  const navigation = router.push('/other')
  await flushPromises()
  await answer('Continuă editarea')
  await navigation
  expect(router.currentRoute.value.query.tab).toBe('settings')
})
it.each([
  { ...project, created_by: 2 },
  { ...project, status: 'archived' as const },
])('rejects direct editor saves without project permission', async (value) => {
  await open({ project: value })
  await set('name', 'New')
  await submit()
  expect(api.createNoteType).not.toHaveBeenCalled()
})

it('shows a save-first path without making association calls for a new Note Type', async () => {
  await open()
  expect(document.body.textContent).toContain('Salvează tipul de notă, apoi')
  expect(api.noteTypeFields).not.toHaveBeenCalled()
})
it('persists an association immediately while preserving unsaved metadata and Settings', async () => {
  const field = {
    id: 3,
    project_id: 12,
    name: 'Energy',
    key: 'energy',
    description: null,
    type: 'number' as const,
    unit: null,
    min: null,
    max: null,
    step: null,
    aggregation: 'average' as const,
    first_value_at: null,
    archived_at: null,
    created_at: null,
    updated_at: null,
  }
  const row = {
    id: 100,
    note_type_id: 7,
    field_id: 3,
    field,
    required: false,
    sort_order: 0,
    activated_at: '2026-10-30',
    deactivated_at: null,
  }
  vi.mocked(api.noteTypeFields).mockResolvedValue({ data: [row] })
  vi.mocked(api.updateNoteTypeField).mockResolvedValue({
    data: { ...row, id: 101, required: true },
  })
  const { router } = await open({ noteType: type })
  await set('name', 'Unsaved title')
  await set('template', 'Unsaved\ntext\n')
  document.querySelector<HTMLButtonElement>('[aria-label="Obligatoriu Energy"]')!.click()
  await flushPromises()
  expect(api.updateNoteTypeField).toHaveBeenCalledExactlyOnceWith(12, 7, 3, { required: true })
  expect(api.updateNoteType).not.toHaveBeenCalled()
  expect(control('name').value).toBe('Unsaved title')
  expect(control('template').value).toBe('Unsaved\ntext\n')
  expect(router.currentRoute.value.query.tab).toBe('settings')
  await close()
  expect(document.querySelector('.note-editor-confirm[open]')?.textContent).toContain(
    'Asocierile deja salvate',
  )
  await answer('Continuă editarea')
  expect(control('name').value).toBe('Unsaved title')
})
