<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, RouterLink } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import ProjectTaskCard from '@/components/projects/ProjectTaskCard.vue'
import ProjectTaskForm from '@/components/projects/ProjectTaskForm.vue'
import ProjectProgressNoteForm from '@/components/projects/ProjectProgressNoteForm.vue'
import { projectDailyTasksApi } from '@/services/projectDailyTasksApi'
import { projectsApi } from '@/services/projectsApi'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type { ProjectTask } from '@/types/projectTasks'
import type { DailyTaskRow, DailyTasks } from '@/types/projectDailyTasks'
import { taskError, today, displayDate } from '@/utils/projectTasks'
import '@/views/projects/projects.css'
import './projectTasks.css'
import './projectProgress.css'

const notifications = useNotificationStore()
const data = ref<DailyTasks | null>(null)
const rows = ref<DailyTaskRow[]>([])
const projects = ref<Record<number, Project>>({})
const expanded = ref(false)
const loading = ref(false)
const error = ref('')
const completedRows = ref<DailyTaskRow[]>([])
const completedOpen = ref(false)
const completedLoading = ref(false)
const completedError = ref('')
const completedPage = ref(0)
const completedLast = ref(1)
const busyCount = ref(0)
const busy = computed(() => busyCount.value > 0)
const noteTask = ref<ProjectTask | null>(null)
const editor = ref<HTMLElement | null>(null)
const noteProject = ref<Project | null>(null)
const creating = ref(false)
const targetProject = ref('')
const choices = ref<Project[]>([])
const choicesLoading = ref(false)
const choicesError = ref('')
const choicesPage = ref(0)
const choicesLast = ref(1)
const target = computed(() =>
  choices.value.find((project) => project.id === Number(targetProject.value)),
)
const date = computed(() => data.value?.date ?? today())
const visible = computed(() => (expanded.value ? rows.value : rows.value.slice(0, 5)))
const sections = [
  { key: 'overdue', label: 'Restante' },
  { key: 'today', label: 'Astăzi' },
  { key: 'persistent', label: 'Permanente' },
] as const
let sequence = 0,
  completedSequence = 0
let disposed = false
function setBusy(value: boolean): void {
  busyCount.value = Math.max(0, busyCount.value + (value ? 1 : -1))
}
function remember(values: Project[]): void {
  for (const project of values) projects.value[project.id] = project
}
async function load(more = false): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  try {
    const response = (
      await projectDailyTasksApi.list(more ? (data.value?.pagination.page ?? 0) + 1 : 1)
    ).data
    if (request !== sequence || disposed) return
    const sameDay = data.value?.date === response.date
    data.value = response
    remember(response.projects)
    rows.value =
      more && sameDay
        ? [...new Map([...rows.value, ...response.rows].map((row) => [row.task.id, row])).values()]
        : response.rows
    if (!sameDay) {
      completedRows.value = []
      completedPage.value = 0
    }
  } catch (failure) {
    if (request === sequence && !disposed) {
      rows.value = []
      data.value = null
      completedRows.value = []
      error.value = taskError(failure)
    }
  } finally {
    if (request === sequence && !disposed) loading.value = false
  }
}
async function loadCompleted(more = false): Promise<void> {
  const request = ++completedSequence
  completedLoading.value = true
  completedError.value = ''
  try {
    const response = (await projectDailyTasksApi.list(more ? completedPage.value + 1 : 1, true))
      .data
    if (request !== completedSequence || disposed) return
    if (data.value && response.date !== data.value.date) {
      await load()
      if (request !== completedSequence || disposed) return
    }
    remember(response.projects)
    completedRows.value = more
      ? [
          ...new Map(
            [...completedRows.value, ...response.rows].map((row) => [row.task.id, row]),
          ).values(),
        ]
      : response.rows
    completedPage.value = response.pagination.page
    completedLast.value = response.pagination.last_page
  } catch (failure) {
    if (request === completedSequence && !disposed) {
      completedRows.value = []
      completedPage.value = 0
      completedError.value = taskError(failure)
    }
  } finally {
    if (request === completedSequence && !disposed) completedLoading.value = false
  }
}
function toggleCompleted(event: Event): void {
  completedOpen.value = (event.target as HTMLDetailsElement).open
  if (completedOpen.value && !completedPage.value && !completedLoading.value) void loadCompleted()
}
async function refresh(): Promise<void> {
  // Clear the old completed page before an Undo can reappear in the active page.
  completedSequence++
  completedRows.value = []
  completedPage.value = 0
  completedLoading.value = false
  await load()
  if (completedOpen.value && !disposed) await loadCompleted()
}
function closeEditor(): void {
  noteTask.value = null
  noteProject.value = null
  creating.value = false
}
async function openNote(task: ProjectTask): Promise<void> {
  if (busy.value) return
  closeEditor()
  noteTask.value = task
  noteProject.value = projects.value[task.project_id] ?? null
  await nextTick()
  editor.value?.scrollIntoView({ block: 'start' })
}
function saved(): void {
  closeEditor()
  refresh()
  notifications.success('Salvat.')
}
function accessChanged(): void {
  closeEditor()
  projects.value = {}
  void refresh()
}
async function loadChoices(more = false): Promise<void> {
  if (choicesLoading.value) return
  choicesLoading.value = true
  choicesError.value = ''
  try {
    const response = await projectsApi.list('active', more ? choicesPage.value + 1 : 1)
    if (disposed) return
    choices.value = more
      ? [
          ...new Map(
            [...choices.value, ...response.data].map((project) => [project.id, project]),
          ).values(),
        ]
      : response.data
    choicesPage.value = response.meta.current_page
    choicesLast.value = response.meta.last_page
  } catch (failure) {
    if (!disposed) choicesError.value = taskError(failure)
  } finally {
    if (!disposed) choicesLoading.value = false
  }
}
async function openCreate(): Promise<void> {
  if (busy.value) return
  closeEditor()
  creating.value = true
  targetProject.value = ''
  void loadChoices()
  await nextTick()
  editor.value?.scrollIntoView({ block: 'start' })
}
onBeforeRouteLeave(() => !busy.value)
function checkDate(): void {
  if (!busy.value && data.value && today(data.value.timezone) !== data.value.date) void refresh()
}
let dateTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  document.addEventListener('visibilitychange', checkDate)
  dateTimer = setInterval(checkDate, 60_000)
})
onBeforeUnmount(() => {
  disposed = true
  sequence++
  completedSequence++
  document.removeEventListener('visibilitychange', checkDate)
  clearInterval(dateTimer)
})
</script>

<template>
  <section class="daily-tasks" aria-label="Taskurile zilei">
    <header class="daily-tasks__heading">
      <h2>Taskurile zilei</h2>
      <AppButton variant="secondary" :disabled="busy" @click="openCreate">+ Task</AppButton>
    </header>
    <p v-if="data" class="daily-tasks__summary">
      {{
        data.summary.scheduled
          ? `${data.summary.completed} din ${data.summary.scheduled} taskuri programate astăzi finalizate`
          : 'Niciun task programat astăzi.'
      }}
      <span>{{ displayDate(data.date) }}</span>
    </p>
    <p v-if="loading" role="status" class="projects-muted">Se încarcă taskurile…</p>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <button class="task-quick" type="button" :disabled="busy || loading" @click="load()">
        Reîncearcă
      </button>
    </div>
    <div v-if="noteTask && noteProject" ref="editor">
      <ProjectProgressNoteForm
        :key="noteTask.id"
        :project="noteProject"
        :initial-tasks="[{ id: noteTask.id, title: noteTask.title, deleted_at: null }]"
        :initial-date="date"
        :timezone="data?.timezone ?? 'UTC'"
        @busy="setBusy"
        @saved="saved"
        @changed="refresh"
        @cancel="closeEditor"
        @access-changed="accessChanged"
      />
    </div>
    <div v-if="creating" ref="editor" class="daily-tasks__editor">
      <AppSelect
        v-model="targetProject"
        label="Proiect pentru taskul nou"
        :disabled="busy"
        :options="[
          { value: '', label: 'Alege un proiect' },
          ...choices.map((project) => ({ value: String(project.id), label: project.name })),
        ]"
      />
      <p v-if="choicesLoading" role="status">Se încarcă proiectele…</p>
      <p v-if="choicesError" class="projects-error" role="alert">{{ choicesError }}</p>
      <button
        v-if="choicesError || choicesPage < choicesLast"
        class="task-quick"
        type="button"
        :disabled="busy || choicesLoading"
        @click="loadChoices(!choicesError)"
      >
        Încarcă proiecte
      </button>
      <p v-if="!choicesLoading && !choicesError && !choices.length" class="projects-muted">
        Nu ai proiecte active.
        <RouterLink :to="{ name: 'project-create' }">Creează un proiect</RouterLink>.
      </p>
      <ProjectTaskForm
        v-if="target"
        :key="target.id"
        :project="target"
        :initial-date="date"
        @busy="setBusy"
        @saved="saved"
        @changed="refresh"
        @cancel="closeEditor"
        @access-changed="accessChanged"
      />
      <button v-else class="task-quick" type="button" @click="closeEditor">Închide</button>
    </div>
    <template v-for="section in sections" :key="section.key">
      <section v-if="visible.some((row) => row.section === section.key)" class="daily-tasks__group">
        <h3>
          {{ section.label }} <span>{{ data?.counts[section.key] }}</span>
        </h3>
        <article
          v-for="row in visible.filter((row) => row.section === section.key)"
          :key="row.task.id"
          class="daily-tasks__row"
          :class="`daily-tasks__row--${section.key}`"
        >
          <RouterLink
            :to="{ name: 'project-detail', params: { id: row.task.project_id } }"
            class="daily-tasks__project"
            >{{ projects[row.task.project_id]?.name }}</RouterLink
          >
          <ProjectTaskCard
            v-if="projects[row.task.project_id]"
            :project="projects[row.task.project_id]!"
            :task="row.task"
            :timezone="data?.timezone ?? 'UTC'"
            :selected-date="date"
            :calendar-occurrence="row.occurrence"
            :locked="busy"
            compact
            @busy="setBusy"
            @changed="refresh"
            @progress="openNote"
            @access-changed="accessChanged"
          />
        </article>
      </section>
    </template>
    <p v-if="!loading && !error && !rows.length" class="projects-muted">
      Nu ai taskuri de făcut pentru astăzi.
    </p>
    <button
      v-if="!expanded && (data?.pagination.total ?? 0) > 5"
      class="task-quick"
      type="button"
      :disabled="busy"
      @click="expanded = true"
    >
      Vezi toate ({{ data?.pagination.total }})
    </button>
    <div v-if="expanded" class="task-buttons">
      <button
        v-if="data && data.pagination.page < data.pagination.last_page"
        class="task-quick"
        type="button"
        :disabled="busy || loading"
        @click="load(true)"
      >
        Mai multe taskuri</button
      ><button class="task-quick" type="button" :disabled="busy" @click="expanded = false">
        Mai puține
      </button>
    </div>
    <details :open="completedOpen" class="daily-tasks__completed" @toggle="toggleCompleted">
      <summary @click="busy && $event.preventDefault()">
        Finalizate <span>{{ data?.counts.completed ?? 0 }}</span>
      </summary>
      <p v-if="completedLoading" role="status">Se încarcă finalizările…</p>
      <div v-if="completedError" class="projects-error" role="alert">
        <p>{{ completedError }}</p>
        <button
          class="task-quick"
          type="button"
          :disabled="busy || completedLoading"
          @click="loadCompleted()"
        >
          Reîncearcă
        </button>
      </div>
      <article
        v-for="row in completedRows"
        :key="row.task.id"
        class="daily-tasks__row daily-tasks__row--completed"
      >
        <RouterLink
          :to="{ name: 'project-detail', params: { id: row.task.project_id } }"
          class="daily-tasks__project"
          >{{ projects[row.task.project_id]?.name }}</RouterLink
        >
        <ProjectTaskCard
          v-if="projects[row.task.project_id]"
          :project="projects[row.task.project_id]!"
          :task="row.task"
          :timezone="data?.timezone ?? 'UTC'"
          :selected-date="date"
          :calendar-occurrence="row.occurrence"
          :locked="busy"
          compact
          @busy="setBusy"
          @changed="refresh"
          @progress="openNote"
          @access-changed="accessChanged"
        />
      </article>
      <p
        v-if="!completedLoading && !completedError && !completedRows.length"
        class="projects-muted"
      >
        Nicio finalizare pentru astăzi.
      </p>
      <button
        v-if="completedPage > 0 && completedPage < completedLast"
        class="task-quick"
        type="button"
        :disabled="busy || completedLoading"
        @click="loadCompleted(true)"
      >
        Mai multe finalizări
      </button>
    </details>
  </section>
</template>

<style scoped>
.daily-tasks {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
}
.daily-tasks__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.daily-tasks h2 {
  margin: 0;
  font-size: 1.1rem;
}
.daily-tasks__heading :deep(.app-button) {
  min-height: 44px;
  padding-inline: 12px;
  font-size: 0.82rem;
}
.daily-tasks__summary {
  font-size: 0.82rem;
  font-weight: 650;
  color: var(--color-primary-strong);
}
.daily-tasks__summary span {
  display: block;
  margin-top: 4px;
  color: var(--color-text-muted);
  font-weight: 400;
  font-size: 0.75rem;
}
.daily-tasks__group,
.daily-tasks__editor {
  display: grid;
  gap: 8px;
  min-width: 0;
}
.daily-tasks h3,
.daily-tasks__completed summary {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 700;
}
.daily-tasks h3 span,
.daily-tasks__completed summary span {
  color: var(--color-text-muted);
  font-weight: 400;
}
.daily-tasks__row {
  min-width: 0;
  border-left: 2px solid var(--color-border);
  padding-left: 6px;
}
.daily-tasks__row--overdue {
  border-color: var(--color-error);
}
.daily-tasks__row--today {
  border-color: var(--color-primary-strong);
}
.daily-tasks__row--completed {
  border-color: #36805a;
}
.daily-tasks__project {
  display: block;
  padding: 3px 8px;
  font-size: 0.72rem;
  color: var(--color-text-muted);
  overflow-wrap: anywhere;
}
.daily-tasks__row :deep(.task-card) {
  box-shadow: none;
  border: 0;
  padding-top: 4px;
}
.daily-tasks__completed > summary {
  min-height: 44px;
  padding-block: 12px;
  cursor: pointer;
  color: var(--color-text);
}
.daily-tasks__completed > article {
  margin-bottom: 8px;
}
</style>
