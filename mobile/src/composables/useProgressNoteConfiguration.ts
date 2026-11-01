import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { projectNoteConfigurationApi as api } from '@/services/projectNoteConfigurationApi'
import type { ProjectNoteType } from '@/types/projectNoteConfiguration'
import type {
  NoteConfigurationField,
  NoteCurrentState,
  NoteFieldEntry,
  NoteFieldValue,
  ProgressNote,
} from '@/types/projectProgress'
import { sameNoteValue, scaledNoteNumber, validateNoteField } from '@/utils/progressNoteFields'

export function useProgressNoteConfiguration(projectId: number, initial?: ProgressNote) {
  const types = ref<ProjectNoteType[]>([])
  const typeId = ref<number | null>(initial?.note_type_id ?? null)
  const fields = ref<NoteConfigurationField[]>(initial?.configuration?.fields ?? [])
  const current = ref<NoteCurrentState | null>(initial?.current_configuration_state ?? null)
  const values = reactive<Record<number, NoteFieldValue>>({})
  const originals = new Map((initial?.values ?? []).map((item) => [item.field_id, item.value]))
  for (const field of fields.value) values[field.field_id] = originals.get(field.field_id) ?? null
  const typesLoading = ref(!initial)
  const fieldsLoading = ref(false)
  const typesError = ref('')
  const fieldsError = ref(
    initial?.note_type_id && !initial.configuration
      ? 'Configurația salvată a notei nu este disponibilă. Redeschide nota după reîncărcarea jurnalului.'
      : '',
  )
  const acceptedLegacy = ref(false)
  const fieldErrors = ref<Record<number, string>>({})
  let initialized = Boolean(initial)
  let sequence = 0
  let disposed = false
  const selectedType = computed(() => types.value.find((type) => type.id === typeId.value))
  const template = computed(() =>
    initial?.note_type_id
      ? (initial.configuration?.note_type.template ?? null)
      : (selectedType.value?.template ?? null),
  )
  const ready = computed(
    () =>
      !typesLoading.value &&
      (!typesError.value || acceptedLegacy.value) &&
      !fieldsLoading.value &&
      !fieldsError.value,
  )
  const hasValues = computed(() => fields.value.some((field) => values[field.field_id] !== null))
  function snapshot() {
    return {
      type_id: typeId.value,
      values: fields.value
        .filter((field) => values[field.field_id] !== null)
        .map((field) => {
          const value = values[field.field_id] ?? null
          return {
            field_id: field.field_id,
            value:
              field.definition.type === 'number' && typeof value === 'string'
                ? (scaledNoteNumber(value)?.toString() ?? value)
                : value,
          }
        }),
    }
  }
  async function selectType(id: number | null): Promise<void> {
    if (initial) return
    const request = ++sequence
    typeId.value = id
    fields.value = []
    current.value = null
    fieldErrors.value = {}
    fieldsError.value = ''
    for (const key of Object.keys(values)) delete values[Number(key)]
    if (id === null) {
      fieldsLoading.value = false
      return
    }
    fieldsLoading.value = true
    try {
      const associations = await api.noteTypeFields(projectId, id)
      const applicable = associations.data.filter(
        (row) => row.field.project_id === projectId && !row.field.archived_at,
      )
      const configured = await Promise.all(
        applicable.map(async (row) => ({
          field_id: row.field_id,
          association_id: row.id,
          required: row.required,
          sort_order: row.sort_order,
          definition: row.field,
          options:
            row.field.type === 'select' ? (await api.options(projectId, row.field_id)).data : [],
        })),
      )
      if (disposed || request !== sequence) return
      fields.value = configured
      current.value = {
        note_type_archived_at: null,
        fields: configured.map((field) => ({
          field_id: field.field_id,
          type: field.definition.type,
          unit: field.definition.unit,
          archived_at: field.definition.archived_at,
          options: field.options.map((option) => ({
            id: option.id,
            archived_at: option.archived_at,
          })),
        })),
      }
      for (const field of configured) values[field.field_id] = null
    } catch (failure) {
      if (!disposed && request === sequence)
        fieldsError.value =
          failure instanceof Error
            ? failure.message
            : 'Nu am putut încărca câmpurile. Încearcă din nou.'
    } finally {
      if (!disposed && request === sequence) fieldsLoading.value = false
    }
  }
  async function loadTypes(): Promise<void> {
    if (initial) return
    typesLoading.value = true
    typesError.value = ''
    try {
      const response = await api.noteTypes(projectId)
      if (disposed) return
      types.value = response.data.filter(
        (type) => type.project_id === projectId && !type.archived_at,
      )
      if (!initialized) {
        initialized = true
        await selectType(types.value.find((type) => type.is_default)?.id ?? null)
      }
    } catch (failure) {
      if (!disposed)
        typesError.value =
          failure instanceof Error ? failure.message : 'Nu am putut încărca tipurile de note.'
    } finally {
      if (!disposed) typesLoading.value = false
    }
  }
  function continueLegacy(): void {
    initialized = true
    acceptedLegacy.value = true
    void selectType(null)
  }
  function entries(): NoteFieldEntry[] {
    if (typeId.value === null) return []
    return fields.value
      .map((field) => ({ field_id: field.field_id, value: values[field.field_id] ?? null }))
      .filter(
        (entry) =>
          !initial ||
          !sameNoteValue(
            fields.value.find((field) => field.field_id === entry.field_id)!,
            entry.value,
            originals.get(entry.field_id) ?? null,
          ),
      )
  }
  function validate(): boolean {
    fieldErrors.value = {}
    if (!ready.value) return false
    for (const field of fields.value) {
      const error = validateNoteField(
        field,
        values[field.field_id] ?? null,
        originals.get(field.field_id) ?? null,
        current.value,
      )
      if (error) fieldErrors.value[field.field_id] = error
    }
    return !Object.keys(fieldErrors.value).length
  }
  function mapErrors(errors: Record<string, string[]>, submitted: NoteFieldEntry[]): void {
    fieldErrors.value = {}
    for (const [path, messages] of Object.entries(errors)) {
      const match = /^values\.(\d+)\.(value|field_id)$/.exec(path)
      const field = match ? submitted[Number(match[1])] : null
      if (field) fieldErrors.value[field.field_id] = messages.join(' ')
    }
  }
  onBeforeUnmount(() => {
    disposed = true
    sequence++
  })
  return {
    types,
    typeId,
    fields,
    current,
    values,
    originals,
    typesLoading,
    fieldsLoading,
    typesError,
    fieldsError,
    fieldErrors,
    selectedType,
    template,
    ready,
    hasValues,
    snapshot,
    selectType,
    loadTypes,
    continueLegacy,
    entries,
    validate,
    mapErrors,
  }
}
