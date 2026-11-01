import { expect, it } from 'vitest'
import {
  displayNoteValue,
  sameNoteValue,
  scaledNoteNumber,
  validateNoteField,
} from './progressNoteFields'
import type { NoteConfigurationField, NoteCurrentState } from '@/types/projectProgress'
const field: NoteConfigurationField = {
  field_id: 1,
  association_id: 5,
  required: true,
  sort_order: 0,
  definition: {
    id: 1,
    project_id: 1,
    key: 'number',
    name: 'Number',
    description: null,
    type: 'number',
    unit: 'points',
    min: '-0.250000',
    max: '999999999999.123456',
    step: '0.500000',
    aggregation: 'average',
    archived_at: null,
  },
  options: [],
}
const current: NoteCurrentState = {
  note_type_archived_at: null,
  fields: [{ field_id: 1, type: 'number', unit: 'points', archived_at: null, options: [] }],
}
it('uses scaled integers for exact bounds, negative origins, six-place precision and step', () => {
  expect(scaledNoteNumber('999999999999.123456')).toBe(999999999999123456n)
  expect(scaledNoteNumber('0.0000001')).toBeNull()
  expect(scaledNoteNumber('1e2')).toBeNull()
  expect(validateNoteField(field, '0.250000', null, current)).toBe('')
  expect(validateNoteField(field, '0.000000', null, current)).toContain('pasul')
  expect(validateNoteField(field, '-0.750000', null, current)).toContain('limitelor')
})
it('canonical decimal comparisons preserve unchanged historical values', () => {
  expect(sameNoteValue(field, '0', '0.000000')).toBe(true)
  expect(sameNoteValue(field, '-0.000000', '0.000000')).toBe(true)
  expect(sameNoteValue(field, '999999999999.123455', '999999999999.123456')).toBe(false)
})
it('distinguishes required false/zero, null and whitespace-only required text', () => {
  const boolean = {
    ...field,
    definition: { ...field.definition, type: 'boolean' as const, unit: null },
  }
  const live = {
    note_type_archived_at: null,
    fields: [{ field_id: 1, type: 'boolean' as const, unit: null, archived_at: null, options: [] }],
  }
  expect(validateNoteField(boolean, false, null, live)).toBe('')
  expect(validateNoteField(boolean, null, null, live)).toContain('obligatoriu')
  const numeric = { ...field, definition: { ...field.definition, min: '0.000000', step: null } }
  expect(validateNoteField(numeric, '0', null, current)).toBe('')
  const text = { ...boolean, definition: { ...boolean.definition, type: 'text' as const } }
  expect(
    validateNoteField(text, ' \n', null, {
      ...live,
      fields: [{ ...live.fields[0]!, type: 'text' }],
    }),
  ).toContain('obligatoriu')
})
it('never validates unchanged archived values against mutable current settings', () => {
  const archived = {
    ...current,
    fields: [{ ...current.fields[0]!, archived_at: '2026-10-31', unit: 'new-unit' }],
  }
  expect(validateNoteField(field, '0.250000', '0.250000', archived)).toBe('')
  expect(validateNoteField(field, '0.750000', '0.250000', archived)).toContain('arhivat')
  expect(validateNoteField({ ...field, required: false }, null, '0.250000', archived)).toBe('')
})
it('displays false, exact numbers and unset values without truthiness loss', () => {
  expect(displayNoteValue(field, '0.000000')).toBe('0.000000')
  expect(
    displayNoteValue({ ...field, definition: { ...field.definition, type: 'boolean' } }, false),
  ).toBe('Nu')
  expect(displayNoteValue(field, null)).toBe('Nesetat')
})

it('matches backend Unicode character limits without counting surrogate pairs twice', () => {
  const text = {
    ...field,
    required: false,
    definition: { ...field.definition, type: 'text' as const, unit: null },
  }
  const live = {
    note_type_archived_at: null,
    fields: [{ field_id: 1, type: 'text' as const, unit: null, archived_at: null, options: [] }],
  }
  expect(validateNoteField(text, '😀'.repeat(50001), null, live)).toBe('')
  expect(validateNoteField(text, 'a'.repeat(100001), null, live)).toContain('100.000')
})
