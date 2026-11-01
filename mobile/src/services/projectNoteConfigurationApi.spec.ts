import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  ProjectCustomField,
  ProjectCustomFieldOption,
  ProjectNoteType,
  ProjectNoteTypeField,
  CreateProjectCustomFieldPayload,
  CreateAndAttachProjectNoteTypeFieldPayload,
} from '@/types/projectNoteConfiguration'

const timestamp = '2026-10-30T12:00:00.123456Z'
const field: ProjectCustomField = {
  id: 7,
  project_id: 12,
  name: 'Energy',
  key: 'energy',
  description: null,
  type: 'number',
  unit: 'points',
  min: '-1.000000',
  max: '999999999999.123456',
  step: '0.000001',
  aggregation: 'average',
  first_value_at: null,
  archived_at: null,
  created_at: timestamp,
  updated_at: timestamp,
}
const option: ProjectCustomFieldOption = {
  id: 9,
  field_id: 7,
  label: 'Calm',
  sort_order: 0,
  archived_at: null,
  created_at: timestamp,
  updated_at: timestamp,
}
const type: ProjectNoteType = {
  id: 4,
  project_id: 12,
  name: 'General',
  description: null,
  template: null,
  is_default: false,
  archived_at: null,
  created_at: timestamp,
  updated_at: timestamp,
}
const association: ProjectNoteTypeField = {
  id: 101,
  note_type_id: 4,
  field_id: 7,
  sort_order: 0,
  required: false,
  activated_at: timestamp,
  deactivated_at: null,
  field,
}
const createField: CreateProjectCustomFieldPayload = {
  name: 'Energy',
  key: 'energy',
  type: 'number',
  unit: 'points',
  min: '-1.000000',
  max: '10.000000',
  step: '0.000001',
  aggregation: 'average',
}
const createAndAttach: CreateAndAttachProjectNoteTypeFieldPayload = {
  field: createField,
  required: false,
  sort_order: 0,
}
const fetchMock = vi.fn<typeof fetch>()
let client: (typeof import('./projectNoteConfigurationApi'))['projectNoteConfigurationApi']
let transport: typeof import('./api')

beforeEach(async () => {
  vi.resetModules()
  fetchMock.mockReset()
  vi.stubEnv('VITE_API_BASE_URL', 'https://jucans.test/api/')
  vi.stubGlobal('fetch', fetchMock)
  transport = await import('./api')
  transport.setApiAccessToken('test-token')
  client = (await import('./projectNoteConfigurationApi')).projectNoteConfigurationApi
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.unstubAllEnvs()
})

interface EndpointCase {
  label: string
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  path: string
  body?: unknown
  run: () => Promise<unknown>
  response?: unknown
}
const cases: EndpointCase[] = [
  {
    label: 'list fields',
    method: 'GET',
    path: '/custom-fields',
    run: () => client.fields(12),
    response: { data: [field] },
  },
  {
    label: 'read field',
    method: 'GET',
    path: '/custom-fields/7',
    run: () => client.field(12, 7),
    response: { data: field },
  },
  {
    label: 'create field',
    method: 'POST',
    path: '/custom-fields',
    body: createField,
    run: () => client.createField(12, createField),
    response: { data: field },
  },
  {
    label: 'patch field with null/zero settings',
    method: 'PATCH',
    path: '/custom-fields/7',
    body: { description: null, min: 0, max: null },
    run: () => client.updateField(12, 7, { description: null, min: 0, max: null }),
    response: { data: { ...field, description: null, min: '0.000000', max: null } },
  },
  {
    label: 'archive field',
    method: 'POST',
    path: '/custom-fields/7/archive',
    run: () => client.archiveField(12, 7),
    response: { data: { ...field, archived_at: timestamp } },
  },
  {
    label: 'restore field',
    method: 'POST',
    path: '/custom-fields/7/restore',
    run: () => client.restoreField(12, 7),
    response: { data: field },
  },
  {
    label: 'list options',
    method: 'GET',
    path: '/custom-fields/7/options',
    run: () => client.options(12, 7),
    response: { data: [option] },
  },
  {
    label: 'create option',
    method: 'POST',
    path: '/custom-fields/7/options',
    body: { label: 'Calm', sort_order: 0 },
    run: () => client.createOption(12, 7, { label: 'Calm', sort_order: 0 }),
    response: { data: option },
  },
  {
    label: 'update option',
    method: 'PATCH',
    path: '/custom-fields/7/options/9',
    body: { label: 'Relaxed' },
    run: () => client.updateOption(12, 7, 9, { label: 'Relaxed' }),
    response: { data: { ...option, label: 'Relaxed' } },
  },
  {
    label: 'reorder options',
    method: 'POST',
    path: '/custom-fields/7/options/reorder',
    body: { ids: [9, 8] },
    run: () => client.reorderOptions(12, 7, { ids: [9, 8] }),
    response: { data: [option] },
  },
  {
    label: 'archive option',
    method: 'POST',
    path: '/custom-fields/7/options/9/archive',
    run: () => client.archiveOption(12, 7, 9),
    response: { data: { ...option, archived_at: timestamp } },
  },
  {
    label: 'restore option',
    method: 'POST',
    path: '/custom-fields/7/options/9/restore',
    run: () => client.restoreOption(12, 7, 9),
    response: { data: option },
  },
  {
    label: 'list types',
    method: 'GET',
    path: '/note-types',
    run: () => client.noteTypes(12),
    response: { data: [type] },
  },
  {
    label: 'read type',
    method: 'GET',
    path: '/note-types/4',
    run: () => client.noteType(12, 4),
    response: { data: type },
  },
  {
    label: 'create type',
    method: 'POST',
    path: '/note-types',
    body: { name: 'General', template: null },
    run: () => client.createNoteType(12, { name: 'General', template: null }),
    response: { data: type },
  },
  {
    label: 'update type',
    method: 'PATCH',
    path: '/note-types/4',
    body: { description: null, template: '{date}' },
    run: () => client.updateNoteType(12, 4, { description: null, template: '{date}' }),
    response: { data: { ...type, template: '{date}' } },
  },
  {
    label: 'set default',
    method: 'POST',
    path: '/note-types/4/set-default',
    run: () => client.setDefaultNoteType(12, 4),
    response: { data: { ...type, is_default: true } },
  },
  {
    label: 'archive type',
    method: 'POST',
    path: '/note-types/4/archive',
    run: () => client.archiveNoteType(12, 4),
    response: { data: { ...type, archived_at: timestamp } },
  },
  {
    label: 'restore type',
    method: 'POST',
    path: '/note-types/4/restore',
    run: () => client.restoreNoteType(12, 4),
    response: { data: type },
  },
  {
    label: 'list current associations',
    method: 'GET',
    path: '/note-types/4/fields',
    run: () => client.noteTypeFields(12, 4),
    response: { data: [association] },
  },
  {
    label: 'read association history',
    method: 'GET',
    path: '/note-types/4/fields?history=1',
    run: () => client.noteTypeFields(12, 4, true),
    response: {
      data: [
        { ...association, deactivated_at: timestamp },
        { ...association, id: 102 },
      ],
    },
  },
  {
    label: 'attach field',
    method: 'POST',
    path: '/note-types/4/fields',
    body: { field_id: 7, required: false, sort_order: 0 },
    run: () => client.attachField(12, 4, { field_id: 7, required: false, sort_order: 0 }),
    response: { data: association },
  },
  {
    label: 'atomically create and attach',
    method: 'POST',
    path: '/note-types/4/fields/create',
    body: createAndAttach,
    run: () => client.createAndAttachField(12, 4, createAndAttach),
    response: { data: association },
  },
  {
    label: 'update association by field ID',
    method: 'PATCH',
    path: '/note-types/4/fields/7',
    body: { required: false, sort_order: 0 },
    run: () => client.updateNoteTypeField(12, 4, 7, { required: false, sort_order: 0 }),
    response: { data: association },
  },
  {
    label: 'detach association with 204',
    method: 'DELETE',
    path: '/note-types/4/fields/7',
    run: () => client.detachField(12, 4, 7),
  },
  {
    label: 'reorder fields by field IDs',
    method: 'POST',
    path: '/note-types/4/fields/reorder',
    body: { ids: [7, 6] },
    run: () => client.reorderNoteTypeFields(12, 4, { ids: [7, 6] }),
    response: { data: [association] },
  },
]

describe('project note configuration wire contracts', () => {
  it.each(cases)('$label', async ({ method, path, body, run, response }) => {
    fetchMock.mockResolvedValueOnce(
      response === undefined
        ? new Response(null, { status: 204 })
        : new Response(JSON.stringify(response), { status: method === 'POST' ? 201 : 200 }),
    )
    expect(await run()).toEqual(response)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe(`https://jucans.test/api/projects/12${path}`)
    expect(init?.method ?? 'GET').toBe(method)
    expect(init?.body).toBe(body === undefined ? undefined : JSON.stringify(body))
    const headers = new Headers(init?.headers)
    expect(headers.get('Authorization')).toBe('Bearer test-token')
    expect(headers.get('Accept')).toBe('application/json')
    expect(headers.get('Content-Type')).toBe(body === undefined ? null : 'application/json')
  })

  it('preserves DECIMAL strings, nested relationships, booleans and microseconds without conversion', async () => {
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ data: association })))
    const { data } = await client.attachField(12, 4, { field_id: 7 })
    expect(data.id).toBe(101)
    expect(data.field_id).toBe(7)
    expect(data.required).toBe(false)
    expect(data.field.min).toBe('-1.000000')
    expect(data.field.max).toBe('999999999999.123456')
    expect(data.field.step).toBe('0.000001')
    expect(data.activated_at).toBe(timestamp)
    expect(data.deactivated_at).toBeNull()
  })

  it('preserves nullable properties and omitted PATCH properties separately', async () => {
    const nullable = {
      ...field,
      description: null,
      unit: null,
      type: 'text',
      min: null,
      max: null,
      step: null,
      aggregation: null,
      archived_at: null,
      created_at: null,
      updated_at: null,
    }
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ data: nullable })))
    expect((await client.updateField(12, 7, { description: null })).data).toEqual(nullable)
    expect(JSON.parse(fetchMock.mock.calls[0]![1]!.body as string)).toEqual({ description: null })
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ data: type })))
    const result = await client.updateNoteType(12, 4, { template: null })
    expect(result.data.template).toBeNull()
    expect(result.data.is_default).toBe(false)
  })

  it('returns empty unpaginated collections and explicitly false history uses the current list', async () => {
    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ data: [] })))
    expect(await client.noteTypeFields(12, 4, false)).toEqual({ data: [] })
    expect(fetchMock.mock.calls[0]![0]).toBe(
      'https://jucans.test/api/projects/12/note-types/4/fields',
    )
  })

  it.each([401, 403, 404, 409, 422, 500])(
    'propagates HTTP %i with original message and validation details',
    async (status) => {
      const errors = { 'field.min': ['Invalid numeric configuration.'] }
      fetchMock.mockResolvedValueOnce(
        new Response(JSON.stringify({ message: 'Rejected', errors }), { status }),
      )
      const failure = await client
        .createAndAttachField(12, 4, createAndAttach)
        .catch((error: unknown) => error)
      expect(failure).toBeInstanceOf(transport.ApiError)
      expect(failure).toMatchObject({ status, message: 'Rejected', errors })
      expect(fetchMock).toHaveBeenCalledTimes(1) // no hidden retry / partial create request
    },
  )

  it('preserves the shared unauthorized-session callback', async () => {
    const unauthorized = vi.fn()
    transport.setUnauthorizedHandler(unauthorized)
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ message: 'Unauthenticated' }), { status: 401 }),
    )
    await expect(client.fields(12)).rejects.toMatchObject({ status: 401 })
    expect(unauthorized).toHaveBeenCalledTimes(1)
  })

  it('propagates shared network errors without requiring a backend', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Offline'))
    await expect(client.noteTypes(12)).rejects.toMatchObject({ name: 'ApiError', status: 0 })
  })
})

it('preserves nullable and populated read-only first_value_at timestamps', async () => {
  fetchMock.mockResolvedValueOnce(
    new Response(JSON.stringify({ data: { ...field, first_value_at: timestamp } })),
  )
  const response = await client.field(12, 7)
  expect(response.data.first_value_at).toBe(timestamp)
  fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ data: field })))
  expect((await client.field(12, 7)).data.first_value_at).toBeNull()
})
