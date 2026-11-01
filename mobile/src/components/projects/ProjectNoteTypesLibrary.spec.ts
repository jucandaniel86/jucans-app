// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProjectNoteTypesLibrary from './ProjectNoteTypesLibrary.vue'
import { projectNoteConfigurationApi as api } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProjectNoteType } from '@/types/projectNoteConfiguration'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: {
    noteTypes: vi.fn(),
    noteTypeFields: vi.fn(),
    setDefaultNoteType: vi.fn(),
    archiveNoteType: vi.fn(),
    restoreNoteType: vi.fn(),
  },
}))
const project = { id: 12, created_by: 1, status: 'active' } as Project
const general: ProjectNoteType = {
  id: 1,
  project_id: 12,
  name: 'General',
  description: null,
  template: 'Context:\nObservații:\n',
  is_default: true,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const other = { ...general, id: 2, name: 'Tratament', is_default: false }
const archived = { ...other, id: 3, name: 'Consultație', archived_at: '2026-10-30T00:00:00Z' }
const wrappers: ReturnType<typeof mount>[] = []
function render(value = project) {
  const wrapper = mount(ProjectNoteTypesLibrary, {
    props: { project: value },
    attachTo: document.body,
    global: { stubs: { ProjectNoteTypeEditor: true } },
  })
  wrappers.push(wrapper)
  return wrapper
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(api.noteTypes).mockResolvedValue({ data: [general, other, archived] })
})
afterEach(() => {
  wrappers.splice(0).forEach((w) => w.unmount())
})
async function confirm() {
  const button = Array.from(
    document.querySelectorAll<HTMLButtonElement>('dialog[open] button'),
  ).find((b) => b.textContent === 'Arhivează')!
  button.click()
  await flushPromises()
}
it('shows loading without an empty flash and preserves backend ordering including archived types', async () => {
  let resolve!: (value: { data: ProjectNoteType[] }) => void
  vi.mocked(api.noteTypes).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const w = render()
  expect(w.get('[role="status"]').text()).toContain('Se încarcă')
  expect(w.text()).not.toContain('nu are încă')
  resolve({ data: [archived, general, other] })
  await flushPromises()
  expect(w.findAll('.note-type h3').map((h) => h.text())).toEqual([
    'Consultație',
    'General',
    'Tratament',
  ])
  expect(w.get('[aria-label="Număr de tipuri"]').text()).toBe('3')
  expect(w.findAll('.note-type--archived')).toHaveLength(1)
  expect(w.findAll('.note-type__default')).toHaveLength(1)
})
it('leaves an empty project empty without initializing or creating General', async () => {
  vi.mocked(api.noteTypes).mockResolvedValue({ data: [] })
  const w = render()
  await flushPromises()
  expect(w.text()).toContain('nu are încă tipuri')
  expect(api.noteTypes).toHaveBeenCalledExactlyOnceWith(12)
})
it('shows list errors and retries', async () => {
  vi.mocked(api.noteTypes).mockRejectedValueOnce(new ApiError('Unavailable', 500))
  const w = render()
  await flushPromises()
  expect(w.find('[role="alert"]').exists()).toBe(true)
  await w.get('.projects-error button').trigger('click')
  await flushPromises()
  expect(w.findAll('.note-type h3')).toHaveLength(3)
})
it.each([
  { ...project, created_by: 2 },
  { ...project, status: 'archived' as const },
])('allows read-only template access without mutation controls', async (value) => {
  const w = render(value)
  await flushPromises()
  expect(w.find('button').exists()).toBe(false)
  expect(w.find('.note-type__actions').exists()).toBe(false)
  expect(w.get('.note-type__template p').text()).toBe(general.template!.trim())
  expect(w.get('.note-type__template p').element.textContent).toBe(general.template)
})
it('opens shared create/edit modal and closes the action menu', async () => {
  const w = render()
  await flushPromises()
  await w.get('[aria-label="Adaugă tip de notă"]').trigger('click')
  let editor = w.findComponent({ name: 'ProjectNoteTypeEditor' })
  expect(editor.props('noteType')).toBeUndefined()
  editor.vm.$emit('cancel')
  await flushPromises()
  const menu = w.get('.note-type__actions').element as HTMLDetailsElement
  menu.open = true
  await w.get('[aria-label="Editează tipul General"]').trigger('click')
  expect(menu.open).toBe(false)
  editor = w.findComponent({ name: 'ProjectNoteTypeEditor' })
  expect(editor.props('noteType')).toEqual(general)
  editor.vm.$emit('saved', general)
  await flushPromises()
  expect(w.findComponent({ name: 'ProjectNoteTypeEditor' }).exists()).toBe(false)
  expect(api.noteTypes).toHaveBeenCalledTimes(2)
})
it('permits archived metadata editing but only offers Restore as a lifecycle action', async () => {
  const w = render()
  await flushPromises()
  await w.get('[aria-label="Editează tipul Consultație"]').trigger('click')
  expect(w.findComponent({ name: 'ProjectNoteTypeEditor' }).props('noteType')).toEqual(archived)
  expect(w.find('[aria-label="Setează implicit Consultație"]').exists()).toBe(false)
  expect(w.find('[aria-label="Arhivează tipul Consultație"]').exists()).toBe(false)
})
it('sets default using its dedicated endpoint and refreshes authoritative badges', async () => {
  vi.mocked(api.setDefaultNoteType).mockResolvedValue({ data: { ...other, is_default: true } })
  const w = render()
  await flushPromises()
  vi.mocked(api.noteTypes).mockResolvedValue({
    data: [
      { ...general, is_default: false },
      { ...other, is_default: true },
    ],
  })
  await w.get('[aria-label="Setează implicit Tratament"]').trigger('click')
  await flushPromises()
  expect(api.setDefaultNoteType).toHaveBeenCalledExactlyOnceWith(12, 2)
  expect(w.findAll('.note-type__default')).toHaveLength(1)
  expect(w.get('.note-type__default').element.closest('li')?.textContent).toContain('Tratament')
})
it('never offers archive for default or last active types', async () => {
  const w = render()
  await flushPromises()
  expect(w.find('[aria-label="Arhivează tipul General"]').exists()).toBe(false)
  expect(w.find('[aria-label="Setează implicit General"]').exists()).toBe(false)
  vi.mocked(api.noteTypes).mockResolvedValue({ data: [other, archived] })
  await w.setProps({ project: { ...project, id: 13 } })
  await flushPromises()
  expect(w.find('[aria-label="Arhivează tipul Tratament"]').exists()).toBe(false)
})
it('confirms archive, blocks duplicate submissions and refreshes after success', async () => {
  vi.mocked(api.archiveNoteType).mockResolvedValue({
    data: { ...other, archived_at: archived.archived_at },
  })
  const w = render()
  await flushPromises()
  await w.get('[aria-label="Arhivează tipul Tratament"]').trigger('click')
  await flushPromises()
  expect(api.archiveNoteType).not.toHaveBeenCalled()
  expect(w.get('[aria-label="Adaugă tip de notă"]').attributes('disabled')).toBeDefined()
  expect(document.querySelector('dialog[open]')?.textContent).toContain('Configurația și istoricul')
  await confirm()
  expect(api.archiveNoteType).toHaveBeenCalledExactlyOnceWith(12, 2)
  expect(api.noteTypes).toHaveBeenCalledTimes(2)
})
it('canceling archive sends no mutation', async () => {
  const w = render()
  await flushPromises()
  await w.get('[aria-label="Arhivează tipul Tratament"]').trigger('click')
  await flushPromises()
  Array.from(document.querySelectorAll<HTMLButtonElement>('dialog[open] button'))
    .find((b) => b.textContent === 'Anulează')!
    .click()
  await flushPromises()
  expect(api.archiveNoteType).not.toHaveBeenCalled()
})
it('restores the same ID without setting default', async () => {
  vi.mocked(api.restoreNoteType).mockResolvedValue({ data: { ...archived, archived_at: null } })
  const w = render()
  await flushPromises()
  await w.get('[aria-label="Restaurează tipul Consultație"]').trigger('click')
  await flushPromises()
  expect(api.restoreNoteType).toHaveBeenCalledExactlyOnceWith(12, 3)
  expect(api.setDefaultNoteType).not.toHaveBeenCalled()
})
it.each([403, 409, 422, 0])(
  'shows lifecycle failure %s without removing definitions',
  async (status) => {
    vi.mocked(api.setDefaultNoteType).mockRejectedValue(
      new ApiError('Conflict details', status, { name: ['Server validation'] }),
    )
    const w = render()
    await flushPromises()
    await w.get('[aria-label="Setează implicit Tratament"]').trigger('click')
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toContain('Server validation')
    expect(w.findAll('.note-type h3')).toHaveLength(3)
    expect(w.get('.note-type__default').element.closest('li')?.textContent).toContain('General')
  },
)
it('ignores an old list response after changing projects', async () => {
  let resolve!: (value: { data: ProjectNoteType[] }) => void
  vi.mocked(api.noteTypes).mockReturnValueOnce(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const w = render()
  await w.setProps({ project: { ...project, id: 24 } })
  await flushPromises()
  resolve({ data: [] })
  await flushPromises()
  expect(w.findAll('.note-type h3')).toHaveLength(3)
  expect(api.noteTypes).toHaveBeenLastCalledWith(24)
})

it('allows viewers to inspect associations lazily without mutation controls', async () => {
  vi.mocked(api.noteTypeFields).mockResolvedValue({ data: [] })
  const w = render({ ...project, created_by: 2 })
  await flushPromises()
  expect(api.noteTypeFields).not.toHaveBeenCalled()
  const details = w.get('[aria-label="Vezi câmpurile General"]').element
    .parentElement as HTMLDetailsElement
  details.open = true
  details.dispatchEvent(new Event('toggle'))
  await flushPromises()
  expect(api.noteTypeFields).toHaveBeenCalledExactlyOnceWith(12, 1)
  expect(w.text()).toContain('Niciun câmp asociat')
  expect(w.find('button').exists()).toBe(false)
})
