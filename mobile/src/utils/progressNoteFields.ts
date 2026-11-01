import type {
  NoteConfigurationField,
  NoteCurrentState,
  NoteFieldValue,
} from '@/types/projectProgress'

export function scaledNoteNumber(value: string): bigint | null {
  if (!/^-?\d{1,12}(\.\d{1,6})?$/.test(value)) return null
  const [whole, fraction = ''] = value.replace(/^-/, '').split('.')
  const magnitude = BigInt(`${whole}${fraction.padEnd(6, '0')}`)
  return value.startsWith('-') ? -magnitude : magnitude
}
export function sameNoteValue(
  field: NoteConfigurationField,
  a: NoteFieldValue,
  b: NoteFieldValue,
): boolean {
  if (field.definition.type === 'number' && typeof a === 'string' && typeof b === 'string') {
    const scaled = scaledNoteNumber(a)
    return scaled !== null && scaled === scaledNoteNumber(b)
  }
  return a === b
}
export function fieldRestriction(
  field: NoteConfigurationField,
  current: NoteCurrentState | null | undefined,
): string {
  const live = current?.fields.find((item) => item.field_id === field.field_id)
  if (!live) return 'Starea câmpului nu este disponibilă. Valoarea salvată se păstrează.'
  if (live.archived_at) return 'Câmp arhivat · valoarea salvată se păstrează.'
  if (live.type !== field.definition.type)
    return 'Tipul câmpului s-a schimbat. Valoarea salvată se păstrează.'
  if (live.unit !== field.definition.unit)
    return `Unitate curentă: ${live.unit ?? 'fără unitate'}. Valoarea salvată se păstrează.`
  return ''
}
export function optionUnavailable(
  field: NoteConfigurationField,
  option: number,
  current: NoteCurrentState | null | undefined,
): boolean {
  const live = current?.fields
    .find((item) => item.field_id === field.field_id)
    ?.options.find((item) => item.id === option)
  return !live || live.archived_at !== null
}
export function validateNoteField(
  field: NoteConfigurationField,
  value: NoteFieldValue,
  original: NoteFieldValue,
  current: NoteCurrentState | null | undefined,
): string {
  if (value === null) return field.required ? 'Completează câmpul obligatoriu.' : ''
  if (sameNoteValue(field, value, original)) return ''
  const restriction = fieldRestriction(field, current)
  if (restriction) return restriction
  switch (field.definition.type) {
    case 'number': {
      const number = typeof value === 'string' ? scaledNoteNumber(value) : null
      if (number === null)
        return 'Folosește maximum 12 cifre întregi și 6 zecimale, cu separatorul „.”.'
      const min = field.definition.min === null ? null : scaledNoteNumber(field.definition.min)
      const max = field.definition.max === null ? null : scaledNoteNumber(field.definition.max)
      const step = field.definition.step === null ? null : scaledNoteNumber(field.definition.step)
      if ((min !== null && number < min) || (max !== null && number > max))
        return 'Valoarea este în afara limitelor afișate.'
      if (step !== null && step > 0n && (number - (min ?? 0n)) % step !== 0n)
        return 'Valoarea nu respectă pasul afișat.'
      return ''
    }
    case 'boolean':
      return typeof value === 'boolean' ? '' : 'Alege Da sau Nu.'
    case 'text':
      return typeof value !== 'string'
        ? 'Introdu text.'
        : field.required && !value.trim()
          ? 'Completează câmpul obligatoriu.'
          : Array.from(value).length > 100000
            ? 'Maximum 100.000 de caractere.'
            : ''
    case 'select':
      return typeof value !== 'number' ||
        !field.options.some((item) => item.id === value) ||
        optionUnavailable(field, value, current)
        ? 'Alege o opțiune disponibilă.'
        : ''
  }
}
export function displayNoteValue(field: NoteConfigurationField, value: NoteFieldValue): string {
  if (value === null) return 'Nesetat'
  if (field.definition.type === 'boolean') return value === false ? 'Nu' : 'Da'
  if (field.definition.type === 'select')
    return field.options.find((option) => option.id === value)?.label ?? 'Opțiune indisponibilă'
  return String(value)
}
