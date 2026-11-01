<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue'
import AppInput from '@/components/ui/AppInput.vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import ProgressNoteFieldValues from './ProgressNoteFieldValues.vue'
import { useProgressNoteConfiguration } from '@/composables/useProgressNoteConfiguration'
import ProjectTaskPicker from '@/components/projects/ProjectTaskPicker.vue'
import { ApiError } from '@/services/api'
import { projectProgressApi } from '@/services/projectProgressApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type { NoteFieldEntry, NoteTask, ProgressNote } from '@/types/projectProgress'
import {
  notePayload,
  progressError,
  saveNoteAndScores,
  type NoteSaveState,
  type ScoreIntent,
} from '@/utils/projectProgress'
import { today } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    project: Project
    note?: ProgressNote
    initialTasks?: NoteTask[]
    initialDate?: string
    timezone?: string
  }>(),
  { timezone: 'UTC', initialTasks: () => [] },
)
const emit = defineEmits<{
  saved: []
  changed: []
  cancel: []
  busy: [value: boolean]
  accessChanged: []
}>()
const auth = useAuthStore()
const notifications = useNotificationStore()
const initial = props.note
const configuration = useProgressNoteConfiguration(props.project.id, initial)
const {
  typeId,
  types: noteTypes,
  fields: customFields,
  current: currentConfiguration,
  values: customValues,
  originals: originalValues,
  fieldErrors: customErrors,
  typesLoading,
  fieldsLoading,
  typesError,
  fieldsError,
} = configuration
const typeSwitching = ref(false)
const contentUserEdited = ref(false)
const form = reactive({
  title: initial?.title ?? '',
  content: initial?.content ?? '',
  noted_at: initial?.noted_at ?? props.initialDate ?? today(props.timezone),
})
const selected = ref<NoteTask[]>([...(initial?.tasks ?? props.initialTasks)])
const choices = reactive<Record<number, { score: string | number; update: boolean }>>({})
const state = reactive<NoteSaveState>({ note: null, noteSaved: false, finished: new Set<number>() })
const busy = ref(false)
const unknownCreation = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const preview = ref<string | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const formElement = ref<HTMLFormElement | null>(null)
const canManage = computed(
  () =>
    props.project.status === 'active' &&
    (!initial ||
      initial.created_by === auth.user?.id ||
      props.project.created_by === auth.user?.id),
)
const frozen = computed(
  () => busy.value || state.noteSaved || unknownCreation.value || !canManage.value,
)
const evaluationsOpen = ref(Boolean(initial?.evaluations.length))
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const observationId = useId()
const dateId = useId()
const completed = ref(false)
let disposed = false
let previewRequest = 0
let contentRevision = 0
let autoTemplatePending = !initial
watch(
  () => form.content,
  () => {
    contentRevision++
    autoTemplatePending = false
  },
  { flush: 'sync' },
)
const deletedLinks = computed(() => selected.value.some((task) => task.deleted_at !== null))
const existing = (task: number) =>
  initial?.evaluations.find(
    (value) =>
      value.task_id === task &&
      value.evaluation_date === form.noted_at &&
      value.is_active &&
      !value.withdrawn_at,
  )
const canEditScore = (task: number) => {
  const value = existing(task)
  return value && (value.created_by === auth.user?.id || props.project.created_by === auth.user?.id)
}
function initialize(reset = false): void {
  for (const task of selected.value)
    if (reset || !choices[task.id])
      choices[task.id] = {
        score: existing(task.id) ? String(existing(task.id)!.score) : '',
        update: false,
      }
}
watch(selected, () => initialize(), { immediate: true })
watch(
  () => form.noted_at,
  () => initialize(true),
)
const previewContext = computed(() =>
  JSON.stringify({
    date: form.noted_at,
    note_type_id: typeId.value,
    tasks: selected.value.filter((task) => !task.deleted_at).map((task) => task.id),
  }),
)
function snapshot(): string {
  return JSON.stringify({
    ...form,
    configuration: configuration.snapshot(),
    task_ids: selected.value.map((task) => task.id).sort((a, b) => a - b),
    evaluations: selected.value
      .filter((task) => !task.deleted_at)
      .map((task) => ({
        id: task.id,
        score: String(choices[task.id]?.score ?? ''),
        update: choices[task.id]?.update ?? false,
      }))
      .sort((a, b) => a.id - b.id),
  })
}
const baseline = ref(snapshot())
watch(
  typeId,
  (id) => {
    if (!initial && typesLoading.value && !typeSwitching.value)
      baseline.value = JSON.stringify({
        ...JSON.parse(baseline.value),
        configuration: { type_id: id, values: [] },
      })
  },
  { flush: 'sync' },
)
const dirty = computed(
  () =>
    !completed.value && (unknownCreation.value || state.noteSaved || snapshot() !== baseline.value),
)
const saveLabel = computed(() => (state.noteSaved ? 'Reîncearcă evaluările' : 'Salvează nota'))
const saveDisabled = computed(
  () =>
    unknownCreation.value ||
    !canManage.value ||
    typeSwitching.value ||
    (!state.noteSaved && !configuration.ready.value),
)
const closeMessage = computed(() =>
  unknownCreation.value
    ? 'Crearea notei nu este confirmată. Verifică jurnalul înainte de a încerca din nou.'
    : state.noteSaved
      ? 'Nota este deja salvată. Evaluările nesalvate vor fi abandonate.'
      : 'Modificările nesalvate vor fi pierdute.',
)
const evaluationCount = computed(
  () => initial?.evaluations.filter((value) => value.is_active && !value.withdrawn_at).length ?? 0,
)
watch(previewContext, () => {
  preview.value = null
  previewError.value = ''
  if (autoTemplatePending && configuration.ready.value) void applyInitialTemplate()
})
async function loadPreview(showPreview = true): Promise<string | null> {
  if (frozen.value) return null
  const request = ++previewRequest
  const context = previewContext.value
  previewLoading.value = true
  previewError.value = ''
  try {
    const response =
      typeId.value !== null
        ? { data: { content: configuration.template.value ?? '' } }
        : await projectProgressApi.preview(
            props.project.id,
            form.noted_at,
            selected.value.filter((task) => !task.deleted_at).map((task) => task.id),
          )
    if (!disposed && request === previewRequest && context === previewContext.value) {
      if (showPreview || !response.data.content) preview.value = response.data.content
      return response.data.content
    }
  } catch (failure) {
    if (!disposed && request === previewRequest && context === previewContext.value)
      previewError.value = progressError(failure)
  } finally {
    if (!disposed && request === previewRequest) previewLoading.value = false
  }
  return null
}
async function applyInitialTemplate(): Promise<void> {
  const revision = contentRevision
  const content = await loadPreview(false)
  if (
    content === null ||
    disposed ||
    frozen.value ||
    contentRevision !== revision ||
    !autoTemplatePending
  )
    return
  autoTemplatePending = false
  form.content = content
  // Generated defaults are pristine. Preserve any title/date/task changes made while loading.
  baseline.value = JSON.stringify({ ...JSON.parse(baseline.value), content })
}
async function applyPreview(): Promise<void> {
  autoTemplatePending = false
  const revision = contentRevision
  const context = previewContext.value
  const content = await loadPreview(false)
  if (!content || disposed || frozen.value || contentRevision !== revision) return
  if (
    form.content.trim() &&
    !(await confirmation.value?.ask({
      title: 'Aplici template-ul?',
      message: 'Conținutul observației va fi înlocuit.',
      confirmText: 'Aplică',
      cancelText: 'Păstrează textul',
    }))
  )
    return
  if (disposed || frozen.value || contentRevision !== revision || context !== previewContext.value)
    return
  form.content = content
  contentUserEdited.value = false
  preview.value = null
  await nextTick()
  formElement.value?.querySelector<HTMLTextAreaElement>('textarea')?.focus()
}
function clearObservation(): void {
  if (frozen.value) return
  autoTemplatePending = false
  contentUserEdited.value = true
  // Also invalidate an outstanding response when the textarea is already empty.
  contentRevision++
  form.content = ''
  formElement.value?.querySelector<HTMLTextAreaElement>('textarea')?.focus()
}
async function initializeConfiguration(): Promise<void> {
  await configuration.loadTypes()
  if (disposed || initial) return
  if (
    configuration.ready.value &&
    autoTemplatePending &&
    (typeId.value !== null ? configuration.template.value : props.project.note_template)
  )
    void applyInitialTemplate()
}
async function switchType(next: number | null, acceptLegacy = false): Promise<void> {
  if (initial || frozen.value || typeSwitching.value) return
  if (next === typeId.value) {
    if (acceptLegacy) {
      configuration.continueLegacy()
      if (autoTemplatePending && !contentUserEdited.value && props.project.note_template)
        void applyInitialTemplate()
    }
    return
  }
  typeSwitching.value = true
  try {
    if (
      (contentUserEdited.value || configuration.hasValues.value) &&
      !(await confirmation.value?.ask({
        title: 'Schimbi tipul notei?',
        message: 'Textul și valorile introduse vor fi înlocuite. Nota nu a fost salvată.',
        confirmText: 'Schimbă tipul',
        cancelText: 'Continuă editarea',
      }))
    )
      return
    if (disposed || frozen.value) return
    previewRequest++
    previewLoading.value = false
    preview.value = null
    previewError.value = ''
    autoTemplatePending = false
    form.content = ''
    contentUserEdited.value = false
    const revision = contentRevision
    if (acceptLegacy) configuration.continueLegacy()
    await configuration.selectType(next)
    const content =
      next !== null
        ? (configuration.template.value ?? '')
        : props.project.note_template
          ? await loadPreview(false)
          : ''
    if (!disposed && !frozen.value && contentRevision === revision && content !== null)
      form.content = content
  } finally {
    typeSwitching.value = false
  }
}
function changeType(event: Event): void {
  const element = event.target as HTMLSelectElement
  const next = noteTypes.value.find((type) => String(type.id) === element.value)?.id ?? null
  element.value = typeId.value === null ? '' : String(typeId.value)
  void switchType(next)
}
async function reloadFields(): Promise<void> {
  if (initial || frozen.value || typeSwitching.value) return
  if (
    configuration.hasValues.value &&
    !(await confirmation.value?.ask({
      title: 'Reîncarci câmpurile?',
      message: 'Valorile introduse vor fi înlocuite. Textul observației se păstrează.',
      confirmText: 'Reîncarcă',
      cancelText: 'Continuă editarea',
    }))
  )
    return
  if (!disposed && !frozen.value) await configuration.selectType(typeId.value)
}
onMounted(() => {
  if (!initial) void initializeConfiguration()
})
onBeforeUnmount(() => {
  disposed = true
  previewRequest++
})
async function save(): Promise<void> {
  if (busy.value || unknownCreation.value || completed.value || typeSwitching.value) return
  if (!canManage.value) {
    error.value =
      props.project.status !== 'active'
        ? 'Proiectul este inactiv. Reactivează-l înainte de a salva progresul.'
        : 'Nu ai permisiunea să modifici această notă.'
    return
  }
  busy.value = true
  emit('busy', true)
  error.value = ''
  errors.value = {}
  let attemptedCreate = false
  let submittedValues: NoteFieldEntry[] = []
  try {
    if (!form.content.trim()) errors.value.content = ['Completează conținutul notei.']
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.noted_at))
      errors.value.noted_at = ['Alege o dată validă pentru observație.']
    if (form.title.length > 255)
      errors.value.title = ['Titlul poate avea maximum 255 de caractere.']
    const fieldsValid = state.noteSaved || configuration.validate()
    if (Object.keys(errors.value).length || !fieldsValid) {
      error.value = 'Verifică informațiile introduse.'
      await nextTick()
      formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
      return
    }
    const ids = selected.value.map((task) => task.id)
    const unchanged =
      initial &&
      JSON.stringify([...ids].sort((a, b) => a - b)) ===
        JSON.stringify([...initial.task_ids].sort((a, b) => a - b))
    const payload = notePayload(
      form.title,
      form.content,
      form.noted_at,
      unchanged ? undefined : ids,
    )
    submittedValues = configuration.entries()
    if (!initial && typeId.value !== null) {
      payload.note_type_id = typeId.value
      payload.values = submittedValues
    } else if (initial?.note_type_id && submittedValues.length) payload.values = submittedValues
    const intents: ScoreIntent[] = []
    for (const task of selected.value) {
      if (task.deleted_at || state.finished.has(task.id)) continue
      const row = choices[task.id]!
      const value = existing(task.id)
      if (value && (!row.update || !canEditScore(task.id))) continue
      // Vue's v-model on type="number" returns numbers, even without .number.
      if (!value && !String(row.score).trim()) continue
      const score = Number(row.score)
      if (!Number.isInteger(score) || score < 1 || score > 10) {
        evaluationsOpen.value = true
        throw new Error(
          `Scorul pentru „${task.title}” trebuie să fie un număr întreg între 1 și 10.`,
        )
      }
      intents.push({ task_id: task.id, score, ...(value ? { existing: value } : {}) })
    }
    attemptedCreate = !initial && !state.noteSaved
    await saveNoteAndScores(props.project.id, initial?.id, payload, intents, state)
    completed.value = true
    emit('saved')
  } catch (failure) {
    if (failure instanceof ApiError) {
      errors.value = failure.errors
      configuration.mapErrors(failure.errors, submittedValues)
    }
    const message =
      failure instanceof ApiError
        ? failure.status === 409 && !state.noteSaved
          ? `Configurația s-a schimbat. ${failure.message}`
          : progressError(failure)
        : failure instanceof Error
          ? failure.message
          : 'Nu am putut salva progresul.'
    error.value = state.noteSaved
      ? `Nota #${state.note!.id} a fost salvată. Unele evaluări nu au fost salvate. Scorurile confirmate nu se retrimit. ${message}`
      : message
    if (state.noteSaved) {
      evaluationsOpen.value = true
      emit('changed')
    }
    if (
      attemptedCreate &&
      !state.noteSaved &&
      (!(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500)
    ) {
      unknownCreation.value = true
      error.value =
        'Nu putem confirma dacă nota a fost creată. Închide formularul și verifică jurnalul înainte de a crea alta.'
      emit('changed')
    }
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
      notifications.error(progressError(failure))
      emit('accessChanged')
    }
    await nextTick()
    formElement.value?.querySelector<HTMLElement>('.note-editor-error')?.focus()
  } finally {
    busy.value = false
    emit('busy', false)
  }
}
defineExpose({ save, dirty, busy, saveLabel, saveDisabled, closeMessage })
</script>

<template>
  <form
    ref="formElement"
    class="note-editor-form"
    novalidate
    :aria-busy="busy"
    @submit.prevent="save"
  >
    <div v-if="error" class="projects-error note-editor-error" role="alert" tabindex="-1">
      <p>{{ error }}</p>
      <p v-for="(messages, field) in errors" :key="field">{{ messages.join(' ') }}</p>
    </div>
    <fieldset class="note-editor-fields" :disabled="frozen">
      <div class="note-editor-type">
        <template v-if="initial"
          ><span class="note-editor-type-label">Tip de notă</span>
          <p>
            {{
              initial.configuration?.note_type.name ??
              (initial.note_type_id ? 'Tip salvat' : 'Fără tip')
            }}<span v-if="initial.current_configuration_state?.note_type_archived_at">
              · Arhivat</span
            >
          </p></template
        >
        <template v-else>
          <label :for="`${observationId}-type`">Tip de notă</label>
          <select
            :id="`${observationId}-type`"
            :value="typeId === null ? '' : String(typeId)"
            :disabled="frozen || typesLoading || typeSwitching"
            :aria-busy="typesLoading"
            @change="changeType"
          >
            <option value="">Fără tip</option>
            <option v-for="type in noteTypes" :key="type.id" :value="String(type.id)">
              {{ type.name }}{{ type.is_default ? ' · Implicit' : '' }}
            </option>
          </select>
          <p v-if="typesLoading" role="status" class="note-editor-hint">Se încarcă tipurile…</p>
          <div v-if="typesError" role="alert" class="note-editor-field-error">
            <p>{{ typesError }}</p>
            <button type="button" class="note-editor-text-action" @click="initializeConfiguration">
              Reîncearcă tipurile</button
            ><button type="button" class="note-editor-text-action" @click="switchType(null, true)">
              Continuă fără tip
            </button>
          </div>
        </template>
      </div>
      <div class="note-editor-meta">
        <AppInput
          v-model="form.title"
          label="Titlu"
          placeholder="Progres · data"
          :maxlength="255"
          :error="errors.title?.[0]"
        />
        <div class="note-editor-date">
          <label :for="dateId">Data</label>
          <input
            :id="dateId"
            v-model="form.noted_at"
            type="date"
            required
            :aria-invalid="Boolean(errors.noted_at)"
            :aria-describedby="errors.noted_at ? `${dateId}-error` : undefined"
          />
          <p v-if="errors.noted_at" :id="`${dateId}-error`" class="note-editor-field-error">
            {{ errors.noted_at[0] }}
          </p>
        </div>
      </div>
      <section class="note-editor-observation" aria-label="Observație">
        <div class="note-editor-section-heading">
          <label :for="observationId">Observație <span aria-hidden="true">*</span></label>
          <button class="note-editor-text-action" type="button" @click="clearObservation">
            Golește
          </button>
        </div>
        <textarea
          :id="observationId"
          v-model="form.content"
          @input="contentUserEdited = true"
          rows="5"
          placeholder="Scrie observația…"
          required
          :aria-invalid="Boolean(errors.content)"
          :aria-describedby="errors.content ? `${observationId}-error` : undefined"
        />
        <p v-if="errors.content" :id="`${observationId}-error`" class="note-editor-field-error">
          {{ errors.content[0] }}
        </p>
        <details class="note-editor-template">
          <summary>
            {{ typeId === null ? 'Template proiect' : 'Template tip' }}
            <span v-if="previewLoading" role="status">· se încarcă…</span>
          </summary>
          <div class="note-editor-template-actions">
            <button
              class="note-editor-text-action"
              type="button"
              :disabled="previewLoading"
              @click="applyPreview"
            >
              Aplică template
            </button>
            <button
              class="note-editor-text-action"
              type="button"
              :disabled="previewLoading"
              @click="loadPreview()"
            >
              Previzualizează
            </button>
          </div>
          <div v-if="preview !== null" class="note-editor-preview">
            <pre>{{ preview || 'Nu există un template configurat.' }}</pre>
          </div>
        </details>
        <p v-if="previewError" role="alert" class="note-editor-field-error">{{ previewError }}</p>
      </section>
      <p v-if="fieldsLoading" role="status" class="note-editor-hint">Se încarcă câmpurile…</p>
      <div v-if="fieldsError" role="alert" class="note-editor-field-error">
        <p>{{ fieldsError }}</p>
        <button v-if="!initial" type="button" class="note-editor-text-action" @click="reloadFields">
          Reîncearcă câmpurile
        </button>
      </div>
      <ProgressNoteFieldValues
        :fields="customFields"
        :values="customValues"
        :originals="originalValues"
        :current="currentConfiguration"
        :errors="customErrors"
        :disabled="frozen"
        @change="(id, value) => (customValues[id] = value)"
      />
      <button
        v-if="!initial && typeId !== null && !fieldsLoading && !fieldsError"
        class="note-editor-text-action note-editor-reload-fields"
        type="button"
        @click="reloadFields"
      >
        Reîncarcă câmpurile
      </button>
      <section class="note-editor-linked" aria-label="Taskuri asociate">
        <div class="note-editor-section-heading">
          <h3>
            Taskuri asociate <span v-if="selected.length">{{ selected.length }}</span>
          </h3>
        </div>
        <ProjectTaskPicker
          v-model="selected"
          :project-id="project.id"
          multiple
          compact
          :disabled="frozen || deletedLinks"
          label="Adaugă task"
          @access-changed="emit('accessChanged')"
        />
        <p class="note-editor-hint">
          {{
            !selected.length
              ? 'Notă generală · doar autorul și proprietarul.'
              : 'Vizibilă celor cu acces la toate taskurile asociate.'
          }}
        </p>
        <p v-if="deletedLinks" class="note-editor-hint">
          Asocierile cu taskuri șterse se păstrează.
        </p>
      </section>
    </fieldset>
    <details
      v-if="selected.some((task) => !task.deleted_at)"
      class="note-editor-evaluations"
      :open="evaluationsOpen"
      @toggle="evaluationsOpen = ($event.target as HTMLDetailsElement).open"
    >
      <summary>
        Evaluări taskuri
        <span>{{ evaluationCount ? `${evaluationCount} existente` : 'opțional · 1–10' }}</span>
      </summary>
      <fieldset class="note-editor-fields" :disabled="busy || unknownCreation || !canManage">
        <p v-if="initial" class="note-editor-hint">
          Evaluările existente își păstrează data. Actualizarea unui scor este explicită.
        </p>
        <div
          v-for="task in selected.filter((value) => !value.deleted_at)"
          :key="task.id"
          class="note-editor-score"
        >
          <div>
            <strong>{{ task.title }}</strong>
            <p v-if="existing(task.id)" class="note-editor-hint">
              {{ existing(task.id)!.score }}/10 · {{ form.noted_at }}
            </p>
          </div>
          <label class="note-editor-score-input"
            >Scor (1–10)<input
              v-model="choices[task.id]!.score"
              type="number"
              inputmode="numeric"
              min="1"
              max="10"
              step="1"
              :disabled="
                state.finished.has(task.id) ||
                Boolean(existing(task.id) && !choices[task.id]!.update)
              "
          /></label>
          <label v-if="canEditScore(task.id)" class="note-editor-score-update"
            ><input
              v-model="choices[task.id]!.update"
              type="checkbox"
              :disabled="state.finished.has(task.id)"
            />Actualizează scorul existent</label
          >
          <p v-if="state.finished.has(task.id)" class="note-editor-hint">Evaluare salvată.</p>
        </div>
      </fieldset>
    </details>
    <ProgressNoteConfirmDialog ref="confirmation" />
  </form>
</template>
