// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ProjectNoteTypeFields from './ProjectNoteTypeFields.vue'
import { projectNoteConfigurationApi as api } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type {
  ProjectCustomField,
  ProjectNoteType,
  ProjectNoteTypeField,
} from '@/types/projectNoteConfiguration'
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: {
    noteTypeFields: vi.fn(),
    fields: vi.fn(),
    attachField: vi.fn(),
    updateNoteTypeField: vi.fn(),
    detachField: vi.fn(),
    reorderNoteTypeFields: vi.fn(),
    createAndAttachField: vi.fn(),
    createField: vi.fn(),
    options: vi.fn(),
    createOption: vi.fn(),
    reorderOptions: vi.fn(),
  },
}))
const project = { id: 12, created_by: 1, status: 'active' } as Project
const type: ProjectNoteType = {
  id: 7,
  project_id: 12,
  name: 'General',
  description: null,
  template: null,
  is_default: true,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const field: ProjectCustomField = {
  id: 3,
  project_id: 12,
  name: 'Energy',
  key: 'energy',
  description: null,
  type: 'number',
  unit: null,
  min: null,
  max: null,
  step: null,
  aggregation: 'average',
  first_value_at: null,
  archived_at: null,
  created_at: null,
  updated_at: null,
}
const second = { ...field, id: 5, name: 'Mood', key: 'mood', type: 'text' as const }
const archived = { ...field, id: 8, name: 'Old', key: 'old', archived_at: '2026-10-30' }
let serverRows: ProjectNoteTypeField[]
let nextPeriod = 100
function association(value: ProjectCustomField, required = false): ProjectNoteTypeField {
  return {
    id: ++nextPeriod,
    note_type_id: type.id,
    field_id: value.id,
    required,
    sort_order: 0,
    activated_at: '2026-10-30T12:00:00.000000Z',
    deactivated_at: null,
    field: value,
  }
}
const hosts: ReturnType<typeof mount>[] = []
async function open(props: Partial<InstanceType<typeof ProjectNoteTypeFields>['$props']> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/projects/:id/edit',
        component: defineComponent({
          setup: () => () => h(ProjectNoteTypeFields, { project, noteType: type, ...props }),
        }),
      },
    ],
  })
  await router.push('/projects/12/edit?tab=settings')
  const host = mount(RouterView, { attachTo: document.body, global: { plugins: [router] } })
  hosts.push(host)
  await flushPromises()
  return { panel: host.findComponent(ProjectNoteTypeFields), router }
}
function button(label: string) {
  return Array.from(document.querySelectorAll<HTMLButtonElement>('button')).find(
    (b) => b.getAttribute('aria-label') === label || b.textContent?.trim() === label,
  )!
}
async function click(label: string) {
  button(label).click()
  await flushPromises()
}
async function setField(name: string, value: string) {
  const el = document.querySelector<HTMLInputElement>(`.field-editor [data-field="${name}"] input`)!
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
async function saveField() {
  document
    .querySelector('.field-editor form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await flushPromises()
}
async function newField() {
  await click('+ Adaugă')
  await click('Creează câmp nou')
}
beforeEach(() => {
  vi.resetAllMocks()
  serverRows = [association(field), association(archived)]
  vi.mocked(api.noteTypeFields).mockImplementation(async () => ({ data: [...serverRows] }))
  vi.mocked(api.fields).mockResolvedValue({
    data: [field, second, archived, { ...second, id: 90, project_id: 99, name: 'Foreign' }],
  })
  vi.mocked(api.attachField).mockImplementation(async (_p, _t, payload) => {
    const found = [field, second, archived].find((f) => f.id === payload.field_id) ?? {
      ...field,
      id: payload.field_id,
      name: 'Created',
      type: 'select' as const,
    }
    const result = association(found, payload.required)
    serverRows.push(result)
    return { data: result }
  })
  vi.mocked(api.updateNoteTypeField).mockImplementation(async (_p, _t, id, payload) => {
    const result = {
      ...serverRows.find((row) => row.field_id === id)!,
      ...payload,
      id: ++nextPeriod,
    }
    serverRows = serverRows.map((row) => (row.field_id === id ? result : row))
    return { data: result }
  })
  vi.mocked(api.reorderNoteTypeFields).mockImplementation(async (_p, _t, payload) => {
    serverRows = payload.ids.map((id) => serverRows.find((row) => row.field_id === id)!)
    return { data: [...serverRows] }
  })
  vi.mocked(api.detachField).mockImplementation(async (_p, _t, id) => {
    serverRows = serverRows.filter((row) => row.field_id !== id)
  })
  vi.mocked(api.createAndAttachField).mockImplementation(async (_p, _t, payload) => {
    const result = association(
      { ...field, ...payload.field, id: 20, min: null, max: null, step: null },
      payload.required,
    )
    serverRows.push(result)
    return { data: result }
  })
  vi.mocked(api.createField).mockResolvedValue({
    data: { ...field, id: 20, type: 'select', name: 'Created' },
  })
  vi.mocked(api.createOption).mockResolvedValue({
    data: {
      id: 40,
      field_id: 20,
      label: 'Low',
      sort_order: 0,
      archived_at: null,
      created_at: null,
      updated_at: null,
    },
  })
  vi.mocked(api.reorderOptions).mockResolvedValue({ data: [] })
  vi.mocked(api.options).mockResolvedValue({ data: [] })
})
afterEach(() => {
  hosts.splice(0).forEach((host) => host.unmount())
})
it('loads current associations in API order with archived fields and stable IDs', async () => {
  const { panel } = await open()
  expect(api.noteTypeFields).toHaveBeenCalledExactlyOnceWith(12, 7)
  expect(panel.findAll('[data-field-id]').map((el) => el.attributes('data-field-id'))).toEqual([
    '3',
    '8',
  ])
  expect(panel.text()).toContain('Arhivat')
  expect(panel.text()).toContain('se salvează imediat')
})
it('displays loading and empty states', async () => {
  let resolve!: (value: { data: ProjectNoteTypeField[] }) => void
  vi.mocked(api.noteTypeFields).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  const { panel } = await open()
  expect(panel.get('[role="status"]').text()).toContain('Se încarcă')
  resolve({ data: [] })
  await flushPromises()
  expect(panel.text()).toContain('Niciun câmp asociat')
})
it('retries a failed association read', async () => {
  vi.mocked(api.noteTypeFields).mockRejectedValueOnce(new ApiError('Read failed', 500))
  const { panel } = await open()
  expect(panel.text()).toContain('Read failed')
  await click('Reîncearcă')
  expect(panel.findAll('[data-field-id]')).toHaveLength(2)
})
it.each([
  { readOnly: true },
  { project: { ...project, created_by: 2 } },
  { noteType: { ...type, archived_at: '2026-10-30' } },
])('keeps viewer/archived configurations readable without mutation controls', async (props) => {
  const { panel } = await open(props)
  expect(panel.find('button').exists()).toBe(false)
  expect(panel.text()).toContain('Energy')
  expect(panel.text()).toContain('Opțional')
})
it('filters the picker by project, active associations, archive state and search', async () => {
  const { panel } = await open()
  await click('+ Adaugă')
  expect(button('Asociază Mood')).toBeDefined()
  expect(button('Asociază Energy')).toBeUndefined()
  expect(button('Asociază Old')).toBeUndefined()
  expect(button('Asociază Foreign')).toBeUndefined()
  await panel.get('input[type="search"]').setValue('does not exist')
  expect(panel.text()).toContain('Niciun câmp disponibil')
  await panel.get('input[type="search"]').setValue('text')
  expect(button('Asociază Mood')).toBeDefined()
})
it('retries a library failure without losing associations', async () => {
  vi.mocked(api.fields).mockRejectedValueOnce(new ApiError('Library failed', 500))
  const { panel } = await open()
  await click('+ Adaugă')
  expect(panel.text()).toContain('Library failed')
  await click('Reîncearcă biblioteca')
  expect(button('Asociază Mood')).toBeDefined()
  expect(panel.findAll('[data-field-id]')).toHaveLength(2)
})
it('attaches an existing field with required state and refreshes while retaining Settings', async () => {
  const { panel, router } = await open()
  await click('+ Adaugă')
  await panel.get('input[type="checkbox"]').setValue(true)
  await click('Asociază Mood')
  expect(api.attachField).toHaveBeenCalledExactlyOnceWith(12, 7, { field_id: 5, required: true })
  expect(panel.findAll('[data-field-id]')).toHaveLength(3)
  expect(router.currentRoute.value.query.tab).toBe('settings')
})
it('toggles only the association required state and accepts a replacement period ID', async () => {
  const { panel } = await open()
  const originalId = serverRows[0]!.id
  await click('Obligatoriu Energy')
  expect(api.updateNoteTypeField).toHaveBeenCalledExactlyOnceWith(12, 7, 3, { required: true })
  expect(serverRows[0]!.id).not.toBe(originalId)
  expect(panel.get('[aria-label="Obligatoriu Energy"]').attributes('aria-pressed')).toBe('true')
  expect(api.createField).not.toHaveBeenCalled()
})
it('reorders with all active field IDs including archived definitions, never period IDs', async () => {
  const { panel } = await open()
  await click('Mută în jos Energy')
  expect(api.reorderNoteTypeFields).toHaveBeenCalledExactlyOnceWith(12, 7, { ids: [8, 3] })
  expect(panel.findAll('[data-field-id]').map((el) => el.attributes('data-field-id'))).toEqual([
    '8',
    '3',
  ])
})
it('preserves a failed desired order and retries that exact order', async () => {
  vi.mocked(api.reorderNoteTypeFields).mockRejectedValueOnce(
    new ApiError('Concurrent change', 422, { ids: ['Full ID list required'] }),
  )
  const { panel } = await open()
  await click('Mută în jos Energy')
  expect(panel.text()).toContain('Full ID list required')
  expect(panel.get('[data-field-id]').attributes('data-field-id')).toBe('8')
  await click('Reîncearcă ordinea')
  expect(api.reorderNoteTypeFields).toHaveBeenNthCalledWith(2, 12, 7, { ids: [8, 3] })
})
it('confirms explicit reload before discarding failed order', async () => {
  vi.mocked(api.reorderNoteTypeFields).mockRejectedValue(new ApiError('Conflict', 409))
  const { panel } = await open()
  await click('Mută în jos Energy')
  await click('Reîncarcă lista')
  expect(document.querySelector('dialog[open]')?.textContent).toContain('Ordinea nesalvată')
  await click('Reîncarcă')
  expect(panel.get('[data-field-id]').attributes('data-field-id')).toBe('3')
})
it('confirms detach and preserves the field definition', async () => {
  const { panel } = await open()
  await click('Detașează Energy')
  expect(api.detachField).not.toHaveBeenCalled()
  expect(document.querySelector('dialog[open]')?.textContent).toContain('Rămâne în biblioteca')
  await click('Detașează')
  expect(api.detachField).toHaveBeenCalledExactlyOnceWith(12, 7, 3)
  expect(panel.findAll('[data-field-id]')).toHaveLength(1)
  expect(field.archived_at).toBeNull()
  expect(api.createField).not.toHaveBeenCalled()
})
it('canceling detach leaves the configuration intact', async () => {
  const { panel } = await open()
  await click('Detașează Energy')
  await click('Anulează')
  expect(api.detachField).not.toHaveBeenCalled()
  expect(panel.findAll('[data-field-id]')).toHaveLength(2)
})
it.each([403, 409, 422, 0])(
  'preserves configuration and useful messages on association error %s',
  async (status) => {
    vi.mocked(api.updateNoteTypeField).mockRejectedValue(
      new ApiError('Conflict', status, { required: ['Server detail'] }),
    )
    const { panel } = await open()
    await click('Obligatoriu Energy')
    expect(panel.text()).toContain('Server detail')
    expect(panel.get('[aria-label="Obligatoriu Energy"]').attributes('aria-pressed')).toBe('false')
  },
)
it('blocks duplicate submissions during association requests', async () => {
  let resolve!: (value: { data: ProjectNoteTypeField }) => void
  vi.mocked(api.updateNoteTypeField).mockReturnValue(
    new Promise((answer) => {
      resolve = answer
    }),
  )
  await open()
  await click('Obligatoriu Energy')
  await click('Obligatoriu Energy')
  expect(api.updateNoteTypeField).toHaveBeenCalledTimes(1)
  resolve({ data: association(field, true) })
  await flushPromises()
})
it.each(['number', 'text', 'boolean'] as const)(
  'reuses the field editor and atomic create-and-attach for %s',
  async (kind) => {
    const { panel } = await open()
    await newField()
    await setField('name', 'New')
    if (kind !== 'number') {
      document
        .querySelector<HTMLInputElement>(`.field-editor input[type="radio"][value="${kind}"]`)!
        .click()
      await flushPromises()
    }
    await saveField()
    expect(api.createAndAttachField).toHaveBeenCalledExactlyOnceWith(12, 7, {
      field: expect.objectContaining({ name: 'New', key: 'new', type: kind }),
      required: false,
    })
    expect(api.createField).not.toHaveBeenCalled()
    expect(api.attachField).not.toHaveBeenCalled()
    expect(panel.find('[data-field-id="20"]').exists()).toBe(true)
  },
)
it('reuses Select option checkpoints before attaching the saved field', async () => {
  await open()
  await newField()
  await setField('name', 'New')
  document.querySelector<HTMLInputElement>('.field-editor input[value="select"]')!.click()
  await flushPromises()
  await click('+ Opțiune')
  const el = document.querySelector<HTMLInputElement>('.select-option input')!
  el.value = 'Low'
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  await saveField()
  expect(api.createAndAttachField).not.toHaveBeenCalled()
  expect(api.createField).toHaveBeenCalledTimes(1)
  expect(api.createOption).toHaveBeenCalledExactlyOnceWith(12, 20, { label: 'Low', sort_order: 0 })
  expect(api.attachField).toHaveBeenCalledExactlyOnceWith(12, 7, { field_id: 20, required: false })
})
it('retains the created Select ID after attach failure and retries safely', async () => {
  vi.mocked(api.attachField).mockRejectedValueOnce(new ApiError('Attach failed', 409))
  const { panel } = await open()
  await newField()
  await setField('name', 'New')
  document.querySelector<HTMLInputElement>('.field-editor input[value="select"]')!.click()
  await flushPromises()
  await click('+ Opțiune')
  const el = document.querySelector<HTMLInputElement>('.select-option input')!
  el.value = 'Low'
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  await saveField()
  expect(panel.text()).toContain('(#20) este creat')
  expect(panel.text()).toContain('Attach failed')
  await click('Reîncearcă asocierea')
  expect(api.createField).toHaveBeenCalledTimes(1)
  expect(api.createOption).toHaveBeenCalledTimes(1)
  expect(api.attachField).toHaveBeenCalledTimes(2)
  expect(panel.text()).not.toContain('Asocierea nu este confirmată')
})
it('maps atomic field validation back into the shared editor without recreating definitions', async () => {
  vi.mocked(api.createAndAttachField).mockRejectedValue(
    new ApiError('Invalid', 422, { 'field.key': ['Duplicate key'] }),
  )
  await open()
  await newField()
  await setField('name', 'New')
  await saveField()
  expect(document.querySelector('.field-editor')?.textContent).toContain('Duplicate key')
  expect(
    document.querySelector<HTMLInputElement>('.field-editor [data-field="name"] input')?.value,
  ).toBe('New')
})
it('does not blindly repeat an uncertain atomic create', async () => {
  vi.mocked(api.createAndAttachField).mockRejectedValue(new ApiError('Network', 0))
  await open()
  await newField()
  await setField('name', 'New')
  await saveField()
  await saveField()
  expect(api.createAndAttachField).toHaveBeenCalledTimes(1)
  expect(document.querySelector('.field-editor')?.textContent).toContain('nu este confirmată')
})

async function prepareSelect() {
  await newField()
  await setField('name', 'New')
  document.querySelector<HTMLInputElement>('.field-editor input[value="select"]')!.click()
  await flushPromises()
  await click('+ Opțiune')
  const el = document.querySelector<HTMLInputElement>('.select-option input')!
  el.value = 'Low'
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}
it('retries Select option failures before attempting association without recreating the field', async () => {
  vi.mocked(api.createOption).mockRejectedValueOnce(
    new ApiError('Invalid label', 422, { label: ['Option validation'] }),
  )
  await open()
  await prepareSelect()
  await saveField()
  expect(api.attachField).not.toHaveBeenCalled()
  expect(document.querySelector('.field-editor')?.textContent).toContain('Option validation')
  await saveField()
  expect(api.createField).toHaveBeenCalledTimes(1)
  expect(api.createOption).toHaveBeenCalledTimes(2)
  expect(api.attachField).toHaveBeenCalledExactlyOnceWith(12, 7, { field_id: 20, required: false })
})
it('reconciles an uncertain but committed Select attachment before retrying', async () => {
  vi.mocked(api.attachField).mockImplementationOnce(async () => {
    serverRows.push(association({ ...field, id: 20, type: 'select' }))
    throw new ApiError('Lost response', 0)
  })
  const { panel } = await open()
  await prepareSelect()
  await saveField()
  expect(panel.text()).toContain('Asocierea nu este confirmată')
  await click('Reîncearcă asocierea')
  expect(api.attachField).toHaveBeenCalledTimes(1)
  expect(api.createField).toHaveBeenCalledTimes(1)
  expect(panel.findAll('[data-field-id="20"]')).toHaveLength(1)
  expect(panel.text()).not.toContain('Asocierea nu este confirmată')
})
it('reconciles uncertain existing-field attachment through explicit reload', async () => {
  vi.mocked(api.attachField).mockImplementationOnce(async () => {
    serverRows.push(association(second))
    throw new ApiError('Lost response', 0)
  })
  const { panel } = await open()
  await click('+ Adaugă')
  await click('Asociază Mood')
  expect(button('Asociază Mood').disabled).toBe(true)
  await click('Reîncarcă lista')
  expect(panel.find('[data-field-id="5"]').exists()).toBe(true)
  expect(button('Asociază Mood')).toBeUndefined()
  expect(api.attachField).toHaveBeenCalledTimes(1)
})
