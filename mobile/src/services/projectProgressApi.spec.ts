import actualResponse from '../../../server/docs/examples/note-values-create.response.json'
import { afterEach, expect, it, vi } from 'vitest'
import { projectProgressApi } from './projectProgressApi'
import type { NotePayload, ProgressNote } from '@/types/projectProgress'
const response = actualResponse as { data: ProgressNote }
afterEach(() => vi.unstubAllGlobals())
it('serializes typed creation exactly and maps the real Phase 2 response without decimal or Boolean conversion', async () => {
  const fetch = vi.fn().mockResolvedValue(
    new Response(JSON.stringify(response), {
      status: 201,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
  vi.stubGlobal('fetch', fetch)
  const payload: NotePayload = {
    title: 'Observation',
    content: 'Text',
    noted_at: '2020-01-01',
    note_type_id: 1,
    values: [
      { field_id: 1, value: '999999999999.123456' },
      { field_id: 2, value: '  Text\n' },
      { field_id: 3, value: false },
      { field_id: 4, value: 1 },
    ],
  }
  const result = await projectProgressApi.createNote(1, payload)
  expect(fetch.mock.calls[0]![0]).toMatch(/\/projects\/1\/notes$/)
  expect(fetch.mock.calls[0]![1].method).toBe('POST')
  expect(JSON.parse(fetch.mock.calls[0]![1].body)).toEqual(payload)
  expect(result.data.values?.find((row) => row.field_id === 1)?.value).toBe('0.000000')
  expect(result.data.values?.find((row) => row.field_id === 3)?.value).toBe(false)
  expect(result.data.configuration?.fields[0]?.definition.step).toBe('0.500000')
  expect(result.data.configuration_captured_at).toBe('2026-10-31T12:00:00.123456Z')
})
it('sends partial merge entries and explicit null without manufacturing omitted values', async () => {
  const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify(response)))
  vi.stubGlobal('fetch', fetch)
  const payload: NotePayload = {
    title: 'Observation',
    content: 'Text',
    noted_at: '2020-01-01',
    values: [{ field_id: 2, value: null }],
  }
  await projectProgressApi.updateNote(1, 1, payload)
  expect(fetch.mock.calls[0]![0]).toMatch(/\/projects\/1\/notes\/1$/)
  expect(fetch.mock.calls[0]![1].method).toBe('PATCH')
  expect(JSON.parse(fetch.mock.calls[0]![1].body)).toEqual(payload)
})
it('preserves legacy nullable configuration and empty values', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            ...response.data,
            note_type_id: null,
            configuration: null,
            configuration_captured_at: null,
            current_configuration_state: null,
            values: [],
          },
        }),
      ),
    ),
  )
  const result = await projectProgressApi.note(1, 1)
  expect(result.data.configuration).toBeNull()
  expect(result.data.values).toEqual([])
})
it('propagates value validation paths and conflict errors', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          message: 'Conflict',
          errors: { 'values.0.value': ['Archived option'] },
        }),
        { status: 422 },
      ),
    ),
  )
  await expect(
    projectProgressApi.updateNote(1, 1, { title: 'Note', content: 'Text', noted_at: '2020-01-01' }),
  ).rejects.toMatchObject({ status: 422, errors: { 'values.0.value': ['Archived option'] } })
})
