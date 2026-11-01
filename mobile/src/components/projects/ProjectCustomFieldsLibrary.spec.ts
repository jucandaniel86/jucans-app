// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProjectCustomFieldsLibrary from './ProjectCustomFieldsLibrary.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProjectCustomField } from '@/types/projectNoteConfiguration'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))

vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: {
    fields: vi.fn(),
    options: vi.fn(),
    archiveField: vi.fn(),
    restoreField: vi.fn(),
  },
}))
const field: ProjectCustomField = {
  id: 7,
  project_id: 12,
  name: 'Energie',
  key: 'energy',
  description: 'Observație zilnică',
  type: 'number',
  unit: 'puncte',
  min: '0.000000',
  max: '10.000000',
  step: '0.500000',
  aggregation: 'average',
  first_value_at: null,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const wrappers: ReturnType<typeof mount>[] = []
function render(project?: Project) {
  const wrapper = mount(ProjectCustomFieldsLibrary, {
    props: { projectId: 12, project },
    global: { stubs: { ProjectCustomFieldEditor: true } },
  })
  wrappers.push(wrapper)
  return wrapper
}
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((answer) => {
    resolve = answer
  })
  return { promise, resolve }
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({ data: [] })
})
afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
})

it('shows a loading state without flashing empty content', async () => {
  const request = deferred<{ data: ProjectCustomField[] }>()
  vi.mocked(projectNoteConfigurationApi.fields).mockReturnValue(request.promise)
  const wrapper = render()
  expect(wrapper.get('[role="status"]').text()).toContain('Se încarcă')
  expect(wrapper.get('section').attributes('aria-busy')).toBe('true')
  expect(wrapper.text()).not.toContain('nu are încă')
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledExactlyOnceWith(12)
  request.resolve({ data: [field] })
  await flushPromises()
  expect(wrapper.find('[role="status"]').exists()).toBe(false)
  expect(wrapper.get('section').attributes('aria-busy')).toBe('false')
})

it('shows the empty library without placeholder mutation controls', async () => {
  const wrapper = render()
  await flushPromises()
  expect(wrapper.text()).toContain('Proiectul nu are încă câmpuri personalizate.')
  expect(wrapper.find('ul').exists()).toBe(false)
  expect(wrapper.find('button').exists()).toBe(false)
})

it('shows an API error and retries using the existing client', async () => {
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockRejectedValueOnce(new ApiError('Service unavailable', 503))
    .mockResolvedValueOnce({ data: [field] })
  const wrapper = render()
  await flushPromises()
  expect(wrapper.get('[role="alert"]').text()).toContain(
    'Nu am putut încărca câmpurile personalizate.',
  )
  await wrapper.get('button').trigger('click')
  await flushPromises()
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(2)
  expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('Energie')
})

it('renders all four field types, numeric unit/aggregation and optional descriptions', async () => {
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({
    data: [
      field,
      {
        ...field,
        id: 8,
        name: 'Dispoziție',
        type: 'select',
        unit: null,
        aggregation: null,
        description: null,
      },
      {
        ...field,
        id: 9,
        name: 'Odihnit',
        type: 'boolean',
        unit: null,
        aggregation: null,
        description: '',
      },
      {
        ...field,
        id: 10,
        name: 'Comentariu',
        type: 'text',
        unit: null,
        aggregation: null,
        description: 'Text liber',
      },
    ],
  })
  const wrapper = render()
  await flushPromises()
  expect(wrapper.findAll('li')).toHaveLength(4)
  for (const label of [
    'Număr',
    'Select',
    'Da / Nu',
    'Text',
    'puncte',
    'Medie',
    'Observație zilnică',
    'Text liber',
  ])
    expect(wrapper.text()).toContain(label)
  expect(wrapper.findAll('.custom-field__description')).toHaveLength(2)
  expect(wrapper.findAll('dt').filter((item) => item.text() === 'Agregare')).toHaveLength(1)
  expect(wrapper.find('.custom-field__edit').exists()).toBe(false)
  expect(wrapper.find('[aria-label="Adaugă câmp"]').exists()).toBe(false)
})

it('retains archived definitions with a readable status and distinct styling', async () => {
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({
    data: [
      field,
      { ...field, id: 8, name: 'Scor istoric', archived_at: '2026-10-30T12:00:00.123456Z' },
    ],
  })
  const wrapper = render()
  await flushPromises()
  expect(wrapper.findAll('li')).toHaveLength(2)
  expect(wrapper.get('.custom-field--archived').text()).toContain('Scor istoric')
  expect(wrapper.get('.custom-field--archived').text()).toContain('Arhivat')
  expect(wrapper.findAll('.custom-field__archived')).toHaveLength(1)
  expect(wrapper.get('.custom-fields-library__count').text()).toBe('2')
})

it('ignores stale responses when switching projects and clears old definitions immediately', async () => {
  const first = deferred<{ data: ProjectCustomField[] }>()
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockReturnValueOnce(first.promise)
    .mockResolvedValueOnce({ data: [{ ...field, id: 20, project_id: 24, name: 'Alt proiect' }] })
  const wrapper = render()
  await wrapper.setProps({ projectId: 24 })
  await flushPromises()
  first.resolve({ data: [field] })
  await flushPromises()
  expect(projectNoteConfigurationApi.fields).toHaveBeenLastCalledWith(24)
  expect(wrapper.text()).toContain('Alt proiect')
  expect(wrapper.text()).not.toContain('Energie')
})

it('does not retain definitions after a subsequent access-denied response', async () => {
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockResolvedValueOnce({ data: [field] })
    .mockRejectedValueOnce(new ApiError('Forbidden', 403))
  const wrapper = render()
  await flushPromises()
  expect(wrapper.text()).toContain('Energie')
  await wrapper.setProps({ projectId: 24 })
  await flushPromises()
  expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  expect(wrapper.find('li').exists()).toBe(false)
  expect(wrapper.text()).not.toContain('Energie')
})

const ownerProject: Project = {
  id: 12,
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
it('shows compact create/edit controls for all four field types', async () => {
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({
    data: [
      field,
      { ...field, id: 8, type: 'text', name: 'Text' },
      { ...field, id: 9, type: 'boolean', name: 'Boolean' },
      { ...field, id: 10, type: 'select', name: 'Select' },
    ],
  })
  const wrapper = render(ownerProject)
  await flushPromises()
  expect(wrapper.find('[aria-label="Adaugă câmp"]').exists()).toBe(true)
  expect(wrapper.findAll('.custom-field__edit')).toHaveLength(4)
  expect(wrapper.findAll('li')).toHaveLength(4)
  expect(wrapper.find('[aria-label="Editează câmpul Select"]').exists()).toBe(true)
  const menu = wrapper.get('details').element as HTMLDetailsElement
  menu.open = true
  await wrapper.get('[aria-label="Editează câmpul Energie"]').trigger('click')
  expect(menu.open).toBe(false)
  const editor = wrapper.findComponent({ name: 'ProjectCustomFieldEditor' })
  expect(editor.props('field')).toEqual(field)
  editor.vm.$emit('cancel')
  await flushPromises()
  expect(wrapper.findComponent({ name: 'ProjectCustomFieldEditor' }).exists()).toBe(false)
})
it.each([
  { ...ownerProject, created_by: 2 },
  { ...ownerProject, status: 'archived' as const },
  { ...ownerProject, id: 24 },
])(
  'keeps viewers and inactive/mismatched projects read-only: $created_by / $status / $id',
  async (project) => {
    vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({ data: [field] })
    const wrapper = render(project)
    await flushPromises()
    expect(wrapper.find('button').exists()).toBe(false)
  },
)
it('refreshes the library after success without changing its ordering or reloading the page', async () => {
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockResolvedValueOnce({ data: [] })
    .mockResolvedValueOnce({ data: [field] })
  const wrapper = render(ownerProject)
  await flushPromises()
  await wrapper.get('[aria-label="Adaugă câmp"]').trigger('click')
  const editor = wrapper.findComponent({ name: 'ProjectCustomFieldEditor' })
  expect(editor.props('project')).toEqual(ownerProject)
  expect(editor.props('field')).toBeUndefined()
  editor.vm.$emit('saved', field)
  await flushPromises()
  expect(wrapper.findComponent({ name: 'ProjectCustomFieldEditor' }).exists()).toBe(false)
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(2)
  expect(wrapper.findAll('li')).toHaveLength(1)
  expect(wrapper.get('li').text()).toContain('Energie')
})

function confirmationButton(wrapper: ReturnType<typeof render>, label: string) {
  return wrapper
    .get('dialog[open]')
    .findAll('button')
    .find((button) => button.text() === label)!
}
it('confirms field archive, preserves history messaging and refreshes the same field ID', async () => {
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockResolvedValueOnce({ data: [field] })
    .mockResolvedValueOnce({ data: [{ ...field, archived_at: '2026-10-30T12:00:00Z' }] })
  vi.mocked(projectNoteConfigurationApi.archiveField).mockResolvedValue({
    data: { ...field, archived_at: '2026-10-30T12:00:00Z' },
  })
  const wrapper = render(ownerProject)
  await flushPromises()
  await wrapper.get('[aria-label="Arhivează câmpul Energie"]').trigger('click')
  await flushPromises()
  expect(wrapper.get('dialog[open]').text()).toContain('Definiția și istoricul se păstrează')
  expect(projectNoteConfigurationApi.archiveField).not.toHaveBeenCalled()
  await confirmationButton(wrapper, 'Arhivează').trigger('click')
  await flushPromises()
  expect(projectNoteConfigurationApi.archiveField).toHaveBeenCalledExactlyOnceWith(12, 7)
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(2)
  expect(wrapper.get('.custom-field--archived').text()).toContain('Arhivat')
})
it('restores an archived field with the same ID without creating a replacement', async () => {
  const archived = { ...field, archived_at: '2026-10-30T12:00:00Z' }
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockResolvedValueOnce({ data: [archived] })
    .mockResolvedValueOnce({ data: [field] })
  vi.mocked(projectNoteConfigurationApi.restoreField).mockResolvedValue({ data: field })
  const wrapper = render(ownerProject)
  await flushPromises()
  await wrapper.get('[aria-label="Restaurează câmpul Energie"]').trigger('click')
  await flushPromises()
  expect(projectNoteConfigurationApi.restoreField).toHaveBeenCalledExactlyOnceWith(12, 7)
  expect(wrapper.find('.custom-field--archived').exists()).toBe(false)
  expect(wrapper.get('li h3').text()).toBe(field.name)
})
it.each([403, 409, 422])('preserves the library after lifecycle status %i', async (status) => {
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({
    data: [{ ...field, archived_at: '2026-10-30T12:00:00Z' }],
  })
  vi.mocked(projectNoteConfigurationApi.restoreField).mockRejectedValue(
    new ApiError('Rejected', status, status === 422 ? { state: ['Keep definition'] } : {}),
  )
  const wrapper = render(ownerProject)
  await flushPromises()
  await wrapper.get('[aria-label="Restaurează câmpul Energie"]').trigger('click')
  await flushPromises()
  expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  expect(wrapper.find('.custom-field--archived').exists()).toBe(true)
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(1)
})

it('allows viewers to inspect Select options including archived labels without mutation controls', async () => {
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({
    data: [{ ...field, type: 'select' }],
  })
  vi.mocked(projectNoteConfigurationApi.options).mockResolvedValue({
    data: [
      {
        id: 19,
        field_id: 7,
        label: 'Historical',
        sort_order: 0,
        archived_at: '2026-10-30T12:00:00Z',
        created_at: null,
        updated_at: null,
      },
    ],
  })
  const wrapper = render({ ...ownerProject, created_by: 2 })
  await flushPromises()
  await wrapper.get('[aria-label="Vezi opțiunile câmpului Energie"]').trigger('click')
  await flushPromises()
  expect(wrapper.get('.select-options').text()).toContain('Historical')
  expect(wrapper.get('.select-options').text()).toContain('Arhivată')
  expect(wrapper.get('.select-options').find('input').exists()).toBe(false)
  expect(wrapper.get('.select-options').find('button').exists()).toBe(false)
  expect(wrapper.find('.custom-field__edit').exists()).toBe(false)
  expect(wrapper.find('[aria-label="Arhivează câmpul Energie"]').exists()).toBe(false)
})
