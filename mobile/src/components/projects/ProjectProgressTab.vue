<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import PlusIcon from '@/components/icons/plusIcon.vue'
import ProjectTaskPicker from '@/components/projects/ProjectTaskPicker.vue'
import ProjectProgressNoteForm from '@/components/projects/ProjectProgressNoteForm.vue'
import ProjectEvaluationCard from '@/components/projects/ProjectEvaluationCard.vue'
import ProjectTaskEvaluations from '@/components/projects/ProjectTaskEvaluations.vue'
import ProgressNoteContent from '@/components/projects/ProgressNoteContent.vue'
import { projectProgressApi } from '@/services/projectProgressApi'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type { NoteTask, ProgressNote } from '@/types/projectProgress'
import { progressAuthor, progressError } from '@/utils/projectProgress'
import { displayDate } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    project: Project
    timezone?: string
    initialTask?: NoteTask | null
    initialDate?: string
    externalBusy?: boolean
  }>(),
  { timezone: 'UTC', initialTask: null, externalBusy: false },
)
const emit = defineEmits<{ changed: []; busy: [value: boolean]; accessChanged: []; consumed: [] }>()
const auth = useAuthStore()
const notifications = useNotificationStore()
const owner = computed(() => props.project.created_by === auth.user?.id)
const active = computed(() => props.project.status === 'active')
const rows = ref<ProgressNote[]>([])
const page = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const recentlyDeleted = ref<ProgressNote | null>(null)
const trash = ref(false)
const search = ref('')
const from = ref('')
const to = ref('')
const general = ref(false)
const filterTasks = ref<NoteTask[]>(props.initialTask ? [props.initialTask] : [])
const initialTasks = ref<NoteTask[]>(props.initialTask ? [props.initialTask] : [])
const editorDate = ref(props.initialDate)
const creating = ref(Boolean(props.initialTask) && active.value)
const editing = ref<ProgressNote | undefined>()
const editorKey = ref(0)
const editor = ref<HTMLElement | null>(null)
const evaluationPanel = ref<InstanceType<typeof ProjectTaskEvaluations> | null>(null)
const busyCount = ref(0)
const locked = computed(() => busyCount.value > 0 || props.externalBusy)
let sequence = 0
let disposed = false
const grouped = computed(() => {
  const groups = new Map<string, ProgressNote[]>()
  for (const note of rows.value) {
    if (!groups.has(note.noted_at)) groups.set(note.noted_at, [])
    groups.get(note.noted_at)!.push(note)
  }
  return [...groups].map(([date, notes]) => ({ date, notes }))
})
const canManage = (note: ProgressNote) =>
  active.value && (owner.value || note.created_by === auth.user?.id)
function busy(value: boolean): void {
  busyCount.value = Math.max(0, busyCount.value + (value ? 1 : -1))
  emit('busy', locked.value)
}
async function load(more = false): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  try {
    const response = await projectProgressApi.notes(
      props.project.id,
      {
        search: search.value.trim() || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
        general_only: general.value,
        task_id: !general.value ? filterTasks.value[0]?.id : undefined,
        page: more ? page.value + 1 : 1,
      },
      trash.value,
    )
    if (request !== sequence || disposed) return
    rows.value = more
      ? [...new Map([...rows.value, ...response.data].map((note) => [note.id, note])).values()]
      : response.data
    rows.value.sort((a, b) => b.noted_at.localeCompare(a.noted_at) || b.id - a.id)
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
  } catch (failure) {
    if (request === sequence && !disposed) {
      error.value = progressError(failure)
      rows.value = []
      page.value = 0
      lastPage.value = 1
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
    }
  } finally {
    if (request === sequence && !disposed) loading.value = false
  }
}
function changed(refreshEvaluations = true): void {
  void load()
  if (refreshEvaluations) evaluationPanel.value?.refresh()
  emit('changed')
}
function closeEditor(): void {
  creating.value = false
  editing.value = undefined
}
function saved(): void {
  closeEditor()
  changed()
  notifications.success('Progresul a fost salvat.')
}
async function openEditor(note?: ProgressNote): Promise<void> {
  if (locked.value || !active.value) return
  editing.value = note
  creating.value = !note
  initialTasks.value = note ? [] : [...filterTasks.value]
  editorDate.value = undefined
  editorKey.value++
  await nextTick()
  editor.value?.scrollIntoView({ block: 'start' })
}
function switchTrash(): void {
  if (locked.value || !owner.value) return
  closeEditor()
  trash.value = !trash.value
  rows.value = []
  page.value = 0
  actionError.value = ''
  void load()
}
async function mutate(note: ProgressNote, restore = false): Promise<void> {
  if (locked.value || !canManage(note)) return
  busy(true)
  actionError.value = ''
  try {
    if (
      !(await notifications.confirm({
        title: restore ? 'Restaurezi nota?' : 'Muți nota în Trash?',
        message: restore
          ? 'Evaluările asociate se reactivează numai dacă nu există conflicte.'
          : 'Evaluările legate de notă sunt suspendate. Istoricul se păstrează.',
        confirmText: restore ? 'Restaurează' : 'Șterge',
      }))
    )
      return
    if (restore) await projectProgressApi.restoreNote(props.project.id, note.id)
    else await projectProgressApi.deleteNote(props.project.id, note.id)
    if (!restore) recentlyDeleted.value = note
    else if (recentlyDeleted.value?.id === note.id) recentlyDeleted.value = null
    if (editing.value?.id === note.id) closeEditor()
    changed()
  } catch (failure) {
    actionError.value =
      restore && failure instanceof ApiError && failure.status === 409
        ? 'Nota nu a fost restaurată: există deja o evaluare activă pentru un task în aceeași zi. Verifică evaluările, retrage explicit evaluarea înlocuitoare dacă ai dreptul, apoi reîncearcă.'
        : progressError(failure)
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
  } finally {
    busy(false)
  }
}
function noteTask(note: ProgressNote, id: number): string {
  return note.tasks.find((task) => task.id === id)?.title ?? `Task #${id}`
}
watch(
  () => props.project.status,
  () => {
    if (!active.value) closeEditor()
    void load()
  },
)
onMounted(async () => {
  void load()
  if (props.initialTask) {
    emit('consumed')
    if (creating.value) {
      await nextTick()
      editor.value?.scrollIntoView({ block: 'start' })
    }
  }
})
onBeforeUnmount(() => {
  disposed = true
  sequence++
  emit('busy', false)
})
</script>

<template>
  <section class="project-progress" aria-label="Jurnal de progres">
    <header class="progress-toolbar">
      <h3>{{ trash ? 'Trash · progres' : 'Jurnal de progres' }}</h3>
      <div class="task-buttons">
        <AppButton v-if="active && !trash" :disabled="locked" @click="openEditor()"
          ><PlusIcon aria-hidden="true" /> Adaugă progres</AppButton
        ><button
          v-if="owner"
          class="task-quick"
          type="button"
          :disabled="locked"
          @click="switchTrash"
        >
          {{ trash ? '‹ Jurnal' : 'Trash' }}
        </button>
      </div>
    </header>
    <p v-if="trash" class="projects-muted">
      Notele șterse și evaluările lor suspendate sunt vizibile aici doar proprietarului.
    </p>
    <div v-if="creating || editing" ref="editor">
      <ProjectProgressNoteForm
        :key="editorKey"
        :project="project"
        :note="editing"
        :initial-tasks="initialTasks"
        :initial-date="editorDate"
        :timezone="timezone"
        @busy="busy"
        @changed="changed"
        @saved="saved"
        @cancel="closeEditor"
        @access-changed="emit('accessChanged')"
      />
    </div>
    <details class="progress-filters">
      <summary @click="locked && $event.preventDefault()">Filtre și căutare</summary>
      <form class="progress-stack" @submit.prevent="load()">
        <fieldset class="task-fields" :disabled="locked">
          <AppInput v-model="search" label="Caută în titlu sau conținut" :maxlength="255" />
          <div class="task-form__columns">
            <label>De la<input v-model="from" type="date" /></label
            ><label>Până la<input v-model="to" type="date" /></label>
          </div>
          <label class="task-check"
            ><input v-model="general" type="checkbox" /> Doar note generale</label
          ><ProjectTaskPicker
            v-model="filterTasks"
            :project-id="project.id"
            :disabled="locked || general"
            label="Filtrează după task"
            @access-changed="emit('accessChanged')"
          /><AppButton type="submit" variant="secondary" :loading="loading"
            >Aplică filtrele</AppButton
          >
        </fieldset>
      </form>
    </details>
    <p v-if="loading" role="status">Se încarcă progresul…</p>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <button class="task-quick" type="button" :disabled="locked" @click="load()">
        Reîncearcă
      </button>
    </div>
    <p v-if="actionError" class="projects-error" role="alert">{{ actionError }}</p>
    <div v-if="!trash && recentlyDeleted && canManage(recentlyDeleted)" class="projects-notice">
      <p>Nota a fost mutată în Trash. Evaluările asociate sunt suspendate.</p>
      <button
        class="task-quick"
        type="button"
        :disabled="locked"
        @click="mutate(recentlyDeleted, true)"
      >
        Restaurează nota ștearsă recent
      </button>
    </div>
    <p v-if="!loading && !error && !rows.length" class="task-empty">
      {{
        trash
          ? 'Nu există note șterse pentru filtrele selectate.'
          : 'Nicio notă de progres încă. Adaugă o observație sau ajustează filtrele.'
      }}
    </p>
    <section v-for="group in grouped" :key="group.date" class="progress-date-group">
      <h4>{{ displayDate(group.date) }}</h4>
      <article v-for="note in group.notes" :key="note.id" class="progress-note">
        <header>
          <h4>{{ note.title }}</h4>
          <p class="projects-muted">
            {{ progressAuthor(note.created_by, project, auth.user) }} ·
            {{ displayDate(note.noted_at) }}
          </p>
        </header>
        <ProgressNoteContent :content="note.content" />
        <div v-if="note.tasks.length" class="progress-task-chips">
          <button
            v-for="task in note.tasks"
            :key="task.id"
            type="button"
            :disabled="locked || Boolean(task.deleted_at)"
            @click="evaluationPanel?.openFor(task)"
          >
            {{ task.title }}{{ task.deleted_at ? ' (șters)' : '' }}
          </button>
        </div>
        <p v-else class="projects-muted">Notă generală · autor și proprietar</p>
        <template v-if="trash"
          ><p v-for="score in note.evaluations" :key="score.id" class="projects-muted">
            {{ noteTask(note, score.task_id) }} · {{ score.score }}/10 ·
            {{ displayDate(score.evaluation_date) }} ·
            {{ score.withdrawn_at ? 'Retrasă' : 'Suspendată' }}
          </p></template
        >
        <template v-else
          ><ProjectEvaluationCard
            v-for="score in note.evaluations"
            :key="score.id"
            :project="project"
            :evaluation="score"
            :task-title="noteTask(note, score.task_id)"
            :task-deleted="
              Boolean(note.tasks.find((task) => task.id === score.task_id)?.deleted_at)
            "
            :locked="locked"
            @busy="busy"
            @changed="changed"
            @access-changed="emit('accessChanged')"
        /></template>
        <div v-if="canManage(note)" class="task-buttons">
          <button
            v-if="trash"
            class="progress-text-action"
            type="button"
            :disabled="locked"
            @click="mutate(note, true)"
          >
            Restaurează</button
          ><template v-else
            ><button
              class="progress-text-action"
              type="button"
              :disabled="locked"
              @click="openEditor(note)"
            >
              Editează nota</button
            ><button
              class="progress-text-action"
              type="button"
              :disabled="locked"
              @click="mutate(note)"
            >
              Șterge nota
            </button></template
          >
        </div>
      </article>
    </section>
    <AppButton
      v-if="page < lastPage"
      variant="secondary"
      :loading="loading"
      :disabled="locked"
      @click="load(true)"
      >Mai mult progres</AppButton
    >
    <ProjectTaskEvaluations
      v-if="!trash"
      ref="evaluationPanel"
      :project="project"
      :timezone="timezone"
      :locked="locked"
      @busy="busy"
      @changed="changed(false)"
      @access-changed="emit('accessChanged')"
    />
  </section>
</template>

<style src="./projectProgress.css"></style>
