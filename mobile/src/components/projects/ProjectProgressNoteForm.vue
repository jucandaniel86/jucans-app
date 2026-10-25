<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskPicker from '@/components/projects/ProjectTaskPicker.vue'
import { ApiError } from '@/services/api'
import { projectProgressApi } from '@/services/projectProgressApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type { NoteTask, ProgressNote } from '@/types/projectProgress'
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
const frozen = computed(() => busy.value || state.noteSaved || unknownCreation.value)
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
    tasks: selected.value.filter((task) => !task.deleted_at).map((task) => task.id),
  }),
)
watch(previewContext, () => {
  preview.value = null
  previewError.value = ''
})
async function loadPreview(showPreview = true): Promise<string | null> {
  if (previewLoading.value || frozen.value) return null
  const context = previewContext.value
  previewLoading.value = true
  previewError.value = ''
  try {
    const response = await projectProgressApi.preview(
      props.project.id,
      form.noted_at,
      selected.value.filter((task) => !task.deleted_at).map((task) => task.id),
    )
    if (context === previewContext.value) {
      if (showPreview || !response.data.content) preview.value = response.data.content
      return response.data.content
    }
  } catch (failure) {
    if (context === previewContext.value) previewError.value = progressError(failure)
  } finally {
    previewLoading.value = false
  }
  return null
}
async function applyPreview(): Promise<void> {
  const content = await loadPreview(false)
  if (!content || frozen.value) return
  if (
    form.content.trim() &&
    !(await notifications.confirm({
      title: 'Aplici template-ul?',
      message: 'Conținutul introdus va fi înlocuit cu previzualizarea.',
      confirmText: 'Aplică',
    }))
  )
    return
  if (!frozen.value) {
    form.content = content
    preview.value = null
    await nextTick()
    const textarea = formElement.value?.querySelector<HTMLTextAreaElement>('textarea')
    textarea?.focus()
    textarea?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }
}
async function save(): Promise<void> {
  if (busy.value || unknownCreation.value) return
  if (props.project.status !== 'active') {
    error.value = 'Proiectul este inactiv. Reactivează-l înainte de a salva progresul.'
    return
  }
  busy.value = true
  emit('busy', true)
  error.value = ''
  errors.value = {}
  try {
    if (!form.content.trim()) errors.value.content = ['Completează conținutul notei.']
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.noted_at))
      errors.value.noted_at = ['Alege o dată validă pentru observație.']
    if (form.title.length > 255)
      errors.value.title = ['Titlul poate avea maximum 255 de caractere.']
    if (Object.keys(errors.value).length) {
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
    const intents: ScoreIntent[] = []
    for (const task of selected.value) {
      if (task.deleted_at || state.finished.has(task.id)) continue
      const row = choices[task.id]!
      const value = existing(task.id)
      if (value && (!row.update || !canEditScore(task.id))) continue
      // Vue's v-model on type="number" returns numbers, even without .number.
      if (!value && !String(row.score).trim()) continue
      const score = Number(row.score)
      if (!Number.isInteger(score) || score < 1 || score > 10)
        throw new Error(
          `Scorul pentru „${task.title}” trebuie să fie un număr întreg între 1 și 10.`,
        )
      intents.push({ task_id: task.id, score, ...(value ? { existing: value } : {}) })
    }
    await saveNoteAndScores(props.project.id, initial?.id, payload, intents, state)
    emit('saved')
  } catch (failure) {
    if (failure instanceof ApiError) errors.value = failure.errors
    const message =
      failure instanceof ApiError
        ? progressError(failure)
        : failure instanceof Error
          ? failure.message
          : 'Nu am putut salva progresul.'
    error.value = state.noteSaved
      ? `Nota #${state.note!.id} a fost salvată. Unele evaluări nu au fost salvate. Scorurile confirmate nu se retrimit. ${message}`
      : message
    if (state.noteSaved) emit('changed')
    if (
      !initial &&
      !state.noteSaved &&
      failure instanceof ApiError &&
      (failure.status === 0 || failure.status >= 500)
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
  } finally {
    busy.value = false
    emit('busy', false)
  }
}
</script>

<template>
  <form ref="formElement" class="progress-note-form" novalidate @submit.prevent="save">
    <h3>{{ initial ? 'Editează progresul' : 'Adaugă progres' }}</h3>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <p v-for="(messages, field) in errors" :key="field">{{ messages.join(' ') }}</p>
    </div>
    <fieldset class="task-fields" :disabled="frozen">
      <AppInput
        v-model="form.title"
        label="Titlu (opțional)"
        :maxlength="255"
        :error="errors.title?.[0]"
      />
      <p class="projects-muted">Dacă îl lași gol, folosim „Progres · data”.</p>
      <label
        >Data observației<input
          v-model="form.noted_at"
          type="date"
          required
          :aria-invalid="Boolean(errors.noted_at)"
      /></label>
      <AppTextarea
        v-model="form.content"
        label="Conținut *"
        hint="Textul notei poate fi editat liber, inclusiv după aplicarea template-ului."
        :rows="4"
        :error="errors.content?.[0]"
      />
      <ProjectTaskPicker
        v-model="selected"
        :project-id="project.id"
        multiple
        :disabled="frozen || deletedLinks"
        @access-changed="emit('accessChanged')"
        label="Asociază taskuri (opțional)"
      />
      <p v-if="!selected.length" class="projects-muted">
        Notă generală: vizibilă doar ție și proprietarului proiectului.
      </p>
      <p v-else class="projects-muted">
        Nota este vizibilă colaboratorilor care au acces la toate taskurile asociate.
      </p>
      <p v-if="deletedLinks" class="projects-muted">
        Asocierile cu taskuri șterse se păstrează. Poți modifica textul notei.
      </p>
      <div class="task-buttons">
        <button class="task-quick" type="button" :disabled="previewLoading" @click="applyPreview">
          Aplică template
        </button>
        <button class="task-quick" type="button" :disabled="previewLoading" @click="loadPreview()">
          {{ previewLoading ? 'Se încarcă template-ul…' : 'Previzualizează template' }}
        </button>
      </div>
      <p v-if="previewError" role="alert" class="task-overdue">{{ previewError }}</p>
      <div v-if="preview !== null" class="progress-preview">
        <p class="projects-muted">Previzualizare template · doar citire</p>
        <pre>{{ preview || 'Nu există un șablon configurat pentru acest proiect.' }}</pre>
      </div>
    </fieldset>
    <fieldset
      v-if="selected.some((task) => !task.deleted_at)"
      class="task-fields progress-score-fields"
      :disabled="busy || unknownCreation"
    >
      <legend>Evaluări opționale · {{ form.noted_at }}</legend>
      <p class="projects-muted">
        Evaluările existente își păstrează data, chiar dacă schimbi data notei. Un scor nou nu
        înlocuiește automat altă evaluare.
      </p>
      <div
        v-for="task in selected.filter((value) => !value.deleted_at)"
        :key="task.id"
        class="progress-score-row"
      >
        <strong>{{ task.title }}</strong>
        <p v-if="existing(task.id)" class="projects-muted">
          Scor existent: {{ existing(task.id)!.score }}/10.
        </p>
        <label v-if="canEditScore(task.id)" class="task-check"
          ><input
            v-model="choices[task.id]!.update"
            type="checkbox"
            :disabled="state.finished.has(task.id)"
          />
          Actualizează explicit scorul existent</label
        >
        <label
          >Scor (1–10)<input
            v-model="choices[task.id]!.score"
            type="number"
            min="1"
            max="10"
            step="1"
            :disabled="
              state.finished.has(task.id) || Boolean(existing(task.id) && !choices[task.id]!.update)
            "
        /></label>
        <p v-if="state.finished.has(task.id)" class="projects-muted">Evaluare salvată.</p>
      </div>
    </fieldset>
    <div class="task-buttons">
      <AppButton type="submit" :loading="busy" :disabled="unknownCreation">{{
        state.noteSaved ? 'Reîncearcă evaluările rămase' : 'Salvează progresul'
      }}</AppButton
      ><button class="task-quick" type="button" :disabled="busy" @click="emit('cancel')">
        {{ state.noteSaved || unknownCreation ? 'Închide și verifică jurnalul' : 'Renunță' }}
      </button>
    </div>
  </form>
</template>
