// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ProjectProgressNoteForm from './ProjectProgressNoteForm.vue'
import ProgressNoteValuesDisplay from './ProgressNoteValuesDisplay.vue'
import { projectProgressApi as progress } from '@/services/projectProgressApi'
import { projectNoteConfigurationApi as api } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { NoteConfigurationField, ProgressNote } from '@/types/projectProgress'
import type { ProjectCustomField, ProjectNoteType } from '@/types/projectNoteConfiguration'

const auth = vi.hoisted(() => ({ user: { id: 1 } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/notifications', () => ({ useNotificationStore: () => ({ error: vi.fn() }) }))
vi.mock('@/services/projectProgressApi', () => ({
  projectProgressApi: {
    preview: vi.fn(),
    createNote: vi.fn(),
    updateNote: vi.fn(),
    createEvaluation: vi.fn(),
    updateEvaluation: vi.fn(),
  },
}))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: { noteTypes: vi.fn(), noteTypeFields: vi.fn(), options: vi.fn() },
}))
const project = {
  id: 1,
  name: 'Project',
  created_by: 1,
  status: 'active',
  note_template: 'Legacy template',
} as Project
const type: ProjectNoteType = {
  id: 7,
  project_id: 1,
  name: 'Daily',
  template: 'Type template\n',
  description: null,
  is_default: true,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const second = { ...type, id: 8, name: 'Other', template: 'Other template', is_default: false }
const baseField: ProjectCustomField = {
  id: 1,
  project_id: 1,
  name: 'Exact number',
  key: 'number',
  description: null,
  type: 'number',
  unit: 'points',
  min: null,
  max: null,
  step: null,
  aggregation: 'average',
  first_value_at: null,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const definitions: ProjectCustomField[] = [
  baseField,
  { ...baseField, id: 2, name: 'Text', key: 'text', type: 'text', unit: null, aggregation: null },
  {
    ...baseField,
    id: 3,
    name: 'Boolean',
    key: 'boolean',
    type: 'boolean',
    unit: null,
    aggregation: null,
  },
  {
    ...baseField,
    id: 4,
    name: 'Select',
    key: 'select',
    type: 'select',
    unit: null,
    aggregation: null,
  },
]
const option = {
  id: 40,
  field_id: 4,
  label: 'Captured option',
  sort_order: 0,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const fields: NoteConfigurationField[] = definitions.map((definition, index) => ({
  field_id: definition.id,
  association_id: index + 100,
  required: false,
  sort_order: index,
  definition,
  options: definition.type === 'select' ? [option] : [],
}))
const legacy: ProgressNote = {
  id: 50,
  project_id: 1,
  created_by: 1,
  updated_by: null,
  title: 'Saved title',
  content: 'Saved content',
  noted_at: '2020-01-01',
  task_ids: [],
  tasks: [],
  evaluations: [],
  deleted_at: null,
}
const saved: ProgressNote = {
  ...legacy,
  note_type_id: 7,
  configuration_captured_at: '2026-10-31T12:00:00.123456Z',
  configuration: { schema_version: 1, note_type: type, fields },
  values: [
    { field_id: 1, value: '0.000000' },
    { field_id: 2, value: ' Saved\ntext ' },
    { field_id: 3, value: false },
    { field_id: 4, value: 40 },
  ],
  current_configuration_state: {
    note_type_archived_at: null,
    fields: fields.map((field) => ({
      field_id: field.field_id,
      type: field.definition.type,
      unit: field.definition.unit,
      archived_at: null,
      options: field.options.map((item) => ({ id: item.id, archived_at: item.archived_at })),
    })),
  },
}
const wrappers: ReturnType<typeof mount>[] = []
function open(note?: ProgressNote) {
  const wrapper = mount(ProjectProgressNoteForm, {
    props: { project, note, initialDate: '2026-11-01' },
    attachTo: document.body,
    global: { stubs: { ProjectTaskPicker: true } },
  })
  wrappers.push(wrapper)
  return wrapper
}
function custom(wrapper: ReturnType<typeof open>, id: number) {
  return wrapper.get(`[data-custom-field="${id}"]`)
}
async function fieldValue(wrapper: ReturnType<typeof open>, id: number, value: string) {
  await custom(wrapper, id).get('input, textarea, select').setValue(value)
}
async function switchType(wrapper: ReturnType<typeof open>, id: string) {
  await wrapper.get('.note-editor-type select').setValue(id)
  await flushPromises()
}
async function click(text: string) {
  Array.from(document.querySelectorAll<HTMLButtonElement>('button'))
    .find((button) => button.textContent?.trim() === text)!
    .click()
  await flushPromises()
}
async function submit(wrapper: ReturnType<typeof open>) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}
function cloneSaved(): ProgressNote {
  return structuredClone(saved)
}
beforeEach(() => {
  vi.resetAllMocks()
  auth.user.id = 1
  vi.stubGlobal(
    'fetch',
    vi.fn().mockRejectedValue(new Error('Unexpected network request in a mocked test')),
  )
  vi.mocked(api.noteTypes).mockResolvedValue({ data: [type, second] })
  vi.mocked(api.noteTypeFields).mockResolvedValue({
    data: fields.map((field) => ({
      id: field.association_id,
      note_type_id: 7,
      field_id: field.field_id,
      required: field.required,
      sort_order: field.sort_order,
      activated_at: '2026-10-31',
      deactivated_at: null,
      field: definitions.find((definition) => definition.id === field.field_id)!,
    })),
  })
  vi.mocked(api.options).mockResolvedValue({ data: [option] })
  vi.mocked(progress.preview).mockResolvedValue({ data: { content: 'Legacy generated' } })
  vi.mocked(progress.createNote).mockResolvedValue({ data: saved })
  vi.mocked(progress.updateNote).mockResolvedValue({ data: saved })
  vi.mocked(progress.createEvaluation).mockResolvedValue({
    data: {
      id: 5,
      task_id: 9,
      score: 5,
      note_id: 50,
      created_by: 1,
      evaluation_date: '2026-11-01',
      lock_version: 0,
      is_active: true,
      withdrawn_at: null,
    },
  })
})
afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.unstubAllGlobals()
})
it('preselects the active default as a pristine unsaved draft and uses its template', async () => {
  const w = open()
  await flushPromises()
  expect((w.get('.note-editor-type select').element as HTMLSelectElement).value).toBe('7')
  expect((w.get('.note-editor-observation textarea').element as HTMLTextAreaElement).value).toBe(
    'Type template\n',
  )
  expect(w.vm.dirty).toBe(false)
  expect(progress.createNote).not.toHaveBeenCalled()
  expect(progress.preview).not.toHaveBeenCalled()
})
it('supports explicit untyped creation with the legacy template and no configuration payload', async () => {
  const w = open()
  await flushPromises()
  await switchType(w, '')
  await submit(w)
  expect(progress.preview).toHaveBeenCalledTimes(1)
  expect(progress.createNote).toHaveBeenCalledWith(
    1,
    expect.objectContaining({ content: 'Legacy generated' }),
  )
  expect(vi.mocked(progress.createNote).mock.calls[0]![1]).not.toHaveProperty('note_type_id')
  expect(vi.mocked(progress.createNote).mock.calls[0]![1]).not.toHaveProperty('values')
})
it.each([{ types: [] }, { types: [{ ...type, archived_at: '2026-10-31' }] }])(
  'keeps projects with no active types usable',
  async ({ types }) => {
    vi.mocked(api.noteTypes).mockResolvedValue({ data: types })
    const w = open()
    await flushPromises()
    await submit(w)
    expect(progress.createNote).toHaveBeenCalledTimes(1)
    expect(api.noteTypeFields).not.toHaveBeenCalled()
    expect(w.find('[data-custom-field]').exists()).toBe(false)
  },
)
it('allows an explicit legacy fallback after a types API error', async () => {
  vi.mocked(api.noteTypes).mockRejectedValue(new ApiError('Unavailable', 500))
  const w = open()
  await flushPromises()
  expect(w.vm.saveDisabled).toBe(true)
  await click('Continuă fără tip')
  await submit(w)
  expect(progress.createNote).toHaveBeenCalledTimes(1)
})
it('sends exact decimal strings, whitespace-preserving text, strict false and option IDs', async () => {
  const w = open()
  await flushPromises()
  await fieldValue(w, 1, '999999999999.123456')
  await fieldValue(w, 2, '  Notes:\n\n')
  await fieldValue(w, 3, 'false')
  await fieldValue(w, 4, '40')
  await submit(w)
  expect(progress.createNote).toHaveBeenCalledExactlyOnceWith(
    1,
    expect.objectContaining({
      note_type_id: 7,
      values: [
        { field_id: 1, value: '999999999999.123456' },
        { field_id: 2, value: '  Notes:\n\n' },
        { field_id: 3, value: false },
        { field_id: 4, value: 40 },
      ],
    }),
  )
})
it('accepts required zero and false while rejecting unset required fields', async () => {
  vi.mocked(api.noteTypeFields).mockResolvedValue({
    data: fields.map((field) => ({
      id: field.association_id,
      note_type_id: 7,
      field_id: field.field_id,
      required: [1, 3].includes(field.field_id),
      sort_order: field.sort_order,
      activated_at: '2026-10-31',
      deactivated_at: null,
      field: definitions.find((definition) => definition.id === field.field_id)!,
    })),
  })
  const w = open()
  await flushPromises()
  await submit(w)
  expect(progress.createNote).not.toHaveBeenCalled()
  expect(custom(w, 1).text()).toContain('obligatoriu')
  await fieldValue(w, 1, '0')
  await fieldValue(w, 3, 'false')
  await submit(w)
  expect(progress.createNote).toHaveBeenCalledTimes(1)
})
it('clears one optional value with explicit null and preserves omitted historical values', async () => {
  const w = open(cloneSaved())
  await flushPromises()
  await custom(w, 2).get('[aria-label="Golește Text"]').trigger('click')
  await submit(w)
  expect(progress.updateNote).toHaveBeenCalledExactlyOnceWith(
    1,
    50,
    expect.objectContaining({ values: [{ field_id: 2, value: null }] }),
  )
  expect(vi.mocked(progress.updateNote).mock.calls[0]![2]).not.toHaveProperty('note_type_id')
  expect(api.noteTypes).not.toHaveBeenCalled()
  expect(api.noteTypeFields).not.toHaveBeenCalled()
})
it('omits unchanged values, including semantically equal decimal input', async () => {
  const w = open(cloneSaved())
  await fieldValue(w, 1, '0')
  await submit(w)
  expect(vi.mocked(progress.updateNote).mock.calls[0]![2]).not.toHaveProperty('values')
})
it('renders only frozen fields, names, units, order and options on historical notes', async () => {
  const note = cloneSaved()
  note.configuration!.fields = [note.configuration!.fields[3]!, note.configuration!.fields[0]!]
  const w = open(note)
  await flushPromises()
  expect(
    w.findAll('[data-custom-field]').map((field) => field.attributes('data-custom-field')),
  ).toEqual(['4', '1'])
  expect(w.text()).toContain('Exact number')
  expect(w.text()).toContain('points')
  expect(w.find('.note-editor-type select').exists()).toBe(false)
  expect(api.noteTypeFields).not.toHaveBeenCalled()
  expect(api.options).not.toHaveBeenCalled()
})
it('keeps legacy notes untyped without requesting current configuration', async () => {
  const w = open(legacy)
  await flushPromises()
  await submit(w)
  expect(w.text()).toContain('Fără tip')
  expect(api.noteTypes).not.toHaveBeenCalled()
  expect(vi.mocked(progress.updateNote).mock.calls[0]![2]).not.toHaveProperty('note_type_id')
})
it('keeps archived historical values readable and allows optional clearing and restoring the original draft value', async () => {
  const note = cloneSaved()
  note.current_configuration_state!.fields[1]!.archived_at = '2026-10-31'
  const w = open(note)
  await flushPromises()
  expect(custom(w, 2).get('textarea').attributes('disabled')).toBeDefined()
  await custom(w, 2).get('[aria-label="Golește Text"]').trigger('click')
  expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe('')
  await click('Păstrează valoarea salvată')
  await submit(w)
  expect(vi.mocked(progress.updateNote).mock.calls[0]![2]).not.toHaveProperty('values')
})
it('blocks replacement on incompatible current type/unit but permits unrelated note edits', async () => {
  const note = cloneSaved()
  note.current_configuration_state!.fields[0]!.unit = 'different'
  const w = open(note)
  await flushPromises()
  expect(custom(w, 1).get('input').attributes('disabled')).toBeDefined()
  await w.get('.note-editor-observation textarea').setValue('Unrelated')
  await submit(w)
  expect(progress.updateNote).toHaveBeenCalledTimes(1)
  expect(vi.mocked(progress.updateNote).mock.calls[0]![2]).not.toHaveProperty('values')
})
it('retains the original archived option but disables selecting another archived option', async () => {
  const note = cloneSaved()
  note.configuration!.fields[3]!.options.push({
    ...option,
    id: 41,
    label: 'Other old option',
    archived_at: '2026-10-31',
  })
  note.current_configuration_state!.fields[3]!.options = [
    { id: 40, archived_at: '2026-10-31' },
    { id: 41, archived_at: '2026-10-31' },
  ]
  const w = open(note)
  await flushPromises()
  const options = custom(w, 4).findAll('option')
  expect(
    options.find((o) => o.attributes('value') === '40')!.attributes('disabled'),
  ).toBeUndefined()
  expect(options.find((o) => o.attributes('value') === '41')!.attributes('disabled')).toBeDefined()
  await submit(w)
  expect(vi.mocked(progress.updateNote).mock.calls[0]![2]).not.toHaveProperty('values')
})
it('retains detached snapshot fields and allows their compatible edits on an archived Note Type', async () => {
  const note = cloneSaved()
  note.current_configuration_state!.note_type_archived_at = '2026-10-31'
  const w = open(note)
  await fieldValue(w, 2, 'Historical edit')
  await submit(w)
  expect(w.text()).toContain('Arhivat')
  expect(progress.updateNote).toHaveBeenCalledWith(
    1,
    50,
    expect.objectContaining({ values: [{ field_id: 2, value: 'Historical edit' }] }),
  )
  expect(api.noteTypeFields).not.toHaveBeenCalled()
})
it('confirms type changes after content or values are edited, permits cancellation, and never carries values', async () => {
  const w = open()
  await flushPromises()
  await w.get('.note-editor-observation textarea').setValue('My draft')
  await fieldValue(w, 2, 'Field draft')
  await switchType(w, '8')
  expect(document.querySelector('dialog[open]')).not.toBeNull()
  await click('Continuă editarea')
  expect((w.get('.note-editor-type select').element as HTMLSelectElement).value).toBe('7')
  expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe('Field draft')
  await switchType(w, '8')
  await click('Schimbă tipul')
  expect((w.get('.note-editor-type select').element as HTMLSelectElement).value).toBe('8')
  expect((w.get('.note-editor-observation textarea').element as HTMLTextAreaElement).value).toBe(
    'Other template',
  )
  expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe('')
  expect(progress.createNote).not.toHaveBeenCalled()
})
it('does not overwrite text entered while default configuration is loading', async () => {
  let resolve!: (value: { data: ProjectNoteType[] }) => void
  vi.mocked(api.noteTypes).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const w = open()
  await w.get('.note-editor-observation textarea').setValue('My early draft')
  resolve({ data: [type] })
  await flushPromises()
  expect((w.get('.note-editor-observation textarea').element as HTMLTextAreaElement).value).toBe(
    'My early draft',
  )
  expect(w.vm.dirty).toBe(true)
})
it.each([409, 422, 0])(
  'preserves typed drafts after API error %s and maps merge indices to field IDs',
  async (status) => {
    vi.mocked(progress.updateNote).mockRejectedValue(
      new ApiError('Server detail', status, { 'values.0.value': ['Field validation'] }),
    )
    const w = open(cloneSaved())
    await fieldValue(w, 2, 'Draft remains\n')
    await submit(w)
    expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe(
      'Draft remains\n',
    )
    expect(custom(w, 2).text()).toContain('Field validation')
    expect(w.emitted('saved')).toBeUndefined()
  },
)
it('retains the saved typed note checkpoint when an evaluation fails and retries only evaluations', async () => {
  vi.mocked(progress.createEvaluation)
    .mockRejectedValueOnce(new ApiError('Conflict', 409))
    .mockResolvedValueOnce({
      data: {
        id: 5,
        task_id: 9,
        score: 5,
        note_id: 50,
        created_by: 1,
        evaluation_date: '2026-11-01',
        lock_version: 0,
        is_active: true,
        withdrawn_at: null,
      },
    })
  const w = mount(ProjectProgressNoteForm, {
    props: {
      project,
      initialTasks: [{ id: 9, title: 'Task', deleted_at: null }],
      initialDate: '2026-11-01',
    },
    attachTo: document.body,
    global: { stubs: { ProjectTaskPicker: true } },
  })
  wrappers.push(w)
  await flushPromises()
  await fieldValue(w, 3, 'false')
  await w.get('.note-editor-score input').setValue('5')
  await submit(w)
  expect(w.emitted('saved')).toBeUndefined()
  expect(w.text()).toContain('Unele evaluări')
  expect((custom(w, 3).get('select').element as HTMLSelectElement).disabled).toBe(true)
  await submit(w)
  expect(progress.createNote).toHaveBeenCalledTimes(1)
  expect(progress.createEvaluation).toHaveBeenCalledTimes(2)
  expect(w.emitted('saved')).toHaveLength(1)
})
it('blocks blind retry after an uncertain typed creation and retains the draft', async () => {
  vi.mocked(progress.createNote).mockRejectedValue(new ApiError('Network', 0))
  const w = open()
  await flushPromises()
  await fieldValue(w, 2, 'Keep')
  await submit(w)
  await submit(w)
  expect(progress.createNote).toHaveBeenCalledTimes(1)
  expect(w.vm.saveDisabled).toBe(true)
  expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe('Keep')
})
it('permits an authorized collaborator to edit their own typed note without configuration-owner checks', async () => {
  auth.user.id = 2
  const note = cloneSaved()
  note.created_by = 2
  const w = open(note)
  await fieldValue(w, 2, 'Collaborator')
  await submit(w)
  expect(progress.updateNote).toHaveBeenCalledTimes(1)
})
it('prevents another viewer from saving a typed note', async () => {
  auth.user.id = 3
  const note = cloneSaved()
  note.created_by = 2
  const w = open(note)
  await submit(w)
  expect(progress.updateNote).not.toHaveBeenCalled()
  expect(w.vm.saveDisabled).toBe(true)
})
it('shows saved type and captured values with zero, false, unset and exact whitespace', () => {
  const note = cloneSaved()
  note.values!.find((value) => value.field_id === 4)!.value = null
  const w = mount(ProgressNoteValuesDisplay, { props: { note } })
  wrappers.push(w)
  expect(w.get('summary').text()).toContain('Daily')
  expect(w.findAll('dd').map((value) => value.element.textContent)).toEqual([
    '0.000000',
    ' Saved\ntext ',
    'Nu',
    'Nesetat',
  ])
})

it('excludes archived fields from new notes and unavailable options from new selection', async () => {
  vi.mocked(api.noteTypeFields).mockResolvedValue({
    data: [
      {
        id: 101,
        note_type_id: 7,
        field_id: 1,
        required: true,
        sort_order: 0,
        activated_at: '2026-10-31',
        deactivated_at: null,
        field: { ...baseField, archived_at: '2026-10-31' },
      },
      {
        id: 104,
        note_type_id: 7,
        field_id: 4,
        required: false,
        sort_order: 1,
        activated_at: '2026-10-31',
        deactivated_at: null,
        field: definitions[3]!,
      },
    ],
  })
  vi.mocked(api.options).mockResolvedValue({ data: [{ ...option, archived_at: '2026-10-31' }] })
  const w = open()
  await flushPromises()
  expect(w.find('[data-custom-field="1"]').exists()).toBe(false)
  expect(custom(w, 4).get('option[value="40"]').attributes('disabled')).toBeDefined()
})
it('restored captured options are available without introducing current-only options', async () => {
  const note = cloneSaved()
  note.values!.find((value) => value.field_id === 4)!.value = null
  note.configuration!.fields[3]!.options[0]!.archived_at = '2026-10-31'
  note.current_configuration_state!.fields[3]!.options.push({ id: 99, archived_at: null })
  const w = open(note)
  await flushPromises()
  expect(custom(w, 4).get('option[value="40"]').attributes('disabled')).toBeUndefined()
  expect(custom(w, 4).find('option[value="99"]').exists()).toBe(false)
  await fieldValue(w, 4, '40')
  await submit(w)
  expect(progress.updateNote).toHaveBeenCalledWith(
    1,
    50,
    expect.objectContaining({ values: [{ field_id: 4, value: 40 }] }),
  )
})
it('keeps the unsaved default pristine while field configuration is still loading', async () => {
  vi.mocked(api.noteTypeFields).mockReturnValue(new Promise(() => {}))
  const w = open()
  await flushPromises()
  expect(w.vm.dirty).toBe(false)
  expect(w.vm.saveDisabled).toBe(true)
})
it('shows field loading failures and can retry without losing observation text', async () => {
  vi.mocked(api.noteTypeFields).mockRejectedValueOnce(new ApiError('Fields unavailable', 500))
  const w = open()
  await flushPromises()
  await w.get('.note-editor-observation textarea').setValue('Preserved text')
  expect(w.vm.saveDisabled).toBe(true)
  await click('Reîncearcă câmpurile')
  expect((w.get('.note-editor-observation textarea').element as HTMLTextAreaElement).value).toBe(
    'Preserved text',
  )
  expect(w.vm.saveDisabled).toBe(false)
})
it('confirms field reload before discarding custom drafts while preserving observation text', async () => {
  const w = open()
  await flushPromises()
  await fieldValue(w, 2, 'Draft')
  await click('Reîncarcă câmpurile')
  await click('Continuă editarea')
  expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe('Draft')
  await click('Reîncarcă câmpurile')
  await click('Reîncarcă')
  expect((custom(w, 2).get('textarea').element as HTMLTextAreaElement).value).toBe('')
  expect((w.get('.note-editor-observation textarea').element as HTMLTextAreaElement).value).toBe(
    'Type template\n',
  )
})
it('blocks typed editing when the frozen snapshot is absent instead of substituting current associations', async () => {
  const note = cloneSaved()
  note.configuration = null
  const w = open(note)
  await flushPromises()
  expect(w.vm.saveDisabled).toBe(true)
  expect(w.text()).toContain('Tip salvat')
  expect(api.noteTypeFields).not.toHaveBeenCalled()
})
it('does not blindly repeat a generic uncertain create error but still allows correcting local validation errors', async () => {
  vi.mocked(progress.createNote).mockRejectedValue(new Error('Unreadable response'))
  const w = open()
  await flushPromises()
  await submit(w)
  await submit(w)
  expect(progress.createNote).toHaveBeenCalledTimes(1)
  expect(w.vm.saveDisabled).toBe(true)
})
