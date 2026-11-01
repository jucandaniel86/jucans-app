<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import ProjectCustomFieldEditor from './ProjectCustomFieldEditor.vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import { projectNoteConfigurationApi as api } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { Project } from '@/types/projects'
import type {
  CreateProjectCustomFieldPayload,
  ProjectCustomField,
  ProjectNoteType,
  ProjectNoteTypeField,
} from '@/types/projectNoteConfiguration'

const props = defineProps<{
  project: Project
  noteType: ProjectNoteType
  readOnly?: boolean
  disabled?: boolean
}>()
const emit = defineEmits<{ changed: []; definitionsChanged: [] }>()
const auth = useAuthStore()
const rows = ref<ProjectNoteTypeField[]>([])
const fields = ref<ProjectCustomField[]>([])
const loading = ref(true)
const loadError = ref('')
const error = ref('')
const libraryError = ref('')
const libraryLoading = ref(false)
const busy = ref(false)
const creating = ref(false)
const picker = ref(false)
const search = ref('')
const required = ref(false)
const pendingField = ref<ProjectCustomField>()
const atomicFieldId = ref<number>()
const unknownAttach = ref(false)
const orderDirty = ref(false)
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const id = useId()
const labels = { number: 'Număr', text: 'Text', boolean: 'Da / Nu', select: 'Select' }
const permitted = computed(
  () =>
    !props.readOnly &&
    props.project.status === 'active' &&
    props.project.created_by === auth.user?.id &&
    props.noteType.project_id === props.project.id &&
    !props.noteType.archived_at,
)
const blocking = computed(() => busy.value || creating.value)
const unsettled = computed(
  () => orderDirty.value || Boolean(pendingField.value) || unknownAttach.value,
)
const locked = computed(
  () =>
    !permitted.value ||
    props.disabled ||
    blocking.value ||
    unsettled.value ||
    loading.value ||
    Boolean(loadError.value),
)
const available = computed(() =>
  fields.value.filter(
    (field) =>
      field.project_id === props.project.id &&
      !field.archived_at &&
      !rows.value.some((row) => row.field_id === field.id) &&
      `${field.name} ${labels[field.type]}`
        .toLocaleLowerCase()
        .includes(search.value.toLocaleLowerCase()),
  ),
)
let sequence = 0
let librarySequence = 0
let disposed = false
function message(failure: unknown): string {
  return failure instanceof ApiError
    ? `${failure.message} ${Object.values(failure.errors).flat().join(' ')}`.trim()
    : 'Operația nu a reușit. Verifică lista și încearcă din nou.'
}
async function load(): Promise<boolean> {
  const request = ++sequence
  loading.value = true
  loadError.value = ''
  try {
    const response = await api.noteTypeFields(props.project.id, props.noteType.id)
    if (disposed || request !== sequence) return false
    rows.value = response.data
    orderDirty.value = false
    unknownAttach.value = false
    return true
  } catch (failure) {
    if (!disposed && request === sequence) loadError.value = message(failure)
    return false
  } finally {
    if (!disposed && request === sequence) loading.value = false
  }
}
async function reload(): Promise<void> {
  if (blocking.value || props.disabled) return
  if (
    orderDirty.value &&
    !(await confirmation.value?.ask({
      title: 'Reîncarci câmpurile?',
      message:
        'Ordinea nesalvată va fi înlocuită cu cea de pe server. Modificările deja salvate se păstrează.',
      cancelText: 'Continuă editarea',
      confirmText: 'Reîncarcă',
    }))
  )
    return
  if (await load()) error.value = ''
}
async function loadLibrary(): Promise<void> {
  const request = ++librarySequence
  libraryLoading.value = true
  libraryError.value = ''
  try {
    const response = await api.fields(props.project.id)
    if (!disposed && request === librarySequence) fields.value = response.data
  } catch (failure) {
    if (!disposed && request === librarySequence) libraryError.value = message(failure)
  } finally {
    if (!disposed && request === librarySequence) libraryLoading.value = false
  }
}
function openPicker(): void {
  if (!locked.value) {
    picker.value = !picker.value
    if (picker.value) void loadLibrary()
  }
}
async function attach(field: ProjectCustomField): Promise<void> {
  if (
    locked.value ||
    field.archived_at ||
    field.project_id !== props.project.id ||
    rows.value.some((row) => row.field_id === field.id)
  )
    return
  busy.value = true
  error.value = ''
  try {
    await api.attachField(props.project.id, props.noteType.id, {
      field_id: field.id,
      required: required.value,
    })
    picker.value = false
    emit('changed')
    await load()
  } catch (failure) {
    error.value = message(failure)
    unknownAttach.value =
      !(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500
  } finally {
    busy.value = false
  }
}
async function toggle(row: ProjectNoteTypeField): Promise<void> {
  if (locked.value) return
  busy.value = true
  error.value = ''
  try {
    const response = await api.updateNoteTypeField(
      props.project.id,
      props.noteType.id,
      row.field_id,
      { required: !row.required },
    )
    rows.value = rows.value.map((item) => (item.field_id === row.field_id ? response.data : item))
    emit('changed')
  } catch (failure) {
    error.value = message(failure)
  } finally {
    busy.value = false
  }
}
async function persistOrder(): Promise<void> {
  if (!permitted.value || props.disabled || blocking.value || !orderDirty.value || loadError.value)
    return
  busy.value = true
  error.value = ''
  try {
    const response = await api.reorderNoteTypeFields(props.project.id, props.noteType.id, {
      ids: rows.value.map((row) => row.field_id),
    })
    rows.value = response.data
    orderDirty.value = false
    emit('changed')
  } catch (failure) {
    error.value = message(failure)
  } finally {
    busy.value = false
  }
}
async function move(index: number, direction: number): Promise<void> {
  if (locked.value || !rows.value[index + direction]) return
  const [row] = rows.value.splice(index, 1)
  rows.value.splice(index + direction, 0, row!)
  orderDirty.value = true
  await persistOrder()
}
async function detach(row: ProjectNoteTypeField): Promise<void> {
  if (locked.value) return
  busy.value = true
  error.value = ''
  try {
    if (
      !(await confirmation.value?.ask({
        title: 'Detașezi câmpul?',
        message:
          'Câmpul este eliminat din acest tip de notă. Rămâne în biblioteca proiectului, iar istoricul asocierii se păstrează.',
        cancelText: 'Anulează',
        confirmText: 'Detașează',
      }))
    )
      return
    if (!permitted.value || disposed) return
    await api.detachField(props.project.id, props.noteType.id, row.field_id)
    emit('changed')
    await load()
  } catch (failure) {
    error.value = message(failure)
  } finally {
    busy.value = false
  }
}
function create(): void {
  if (!locked.value) {
    atomicFieldId.value = undefined
    creating.value = true
  }
}
async function createField(payload: CreateProjectCustomFieldPayload) {
  if (!permitted.value) throw new ApiError('Nu ai permisiunea să asociezi câmpuri.', 403)
  if (payload.type === 'select') return api.createField(props.project.id, payload)
  try {
    const result = await api.createAndAttachField(props.project.id, props.noteType.id, {
      field: payload,
      required: required.value,
    })
    atomicFieldId.value = result.data.field_id
    return { data: result.data.field }
  } catch (failure) {
    if (failure instanceof ApiError)
      throw new ApiError(
        failure.message,
        failure.status,
        Object.fromEntries(
          Object.entries(failure.errors).map(([key, value]) => [
            key.replace(/^field\./, ''),
            value,
          ]),
        ),
      )
    throw failure
  }
}
async function attachPending(): Promise<void> {
  if (!pendingField.value || !permitted.value || blocking.value || props.disabled) return
  busy.value = true
  error.value = ''
  try {
    // Reconcile a possibly committed request before retrying attachment of this stable field ID.
    if (!(await load())) return
    if (!rows.value.some((row) => row.field_id === pendingField.value!.id)) {
      await api.attachField(props.project.id, props.noteType.id, {
        field_id: pendingField.value.id,
        required: required.value,
      })
      emit('changed')
      if (!(await load())) return
    }
    pendingField.value = undefined
  } catch (failure) {
    error.value = message(failure)
  } finally {
    busy.value = false
  }
}
async function created(field: ProjectCustomField): Promise<void> {
  creating.value = false
  picker.value = false
  emit('definitionsChanged')
  if (atomicFieldId.value === field.id) {
    emit('changed')
    await load()
  } else {
    pendingField.value = field
    await attachPending()
  }
}
function definitionChanged(): void {
  emit('definitionsChanged')
}
function cancelCreate(): void {
  creating.value = false
  void load()
  if (picker.value) void loadLibrary()
}
watch(
  () => [props.project.id, props.noteType.id],
  () => {
    rows.value = []
    fields.value = []
    pendingField.value = undefined
    orderDirty.value = false
    unknownAttach.value = false
    picker.value = false
    error.value = ''
    void load()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  disposed = true
  sequence++
  librarySequence++
})
defineExpose({ blocking, unsettled })
</script>
<template>
  <section class="note-type-fields" :aria-labelledby="`${id}-heading`" :aria-busy="loading || busy">
    <header>
      <h3 :id="`${id}-heading`">
        Câmpuri <span v-if="!loading && !loadError">({{ rows.length }})</span>
      </h3>
      <button v-if="permitted" type="button" :disabled="locked" @click="openPicker">
        + Adaugă
      </button>
    </header>
    <p v-if="permitted" class="note-type-fields__hint">
      Asocierile se salvează imediat, separat de datele tipului.
    </p>
    <p v-if="loading" role="status">Se încarcă asocierile…</p>
    <div v-else-if="loadError" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" :disabled="busy" @click="reload">Reîncearcă</button>
    </div>
    <p v-else-if="!rows.length" class="note-type-fields__hint">Niciun câmp asociat.</p>
    <div v-if="error" role="alert">
      <p>{{ error }}</p>
      <button type="button" :disabled="blocking || disabled" @click="reload">
        Reîncarcă lista
      </button>
    </div>
    <div v-if="pendingField" class="note-type-fields__partial" role="status">
      <p>
        Câmpul „{{ pendingField.name }}” (#{{ pendingField.id }}) este creat. Asocierea nu este
        confirmată.
      </p>
      <button
        v-if="permitted"
        type="button"
        :disabled="blocking || disabled"
        @click="attachPending"
      >
        Reîncearcă asocierea
      </button>
    </div>
    <button
      v-if="orderDirty && permitted"
      type="button"
      :disabled="blocking || disabled || Boolean(loadError)"
      @click="persistOrder"
    >
      Reîncearcă ordinea
    </button>
    <ul v-if="!loading && !loadError">
      <li v-for="(row, index) in rows" :key="row.field_id" :data-field-id="row.field_id">
        <div class="note-type-fields__identity">
          <strong>{{ row.field.name }}</strong
          ><span
            >{{ labels[row.field.type]
            }}<template v-if="row.field.archived_at"> · Arhivat</template></span
          >
        </div>
        <div class="note-type-fields__controls">
          <button
            v-if="permitted"
            type="button"
            class="note-type-fields__required"
            :aria-label="`Obligatoriu ${row.field.name}`"
            :aria-pressed="row.required"
            :disabled="locked"
            @click="toggle(row)"
          >
            {{ row.required ? 'Obligatoriu' : 'Opțional' }}
          </button>
          <span v-else class="note-type-fields__hint">{{
            row.required ? 'Obligatoriu' : 'Opțional'
          }}</span>
          <template v-if="permitted"
            ><button
              type="button"
              :aria-label="`Mută în sus ${row.field.name}`"
              :disabled="locked || index === 0"
              @click="move(index, -1)"
            >
              ↑</button
            ><button
              type="button"
              :aria-label="`Mută în jos ${row.field.name}`"
              :disabled="locked || index === rows.length - 1"
              @click="move(index, 1)"
            >
              ↓</button
            ><button
              type="button"
              :aria-label="`Detașează ${row.field.name}`"
              :disabled="locked"
              @click="detach(row)"
            >
              ×
            </button></template
          >
        </div>
      </li>
    </ul>
    <div v-if="picker && permitted" class="note-type-fields__picker">
      <div class="note-type-fields__picker-heading">
        <label><input v-model="required" type="checkbox" :disabled="locked" /> Obligatoriu</label
        ><button type="button" :disabled="locked" @click="create">Creează câmp nou</button>
      </div>
      <label :for="`${id}-search`">Caută în bibliotecă</label
      ><input :id="`${id}-search`" v-model="search" type="search" :disabled="locked" />
      <p v-if="libraryLoading" role="status">Se încarcă biblioteca…</p>
      <div v-else-if="libraryError" role="alert">
        <p>{{ libraryError }}</p>
        <button type="button" @click="loadLibrary">Reîncearcă biblioteca</button>
      </div>
      <p v-else-if="!available.length" class="note-type-fields__hint">Niciun câmp disponibil.</p>
      <ul v-else>
        <li v-for="field in available" :key="field.id">
          <span class="note-type-fields__identity"
            >{{ field.name }}<small>{{ labels[field.type] }}</small></span
          ><button
            type="button"
            :aria-label="`Asociază ${field.name}`"
            :disabled="locked"
            @click="attach(field)"
          >
            Asociază
          </button>
        </li>
      </ul>
    </div>
    <ProjectCustomFieldEditor
      v-if="creating"
      :project="project"
      :create-field="createField"
      @saved="created"
      @cancel="cancelCreate"
      @changed="definitionChanged"
    />
    <ProgressNoteConfirmDialog
      v-if="permitted"
      ref="confirmation"
      class="note-type-fields__confirm"
    />
  </section>
</template>
<style scoped>
.note-type-fields {
  display: grid;
  min-width: 0;
  gap: 8px;
  margin-top: 16px;
  text-align: left;
}
.note-type-fields header,
.note-type-fields__picker-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 4px 8px;
}
.note-type-fields h3 {
  margin: 0;
  font-size: 0.9rem;
}
.note-type-fields p {
  margin: 0;
  overflow-wrap: anywhere;
  font-size: 0.8rem;
}
.note-type-fields__hint,
.note-type-fields__identity span,
.note-type-fields small {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}
.note-type-fields ul {
  display: grid;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.note-type-fields li {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 4px 8px;
  min-width: 0;
  padding-block: 4px;
  border-bottom: 1px solid var(--color-border);
}
.note-type-fields__identity {
  display: grid;
  flex: 1;
  gap: 2px;
  min-width: 90px;
  font-size: 0.82rem;
  overflow-wrap: anywhere;
}
.note-type-fields__controls {
  display: flex;
  align-items: center;
  gap: 2px;
}
.note-type-fields button {
  min-width: 44px;
  min-height: 44px;
  padding: 6px 8px;
  border: 0;
  border-radius: var(--radius-sm);
  font: inherit;
  font-size: 0.78rem;
  background: transparent;
  color: var(--color-primary-strong);
  cursor: pointer;
}
.note-type-fields button:disabled {
  opacity: 0.45;
  cursor: default;
}
.note-type-fields__required[aria-pressed='true'] {
  background: var(--color-primary-soft);
}
.note-type-fields__picker {
  display: grid;
  gap: 6px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border);
}
.note-type-fields__picker label {
  display: flex;
  align-items: center;
  min-height: 44px;
  gap: 6px;
  font-size: 0.8rem;
}
.note-type-fields__picker input[type='checkbox'] {
  width: 20px;
  height: 20px;
  min-height: 0;
}
.note-type-fields__picker input[type='search'] {
  box-sizing: border-box;
  width: 100%;
  min-height: 44px;
  font: inherit;
  font-size: 16px;
  padding: 8px 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text);
}
.note-type-fields [role='alert'] {
  color: var(--color-error);
}
.note-type-fields__partial {
  padding: 8px;
  background: var(--color-surface-soft);
  border-radius: var(--radius-sm);
}
.note-type-fields__confirm {
  box-sizing: border-box;
  width: min(400px, calc(100% - 24px));
  max-height: 90dvh;
  overflow-y: auto;
  margin: auto;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.note-type-fields__confirm::backdrop {
  background: var(--color-overlay);
}
.note-type-fields__confirm :deep(h3) {
  margin: 0 0 8px;
  font-size: 1rem;
}
.note-type-fields__confirm :deep(p) {
  margin: 0 0 12px;
  font-size: 0.85rem;
  line-height: 1.4;
}
.note-type-fields__confirm :deep(.note-editor-confirm__actions) {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}
</style>
