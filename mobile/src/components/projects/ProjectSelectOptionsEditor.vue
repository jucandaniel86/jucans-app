<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { ProjectCustomField, ProjectCustomFieldOption } from '@/types/projectNoteConfiguration'

const props = defineProps<{
  projectId: number
  field?: ProjectCustomField
  disabled?: boolean
  inspect?: boolean
}>()
interface Draft {
  local: number
  id?: number
  label: string
  originalLabel: string
  archived: boolean
  originalArchived: boolean
  error: string
}
const rows = ref<Draft[]>([])
const loading = ref(Boolean(props.field?.type === 'select'))
const loadError = ref('')
const validationError = ref('')
const syncError = ref('')
const unknownCreate = ref(false)
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const id = useId()
const persistedId = ref(props.field?.type === 'select' ? props.field.id : undefined)
const ready = computed(() => !loading.value && !loadError.value)
const hasHistory = computed(() => rows.value.some((row) => row.id !== undefined))
const readOnly = computed(() =>
  Boolean(
    props.inspect ||
    props.disabled ||
    props.field?.archived_at ||
    unknownCreate.value ||
    loading.value ||
    loadError.value,
  ),
)
const order = ref<number[]>([])
const baseline = ref('[]')
let local = 0
let disposed = false
function snapshot(): string {
  return JSON.stringify(
    rows.value.map((row) => ({ id: row.id, label: row.label, archived: row.archived })),
  )
}
const dirty = computed(() => unknownCreate.value || snapshot() !== baseline.value)
function replace(options: ProjectCustomFieldOption[]): void {
  rows.value = options.map((option) => ({
    local: ++local,
    id: option.id,
    label: option.label,
    originalLabel: option.label,
    archived: option.archived_at !== null,
    originalArchived: option.archived_at !== null,
    error: '',
  }))
  order.value = options.map((option) => option.id)
  baseline.value = snapshot()
}
async function load(): Promise<void> {
  if (persistedId.value === undefined) return
  loading.value = true
  loadError.value = ''
  try {
    const response = await projectNoteConfigurationApi.options(props.projectId, persistedId.value)
    if (!disposed) {
      replace(response.data)
      unknownCreate.value = false
      syncError.value = ''
      validationError.value = ''
    }
  } catch (failure) {
    if (!disposed)
      loadError.value =
        failure instanceof Error ? failure.message : 'Nu am putut încărca opțiunile.'
    throw failure
  } finally {
    if (!disposed) loading.value = false
  }
}
async function reload(): Promise<void> {
  if (
    dirty.value &&
    !(await confirmation.value?.ask({
      title: 'Reîncarci opțiunile?',
      message:
        'Modificările nesalvate ale opțiunilor vor fi înlocuite cu lista de pe server. Verifică opțiunile existente înainte de a adăuga din nou una cu rezultat neconfirmat.',
      cancelText: 'Continuă editarea',
      confirmText: 'Reîncarcă',
    }))
  )
    return
  try {
    await load()
  } catch {
    /* Error remains visible; keep the existing draft. */
  }
}
function add(): void {
  if (!readOnly.value)
    rows.value.push({
      local: ++local,
      label: '',
      originalLabel: '',
      archived: false,
      originalArchived: false,
      error: '',
    })
}
function remove(index: number): void {
  if (!readOnly.value && rows.value[index]?.id === undefined) rows.value.splice(index, 1)
}
function move(index: number, direction: number): void {
  if (readOnly.value || index + direction < 0 || index + direction >= rows.value.length) return
  const [row] = rows.value.splice(index, 1)
  rows.value.splice(index + direction, 0, row!)
}
async function toggleArchive(row: Draft): Promise<void> {
  if (readOnly.value || row.id === undefined) return
  if (
    !row.archived &&
    !(await confirmation.value?.ask({
      title: 'Arhivezi opțiunea?',
      message:
        'Opțiunea nu va mai fi disponibilă pentru valori noi. Identitatea și istoricul se păstrează.',
      confirmText: 'Arhivează la salvare',
      cancelText: 'Anulează',
    }))
  )
    return
  if (!readOnly.value) row.archived = !row.archived
}
function validate(): boolean {
  validationError.value = ''
  if (!ready.value || unknownCreate.value) return false
  if (props.field?.archived_at) return true
  let valid = true
  for (const row of rows.value) {
    row.error = !row.label.trim()
      ? 'Completează eticheta.'
      : row.label.trim().length > 255
        ? 'Maximum 255 de caractere.'
        : ''
    if (row.error) valid = false
  }
  if (!props.field && rows.value.length === 0) {
    validationError.value = 'Adaugă cel puțin o opțiune.'
    return false
  }
  return valid
}
async function save(fieldId: number): Promise<void> {
  persistedId.value = fieldId
  if (props.field?.archived_at) return
  if (!validate())
    throw new Error(
      unknownCreate.value
        ? 'O opțiune are creare neconfirmată. Reîncarcă și verifică lista înainte de a continua.'
        : 'Verifică opțiunile câmpului.',
    )
  for (const [position, row] of rows.value.entries()) {
    row.error = ''
    try {
      if (row.id === undefined) {
        const result = await projectNoteConfigurationApi.createOption(props.projectId, fieldId, {
          label: row.label.trim(),
          sort_order: position,
        })
        row.id = result.data.id
        row.originalLabel = result.data.label
        row.originalArchived = result.data.archived_at !== null
      } else if (row.label.trim() !== row.originalLabel) {
        const result = await projectNoteConfigurationApi.updateOption(
          props.projectId,
          fieldId,
          row.id,
          { label: row.label.trim() },
        )
        row.originalLabel = result.data.label
      }
      if (row.archived !== row.originalArchived) {
        const result = row.archived
          ? await projectNoteConfigurationApi.archiveOption(props.projectId, fieldId, row.id!)
          : await projectNoteConfigurationApi.restoreOption(props.projectId, fieldId, row.id!)
        row.originalArchived = result.data.archived_at !== null
      }
    } catch (failure) {
      if (
        row.id === undefined &&
        (!(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500)
      )
        unknownCreate.value = true
      row.error =
        failure instanceof ApiError
          ? (failure.errors.label?.[0] ?? failure.message)
          : 'Opțiunea nu a fost salvată.'
      throw failure
    }
  }
  const ids = rows.value.map((row) => row.id!)
  if (JSON.stringify(ids) !== JSON.stringify(order.value)) {
    // The backend rejects incomplete lists, including IDs added by another writer.
    try {
      await projectNoteConfigurationApi.reorderOptions(props.projectId, fieldId, { ids })
      order.value = [...ids]
      syncError.value = ''
    } catch (failure) {
      syncError.value = failure instanceof Error ? failure.message : 'Ordinea nu a fost salvată.'
      throw failure
    }
  }
  await load()
}
onMounted(() => {
  if (persistedId.value !== undefined) void load().catch(() => {})
})
onBeforeUnmount(() => {
  disposed = true
})
defineExpose({ save, validate, dirty, ready, hasHistory, unknownCreate })
</script>

<template>
  <section class="select-options" aria-label="Opțiuni Select" :aria-busy="loading">
    <div class="select-options__heading">
      <h3>Opțiuni</h3>
      <button
        v-if="!field?.archived_at && !inspect"
        type="button"
        :disabled="readOnly"
        @click="add"
      >
        + Opțiune
      </button>
    </div>
    <p v-if="loading" class="select-options__hint" role="status">Se încarcă opțiunile…</p>
    <p v-if="field?.archived_at && !inspect" class="select-options__hint">
      Restaurează câmpul pentru a modifica opțiunile.
    </p>
    <p v-if="unknownCreate" class="select-options__error" role="alert">
      Crearea unei opțiuni nu este confirmată. Reîncarcă și verifică lista; nu retrimitem automat
      crearea.
    </p>
    <p v-if="syncError" class="select-options__error" role="alert">{{ syncError }}</p>
    <p v-if="validationError" class="select-options__error" role="alert">{{ validationError }}</p>
    <div v-if="loadError" class="select-options__error" role="alert">{{ loadError }}</div>
    <button
      v-if="(loadError || unknownCreate || syncError) && persistedId !== undefined"
      type="button"
      :disabled="loading || (disabled && !inspect)"
      @click="reload"
    >
      Reîncarcă opțiunile
    </button>
    <fieldset class="select-options__list" :disabled="readOnly">
      <div
        v-for="(row, index) in rows"
        :key="row.local"
        class="select-option"
        :class="{ 'select-option--archived': row.archived }"
      >
        <div class="select-option__label">
          <label class="select-options__sr" :for="`${id}-${row.local}`"
            >Opțiunea {{ index + 1 }}</label
          >
          <span v-if="inspect" class="select-options__value">{{ row.label }}</span>
          <input
            v-else
            :id="`${id}-${row.local}`"
            v-model="row.label"
            :maxlength="255"
            :aria-invalid="Boolean(row.error)"
            :aria-describedby="row.error ? `${id}-${row.local}-error` : undefined"
          />
          <span v-if="row.archived" class="select-options__hint">{{
            row.originalArchived ? 'Arhivată' : 'Se arhivează la salvare'
          }}</span>
          <span v-else-if="row.originalArchived" class="select-options__hint"
            >Se restaurează la salvare</span
          >
          <p v-if="row.error" :id="`${id}-${row.local}-error`" class="select-options__error">
            {{ row.error }}
          </p>
        </div>
        <div v-if="!inspect" class="select-option__actions">
          <button
            type="button"
            :disabled="index === 0"
            :aria-label="`Mută opțiunea ${index + 1} în sus`"
            @click="move(index, -1)"
          >
            ↑
          </button>
          <button
            type="button"
            :disabled="index === rows.length - 1"
            :aria-label="`Mută opțiunea ${index + 1} în jos`"
            @click="move(index, 1)"
          >
            ↓
          </button>
          <button
            v-if="row.id === undefined"
            type="button"
            :aria-label="`Elimină opțiunea ${index + 1} nesalvată`"
            @click="remove(index)"
          >
            ×
          </button>
          <button
            v-else
            type="button"
            :aria-label="`${row.archived ? 'Restaurează' : 'Arhivează'} opțiunea ${index + 1}`"
            @click="toggleArchive(row)"
          >
            <svg
              v-if="row.archived"
              viewBox="0 0 24 24"
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              aria-hidden="true"
            >
              <path d="M3 11a8 8 0 1 1 2 7M3 5v6h6" /></svg
            ><svg
              v-else
              viewBox="0 0 24 24"
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              aria-hidden="true"
            >
              <path d="M3 4h18v4H3zM5 8v12h14V8M10 12h4" />
            </svg>
          </button>
        </div>
      </div>
    </fieldset>
    <ProgressNoteConfirmDialog v-if="!inspect" ref="confirmation" />
  </section>
</template>

<style scoped>
.select-options {
  display: grid;
  gap: 6px;
  min-width: 0;
}
.select-options__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.select-options h3 {
  margin: 0;
  font-size: 0.85rem;
}
.select-options button {
  min-height: 44px;
  padding: 4px 8px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--color-primary-strong);
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
}
.select-options button:disabled {
  opacity: 0.4;
  cursor: default;
}
.select-options__list {
  display: grid;
  gap: 8px;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}
.select-option {
  display: flex;
  align-items: start;
  gap: 4px;
  min-width: 0;
}
.select-option__label {
  flex: 1;
  display: grid;
  gap: 3px;
  min-width: 0;
}
.select-option input {
  box-sizing: border-box;
  width: 100%;
  min-width: 0;
  min-height: 44px;
  padding: 8px;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 16px;
}
.select-option input[aria-invalid='true'] {
  border-color: var(--color-error);
}
.select-option--archived input {
  background: var(--color-surface-soft);
}
.select-option__actions {
  display: flex;
  flex: none;
}
.select-option__actions button {
  width: 44px;
  display: grid;
  place-items: center;
  font-size: 1rem;
}
.select-options__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.72rem;
  line-height: 1.4;
}
.select-options__error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.75rem;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
.select-options__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
}
</style>

<style scoped>
.select-options__value {
  overflow-wrap: anywhere;
  font-size: 0.82rem;
  line-height: 1.5;
}
</style>
