<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskPicker from '@/components/projects/ProjectTaskPicker.vue'
import ProjectEvaluationCard from '@/components/projects/ProjectEvaluationCard.vue'
import { projectProgressApi } from '@/services/projectProgressApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { NoteTask, TaskEvaluation } from '@/types/projectProgress'
import { progressError } from '@/utils/projectProgress'
import { today } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{ project: Project; timezone?: string; locked?: boolean }>(),
  { timezone: 'UTC', locked: false },
)
const emit = defineEmits<{ changed: []; busy: [value: boolean]; accessChanged: [] }>()
const selected = ref<NoteTask[]>([])
const expanded = ref(false)
const rows = ref<TaskEvaluation[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const creating = ref(false)
const score = ref('')
const date = ref(today(props.timezone))
const page = ref(0)
const lastPage = ref(1)
let sequence = 0
async function load(more = false): Promise<void> {
  const task = selected.value[0]
  if (!task || task.deleted_at) return
  const request = ++sequence
  loading.value = true
  try {
    const response = await projectProgressApi.evaluations(
      props.project.id,
      task.id,
      more ? page.value + 1 : 1,
    )
    if (request !== sequence) return
    rows.value = more ? [...rows.value, ...response.data] : response.data
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
  } catch (failure) {
    if (request === sequence) {
      error.value = progressError(failure)
      rows.value = []
      page.value = 0
      lastPage.value = 1
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
    }
  } finally {
    if (request === sequence) loading.value = false
  }
}
function openFor(task: NoteTask): void {
  selected.value = [task]
  expanded.value = true
}
function toggle(event: Event): void {
  expanded.value = (event.target as HTMLDetailsElement).open
  if (expanded.value && selected.value.length && page.value === 0 && !loading.value) void load()
}
async function create(): Promise<void> {
  const task = selected.value[0]
  if (!task || task.deleted_at || busy.value || props.locked || props.project.status !== 'active')
    return
  busy.value = true
  emit('busy', true)
  error.value = ''
  try {
    await projectProgressApi.createEvaluation(props.project.id, task.id, {
      evaluation_date: date.value,
      score: Number(score.value),
    })
    creating.value = false
    score.value = ''
    await load()
    emit('changed')
  } catch (failure) {
    error.value = progressError(failure)
    if (failure instanceof ApiError && failure.status === 409) await load()
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
  } finally {
    busy.value = false
    emit('busy', false)
  }
}
function changed(): void {
  void load()
  emit('changed')
}
function retry(): void {
  error.value = ''
  void load()
}
watch(
  () => selected.value[0]?.id,
  () => {
    sequence++
    page.value = 0
    rows.value = []
    creating.value = false
    error.value = ''
    if (expanded.value) void load()
  },
)
onBeforeUnmount(() => {
  sequence++
  if (busy.value) emit('busy', false)
})
function refresh(): void {
  if (expanded.value) void load()
}
defineExpose({ openFor, refresh })
</script>

<template>
  <details class="progress-task-evaluations" :open="expanded" @toggle="toggle">
    <summary @click="locked && $event.preventDefault()">Evaluări pe task</summary>
    <div class="progress-stack">
      <ProjectTaskPicker
        v-model="selected"
        :project-id="project.id"
        :disabled="locked || busy"
        @access-changed="emit('accessChanged')"
      />
      <p v-if="selected[0]?.deleted_at" class="projects-muted">
        Taskul este șters. Evaluările pot fi administrate după restaurarea taskului.
      </p>
      <template v-else-if="selected[0]">
        <button
          v-if="project.status === 'active'"
          class="task-quick"
          type="button"
          :disabled="locked || busy"
          @click="creating = !creating"
        >
          Adaugă evaluare fără notă
        </button>
        <form v-if="creating" class="progress-inline-form" @submit.prevent="create">
          <div class="task-form__columns">
            <label
              >Data evaluării<input v-model="date" type="date" required :disabled="busy" /></label
            ><label
              >Scor (1–10)<input
                v-model="score"
                type="number"
                min="1"
                max="10"
                step="1"
                required
                :disabled="busy"
            /></label>
          </div>
          <AppButton type="submit" :loading="busy">Creează evaluarea</AppButton>
        </form>
        <p v-if="error" class="projects-error" role="alert">{{ error }}</p>
        <p v-if="loading" role="status">Se încarcă evaluările…</p>
        <ProjectEvaluationCard
          v-for="record in rows"
          :key="record.id"
          :evaluation="record"
          :project="project"
          :task-title="selected[0].title"
          :locked="locked || busy"
          @busy="emit('busy', $event)"
          @changed="changed"
          @access-changed="emit('accessChanged')"
        />
        <p v-if="!loading && !error && !rows.length" class="projects-muted">
          Nu există evaluări active vizibile pentru acest task.
        </p>
        <button
          v-if="error"
          class="task-quick"
          type="button"
          :disabled="locked || busy"
          @click="retry"
        >
          Reîncarcă evaluările
        </button>
        <AppButton
          v-if="page < lastPage"
          variant="secondary"
          :loading="loading"
          :disabled="locked || busy"
          @click="load(true)"
          >Mai multe evaluări</AppButton
        >
      </template>
      <p v-else class="projects-muted">
        Alege un task pentru a vedea scorurile și reviziile disponibile.
      </p>
    </div>
  </details>
</template>
