// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ProjectCustomFieldEditor from './ProjectCustomFieldEditor.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type {
  ProjectCustomField,
  ProjectCustomFieldOption,
  CreateProjectCustomFieldOptionPayload,
} from '@/types/projectNoteConfiguration'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: {
    createField: vi.fn(),
    updateField: vi.fn(),
    options: vi.fn(),
    createOption: vi.fn(),
    updateOption: vi.fn(),
    archiveOption: vi.fn(),
    restoreOption: vi.fn(),
    reorderOptions: vi.fn(),
  },
}))
const project: Project = {
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
const field: ProjectCustomField = {
  id: 7,
  project_id: 12,
  name: 'Energy',
  key: 'energy',
  description: null,
  type: 'number',
  unit: 'points',
  min: '-999999999999.123456',
  max: '999999999999.123456',
  step: '0.000001',
  aggregation: 'average',
  first_value_at: null,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const hosts: ReturnType<typeof mount>[] = []
async function open(props: Partial<InstanceType<typeof ProjectCustomFieldEditor>['$props']> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/projects/:id/edit',
        name: 'project-edit',
        component: defineComponent({
          setup: () => () => h(ProjectCustomFieldEditor, { project, ...props }),
        }),
      },
      { path: '/other', component: { template: '<p>Other</p>' } },
    ],
  })
  await router.push('/projects/12/edit?tab=settings')
  const host = mount(RouterView, { attachTo: document.body, global: { plugins: [router] } })
  hosts.push(host)
  await flushPromises()
  return { host, router, editor: host.findComponent(ProjectCustomFieldEditor) }
}
function input(name: string): HTMLInputElement {
  return document.querySelector(`.field-editor [data-field="${name}"] input`)!
}
async function set(name: string, value: string) {
  const target = input(name)
  target.value = value
  target.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
async function choose(type: string) {
  const radio = document.querySelector<HTMLInputElement>(
    `.field-editor input[type="radio"][value="${type}"]`,
  )!
  radio.checked = true
  radio.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}
async function submit() {
  document
    .querySelector<HTMLFormElement>('.field-editor form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await flushPromises()
}
function button(label: string): HTMLButtonElement {
  return Array.from(document.querySelectorAll<HTMLButtonElement>('dialog[open] button')).find(
    (value) => value.textContent?.trim() === label,
  )!
}
async function close() {
  document.querySelector<HTMLButtonElement>('.field-editor__close')!.click()
  await flushPromises()
}
let storedOptions: ProjectCustomFieldOption[] = []
let optionId = 100
async function persistOption(
  _project: number,
  fieldId: number,
  payload: CreateProjectCustomFieldOptionPayload,
) {
  const option: ProjectCustomFieldOption = {
    id: optionId++,
    field_id: fieldId,
    label: payload.label,
    sort_order: payload.sort_order ?? storedOptions.length,
    archived_at: null,
    created_at: null,
    updated_at: null,
  }
  storedOptions.push(option)
  return { data: { ...option } }
}
beforeEach(() => {
  vi.resetAllMocks()
  storedOptions = []
  optionId = 100
  vi.mocked(projectNoteConfigurationApi.options).mockImplementation(async () => ({
    data: storedOptions.map((option) => ({ ...option })),
  }))
  vi.mocked(projectNoteConfigurationApi.createOption).mockImplementation(persistOption)
  vi.mocked(projectNoteConfigurationApi.updateOption).mockImplementation(
    async (_project, _field, id, payload) => {
      const option = storedOptions.find((option) => option.id === id)!
      Object.assign(option, payload)
      return { data: { ...option } }
    },
  )
  vi.mocked(projectNoteConfigurationApi.archiveOption).mockImplementation(
    async (_project, _field, id) => {
      const option = storedOptions.find((option) => option.id === id)!
      option.archived_at = '2026-10-30T12:00:00.123456Z'
      return { data: { ...option } }
    },
  )
  vi.mocked(projectNoteConfigurationApi.restoreOption).mockImplementation(
    async (_project, _field, id) => {
      const option = storedOptions.find((option) => option.id === id)!
      option.archived_at = null
      return { data: { ...option } }
    },
  )
  vi.mocked(projectNoteConfigurationApi.reorderOptions).mockImplementation(
    async (_project, _field, payload) => {
      storedOptions = payload.ids.map((id, index) => ({
        ...storedOptions.find((option) => option.id === id)!,
        sort_order: index,
      }))
      return { data: storedOptions }
    },
  )
  vi.mocked(projectNoteConfigurationApi.createField).mockResolvedValue({ data: field })
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: field })
})
afterEach(() => {
  hosts.splice(0).forEach((host) => host.unmount())
  document.body.innerHTML = ''
})

it('creates a Number with string decimals and default aggregation', async () => {
  const { editor } = await open()
  await set('name', 'Attention Level')
  await set('unit', 'points')
  await set('min', '-999999999999.123456')
  await set('max', '999999999999.123456')
  await set('step', '0.000001')
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledExactlyOnceWith(12, {
    name: 'Attention Level',
    key: 'attention_level',
    description: null,
    type: 'number',
    unit: 'points',
    min: '-999999999999.123456',
    max: '999999999999.123456',
    step: '0.000001',
    aggregation: 'average',
  })
  expect(editor.emitted('saved')?.[0]).toEqual([field])
})

it.each(['text', 'boolean'])('creates %s without numeric configuration', async (type) => {
  await open()
  await set('name', 'Comment')
  await set('min', '2')
  await choose(type)
  expect(document.querySelector('[data-field="unit"]')).toBeNull()
  expect(document.querySelector('[data-field="min"]')).toBeNull()
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledWith(12, {
    name: 'Comment',
    key: 'comment',
    type,
    description: null,
  })
})

it('generates normalized identifiers but never overwrites a manually edited key', async () => {
  await open()
  await set('name', 'Atenție și energie')
  expect(input('key').value).toBe('atentie_si_energie')
  await set('name', '123 Duration')
  expect(input('key').value).toBe('field_123_duration')
  await set('key', 'custom_id')
  await set('name', 'Different name')
  expect(input('key').value).toBe('custom_id')
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledWith(
    12,
    expect.objectContaining({ key: 'custom_id' }),
  )
})

it('validates the raw manually entered key instead of altering it on submit', async () => {
  await open()
  await set('name', 'Name')
  await set('key', ' My_Key ')
  await submit()
  expect(input('key').value).toBe(' My_Key ')
  expect(document.querySelector('[data-field="key"]')?.textContent).toContain(
    'începând cu o literă',
  )
  expect(projectNoteConfigurationApi.createField).not.toHaveBeenCalled()
})

it('offers all four types and keeps advanced Number settings collapsed until needed', async () => {
  await open()
  expect(
    Array.from(
      document.querySelectorAll<HTMLInputElement>('.field-editor input[type="radio"]'),
    ).map((value) => value.value),
  ).toEqual(['number', 'text', 'boolean', 'select'])
  const advanced = document.querySelector<HTMLDetailsElement>('.field-editor__advanced')!
  expect(advanced.open).toBe(false)
  advanced.open = true
  advanced.dispatchEvent(new Event('toggle'))
  await set('min', '0.500000')
  await choose('text')
  await choose('number')
  expect(input('min').value).toBe('0.500000')
  const select = document.querySelector<HTMLSelectElement>('[data-field="aggregation"] select')!
  expect(Array.from(select.options).map((value) => value.value)).toEqual([
    'average',
    'sum',
    'min',
    'max',
    'latest',
  ])
})

it('loads Edit values exactly and omits immutable key/project/archive properties from PATCH', async () => {
  const archived = { ...field, archived_at: '2026-10-30T12:00:00.123456Z' }
  await open({ field: archived })
  expect(input('key').readOnly).toBe(true)
  expect(input('key').value).toBe('energy')
  expect(input('min').value).toBe(field.min)
  expect(input('step').value).toBe('0.000001')
  expect(document.querySelector<HTMLDetailsElement>('.field-editor__advanced')!.open).toBe(true)
  expect(document.querySelector('.field-editor__hint')?.textContent).toContain('arhivat')
  await set('name', 'Energy updated')
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledExactlyOnceWith(12, 7, {
    name: 'Energy updated',
  })
})

it('explicitly clears incompatible persisted settings when changing Number to Text', async () => {
  await open({ field })
  await choose('text')
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledWith(12, 7, {
    type: 'text',
    unit: null,
    min: null,
    max: null,
    step: null,
    aggregation: null,
  })
})

it.each([
  { values: { min: '2', max: '1' }, target: 'max', message: 'Maximul' },
  { values: { step: '0' }, target: 'step', message: 'mai mare decât zero' },
  { values: { step: '-0.000001' }, target: 'step', message: 'mai mare decât zero' },
  { values: { min: '0.0000001' }, target: 'min', message: '6 zecimale' },
  { values: { max: '1000000000000' }, target: 'max', message: '12 cifre' },
  { values: { min: '1,5' }, target: 'min', message: 'separatorul' },
])('validates numeric configuration: $values', async ({ values, target, message }) => {
  await open()
  await set('name', 'Number')
  for (const [name, value] of Object.entries(values)) await set(name, value)
  await submit()
  expect(document.querySelector(`[data-field="${target}"]`)?.textContent).toContain(message)
  expect(document.querySelector<HTMLDetailsElement>('.field-editor__advanced')!.open).toBe(true)
  expect(projectNoteConfigurationApi.createField).not.toHaveBeenCalled()
})

it('shows duplicate key errors beside the input and preserves entered values after failure', async () => {
  vi.mocked(projectNoteConfigurationApi.createField).mockRejectedValue(
    new ApiError('The key has already been taken.', 422, {
      key: ['The key has already been taken.'],
    }),
  )
  const { editor } = await open()
  await set('name', 'Attention')
  await set('description', 'Keep this text')
  await submit()
  expect(document.querySelector('[data-field="key"]')?.textContent).toContain('already been taken')
  expect(input('name').value).toBe('Attention')
  expect(input('description').value).toBe('Keep this text')
  expect(document.activeElement).toBe(input('key'))
  expect(editor.emitted('saved')).toBeUndefined()
})

it.each(['type', 'unit'])(
  'preserves backend immutability validation for %s after the first value',
  async (property) => {
    vi.mocked(projectNoteConfigurationApi.updateField).mockRejectedValue(
      new ApiError('Validation failed', 422, {
        [property]: ['Cannot change after the first value.'],
      }),
    )
    const { editor } = await open({ field })
    if (property === 'type') await choose('text')
    else await set('unit', 'hours')
    await submit()
    expect(document.querySelector(`[data-field="${property}"]`)?.textContent).toContain(
      'Cannot change after the first value.',
    )
    expect(editor.emitted('saved')).toBeUndefined()
    if (property === 'unit') expect(input('unit').value).toBe('hours')
    expect(field.unit).toBe('points')
    expect(field.type).toBe('number')
  },
)

it.each([403, 409, 0, 500])(
  'handles status %i without losing the draft or reporting success',
  async (status) => {
    vi.mocked(projectNoteConfigurationApi.createField).mockRejectedValue(
      new ApiError('Server detail', status),
    )
    const { editor } = await open()
    await set('name', 'Draft')
    await submit()
    expect(document.querySelector('.field-editor__error')?.textContent).toContain(
      status === 403
        ? 'permisiunea'
        : status === 0 || status >= 500
          ? 'nu este confirmată'
          : 'Server detail',
    )
    expect(input('name').value).toBe('Draft')
    expect(editor.emitted('saved')).toBeUndefined()
    if (status === 403)
      expect(
        document.querySelector<HTMLButtonElement>('.field-editor__footer button')!.disabled,
      ).toBe(true)
  },
)

it('prevents duplicate submissions and closing during save, without warning after success', async () => {
  let resolve!: (value: { data: ProjectCustomField }) => void
  vi.mocked(projectNoteConfigurationApi.createField).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const { editor } = await open()
  await set('name', 'Field')
  await submit()
  await submit()
  await close()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledTimes(1)
  expect(editor.emitted('cancel')).toBeUndefined()
  resolve({ data: field })
  await flushPromises()
  await close()
  expect(editor.emitted('cancel')).toHaveLength(1)
  expect(document.querySelector('.note-editor-confirm[open]')).toBeNull()
})

it('closes pristine forms directly and confirms dirty Escape/backdrop/navigation attempts', async () => {
  const pristine = await open()
  await close()
  expect(pristine.editor.emitted('cancel')).toHaveLength(1)
  pristine.host.unmount()
  hosts.splice(hosts.indexOf(pristine.host), 1)
  const { editor, router } = await open()
  await set('name', 'Unsaved')
  document
    .querySelector('dialog.field-editor')!
    .dispatchEvent(new Event('cancel', { cancelable: true }))
  await flushPromises()
  expect(document.querySelector('.note-editor-confirm[open]')).not.toBeNull()
  button('Continuă editarea').click()
  await flushPromises()
  expect(input('name').value).toBe('Unsaved')
  document.querySelector<HTMLDialogElement>('.field-editor')!.click()
  await flushPromises()
  button('Continuă editarea').click()
  await flushPromises()
  const navigation = router.push('/projects/12/edit?tab=general')
  await flushPromises()
  button('Continuă editarea').click()
  await navigation
  expect(router.currentRoute.value.query.tab).toBe('settings')
  const leaving = router.push('/other')
  await flushPromises()
  button('Renunță la modificări').click()
  await leaving
  expect(router.currentRoute.value.path).toBe('/other')
  expect(editor.emitted('saved')).toBeUndefined()
})

it('validates required shared properties before creating anything', async () => {
  await open()
  await submit()
  expect(document.querySelector('[data-field="name"]')?.textContent).toContain('Completează numele')
  expect(document.querySelector('[data-field="key"]')?.textContent).toContain(
    'începând cu o literă',
  )
  expect(projectNoteConfigurationApi.createField).not.toHaveBeenCalled()
})

const selectField: ProjectCustomField = {
  ...field,
  type: 'select',
  unit: null,
  min: null,
  max: null,
  step: null,
  aggregation: null,
}
function option(id: number, label: string, archived = false): ProjectCustomFieldOption {
  return {
    id,
    field_id: 7,
    label,
    sort_order: id,
    archived_at: archived ? '2026-10-30T12:00:00.123456Z' : null,
    created_at: null,
    updated_at: null,
  }
}
function optionInputs(): HTMLInputElement[] {
  return Array.from(document.querySelectorAll('.select-option input'))
}
async function optionLabel(index: number, label: string) {
  const input = optionInputs()[index]!
  input.value = label
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
async function addOption(label: string) {
  button('+ Opțiune').click()
  await flushPromises()
  await optionLabel(optionInputs().length - 1, label)
}
async function optionAction(label: string) {
  document.querySelector<HTMLButtonElement>(`.select-options [aria-label="${label}"]`)!.click()
  await flushPromises()
}

it('creates a Select field and multiple options sequentially without embedding options in the field payload', async () => {
  vi.mocked(projectNoteConfigurationApi.createField).mockResolvedValue({ data: selectField })
  const { editor } = await open()
  await set('name', 'Symptoms')
  await choose('select')
  await addOption('None')
  await addOption('Mild')
  await optionAction('Mută opțiunea 2 în sus')
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledExactlyOnceWith(12, {
    name: 'Symptoms',
    key: 'symptoms',
    type: 'select',
    description: null,
  })
  expect(projectNoteConfigurationApi.createOption).toHaveBeenNthCalledWith(1, 12, 7, {
    label: 'Mild',
    sort_order: 0,
  })
  expect(projectNoteConfigurationApi.createOption).toHaveBeenNthCalledWith(2, 12, 7, {
    label: 'None',
    sort_order: 1,
  })
  expect(projectNoteConfigurationApi.reorderOptions).toHaveBeenCalledWith(12, 7, {
    ids: [100, 101],
  })
  expect(editor.emitted('saved')).toHaveLength(1)
})

it('retains the field and successful option IDs across partial failure and retries only remaining creates', async () => {
  vi.mocked(projectNoteConfigurationApi.createField).mockResolvedValue({ data: selectField })
  vi.mocked(projectNoteConfigurationApi.createOption)
    .mockImplementationOnce(persistOption)
    .mockRejectedValueOnce(new ApiError('Invalid label', 422, { label: ['Correct this label'] }))
  const { editor } = await open()
  await set('name', 'Symptoms')
  await choose('select')
  await addOption('None')
  await addOption('Mild')
  await submit()
  expect(editor.emitted('saved')).toBeUndefined()
  expect(editor.emitted('changed')).toHaveLength(1)
  expect(document.querySelector('.field-editor__error')?.textContent).toContain(
    'Câmpul #7 este salvat',
  )
  expect(document.querySelector('.select-options')?.textContent).toContain('Correct this label')
  await optionLabel(1, 'Moderate')
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledTimes(1)
  expect(projectNoteConfigurationApi.createOption).toHaveBeenCalledTimes(3)
  expect(projectNoteConfigurationApi.createOption).toHaveBeenLastCalledWith(12, 7, {
    label: 'Moderate',
    sort_order: 1,
  })
  expect(storedOptions.map((row) => row.id)).toEqual([100, 101])
  expect(editor.emitted('saved')).toHaveLength(1)
})

it('does not blindly retry an uncertain option create, and allows explicit list reconciliation', async () => {
  vi.mocked(projectNoteConfigurationApi.createField).mockResolvedValue({ data: selectField })
  vi.mocked(projectNoteConfigurationApi.createOption).mockImplementationOnce(async (...args) => {
    await persistOption(...args)
    throw new ApiError('Offline', 0)
  })
  const { editor } = await open()
  await set('name', 'Symptoms')
  await choose('select')
  await addOption('None')
  await submit()
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledTimes(1)
  expect(projectNoteConfigurationApi.createOption).toHaveBeenCalledTimes(1)
  expect(editor.emitted('saved')).toBeUndefined()
  expect(document.querySelector('.select-options')?.textContent).toContain('nu retrimitem automat')
  button('Reîncarcă opțiunile').click()
  await flushPromises()
  button('Reîncarcă').click()
  await flushPromises()
  expect(optionInputs()[0]!.value).toBe('None')
  await submit()
  expect(projectNoteConfigurationApi.createField).toHaveBeenCalledTimes(1)
  expect(projectNoteConfigurationApi.createOption).toHaveBeenCalledTimes(1)
  expect(editor.emitted('saved')).toHaveLength(1)
})

it('validates nonempty option labels before creating a field and allows removing unsaved options', async () => {
  await open()
  await set('name', 'Symptoms')
  await choose('select')
  await submit()
  expect(document.querySelector('.select-options')?.textContent).toContain('cel puțin o opțiune')
  await addOption('   ')
  await submit()
  expect(document.querySelector('.select-options')?.textContent).toContain('Completează eticheta')
  expect(projectNoteConfigurationApi.createField).not.toHaveBeenCalled()
  await optionAction('Elimină opțiunea 1 nesalvată')
  expect(optionInputs()).toHaveLength(0)
})

it('edits Select metadata and renames options while preserving their IDs', async () => {
  storedOptions = [option(17, 'Yes'), option(18, 'No', true)]
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: selectField })
  const { editor } = await open({ field: selectField })
  await set('name', 'Updated Select')
  await optionLabel(0, 'Present')
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledWith(12, 7, {
    name: 'Updated Select',
  })
  expect(projectNoteConfigurationApi.updateOption).toHaveBeenCalledExactlyOnceWith(12, 7, 17, {
    label: 'Present',
  })
  expect(projectNoteConfigurationApi.createOption).not.toHaveBeenCalled()
  expect(storedOptions.map((row) => row.id)).toEqual([17, 18])
  expect(projectNoteConfigurationApi.options).toHaveBeenCalledTimes(2)
  expect(editor.emitted('saved')).toHaveLength(1)
})

it('adds to an existing Select and reorders the complete list including archived IDs', async () => {
  storedOptions = [option(17, 'Yes'), option(18, 'No', true)]
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: selectField })
  await open({ field: selectField })
  expect(document.querySelector('.select-options')?.textContent).toContain('Arhivată')
  await optionAction('Mută opțiunea 2 în sus')
  await addOption('Maybe')
  await submit()
  expect(projectNoteConfigurationApi.createOption).toHaveBeenCalledExactlyOnceWith(12, 7, {
    label: 'Maybe',
    sort_order: 2,
  })
  expect(projectNoteConfigurationApi.reorderOptions).toHaveBeenCalledExactlyOnceWith(12, 7, {
    ids: [18, 17, 100],
  })
})

it('stages archive confirmation and restores archived options using their original IDs', async () => {
  storedOptions = [option(17, 'Yes'), option(18, 'No', true)]
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: selectField })
  await open({ field: selectField })
  await optionAction('Arhivează opțiunea 1')
  expect(projectNoteConfigurationApi.archiveOption).not.toHaveBeenCalled()
  button('Arhivează la salvare').click()
  await flushPromises()
  await optionAction('Restaurează opțiunea 2')
  await submit()
  expect(projectNoteConfigurationApi.archiveOption).toHaveBeenCalledExactlyOnceWith(12, 7, 17)
  expect(projectNoteConfigurationApi.restoreOption).toHaveBeenCalledExactlyOnceWith(12, 7, 18)
  expect(storedOptions[0]!.archived_at).not.toBeNull()
  expect(storedOptions[1]!.archived_at).toBeNull()
})

it('prevents modifying options of an archived field while permitting supported metadata updates', async () => {
  storedOptions = [option(17, 'Yes')]
  const archived = { ...selectField, archived_at: '2026-10-30T12:00:00.123456Z' }
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: archived })
  await open({ field: archived })
  expect(document.querySelector('.select-options')?.textContent).toContain('Restaurează câmpul')
  expect(document.querySelector<HTMLFieldSetElement>('.select-options__list')!.disabled).toBe(true)
  await set('name', 'Renamed archived Select')
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledWith(12, 7, {
    name: 'Renamed archived Select',
  })
  expect(projectNoteConfigurationApi.updateOption).not.toHaveBeenCalled()
  expect(projectNoteConfigurationApi.createOption).not.toHaveBeenCalled()
  expect(projectNoteConfigurationApi.reorderOptions).not.toHaveBeenCalled()
})

it('locks type/unit after first_value_at and locks Select type for any option history', async () => {
  const first = await open({ field: { ...field, first_value_at: '2026-10-30T12:00:00.123456Z' } })
  expect(document.querySelector<HTMLFieldSetElement>('.field-editor__types')!.disabled).toBe(true)
  expect(input('unit').disabled).toBe(true)
  first.host.unmount()
  hosts.splice(hosts.indexOf(first.host), 1)
  storedOptions = [option(17, 'Archived only', true)]
  await open({ field: selectField })
  expect(document.querySelector<HTMLFieldSetElement>('.field-editor__types')!.disabled).toBe(true)
  expect(document.querySelector('.field-editor')?.textContent).toContain('istoric de opțiuni')
})

it('allows an empty Select to change type only after options loaded successfully', async () => {
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({
    data: { ...selectField, type: 'text' },
  })
  await open({ field: selectField })
  expect(document.querySelector<HTMLFieldSetElement>('.field-editor__types')!.disabled).toBe(false)
  await choose('text')
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledWith(12, 7, { type: 'text' })
})

it('retains successful rename checkpoints when a reorder is rejected, then retries only the order', async () => {
  storedOptions = [option(17, 'Yes'), option(18, 'No', true)]
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: selectField })
  vi.mocked(projectNoteConfigurationApi.reorderOptions).mockRejectedValueOnce(
    new ApiError('Provide every current ID exactly once.', 422, { ids: ['List changed'] }),
  )
  const { editor } = await open({ field: selectField })
  await optionLabel(0, 'Updated')
  await optionAction('Mută opțiunea 2 în sus')
  await submit()
  expect(editor.emitted('saved')).toBeUndefined()
  expect(projectNoteConfigurationApi.updateOption).toHaveBeenCalledTimes(1)
  expect(document.querySelector('.select-options')?.textContent).toContain(
    'Provide every current ID',
  )
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledTimes(1)
  expect(projectNoteConfigurationApi.updateOption).toHaveBeenCalledTimes(1)
  expect(projectNoteConfigurationApi.reorderOptions).toHaveBeenCalledTimes(2)
  expect(projectNoteConfigurationApi.reorderOptions).toHaveBeenLastCalledWith(12, 7, {
    ids: [18, 17],
  })
  expect(editor.emitted('saved')).toHaveLength(1)
})

it('retains staged archive intent after a lifecycle failure and retries the same option ID', async () => {
  storedOptions = [option(17, 'Yes')]
  vi.mocked(projectNoteConfigurationApi.updateField).mockResolvedValue({ data: selectField })
  vi.mocked(projectNoteConfigurationApi.archiveOption).mockRejectedValueOnce(
    new ApiError('Restore parent first', 409),
  )
  const { editor } = await open({ field: selectField })
  await optionAction('Arhivează opțiunea 1')
  button('Arhivează la salvare').click()
  await flushPromises()
  await submit()
  expect(editor.emitted('saved')).toBeUndefined()
  expect(document.querySelector('.select-options')?.textContent).toContain('Restore parent first')
  expect(optionInputs()[0]!.value).toBe('Yes')
  expect(projectNoteConfigurationApi.archiveOption).toHaveBeenCalledWith(12, 7, 17)
  await submit()
  expect(projectNoteConfigurationApi.updateField).toHaveBeenCalledTimes(1)
  expect(projectNoteConfigurationApi.archiveOption).toHaveBeenCalledTimes(2)
  expect(editor.emitted('saved')).toHaveLength(1)
})

it('keeps Select type locked on an option-list error and retries the read without losing metadata edits', async () => {
  storedOptions = [option(17, 'Yes')]
  vi.mocked(projectNoteConfigurationApi.options).mockRejectedValueOnce(
    new ApiError('Temporarily unavailable', 503),
  )
  await open({ field: selectField })
  await set('name', 'Unsaved metadata')
  expect(document.querySelector<HTMLFieldSetElement>('.field-editor__types')!.disabled).toBe(true)
  expect(document.querySelector('.select-options')?.textContent).toContain(
    'Temporarily unavailable',
  )
  button('Reîncarcă opțiunile').click()
  await flushPromises()
  expect(input('name').value).toBe('Unsaved metadata')
  expect(optionInputs()[0]!.value).toBe('Yes')
  expect(document.querySelector<HTMLFieldSetElement>('.field-editor__types')!.disabled).toBe(true)
  expect(projectNoteConfigurationApi.updateField).not.toHaveBeenCalled()
})
