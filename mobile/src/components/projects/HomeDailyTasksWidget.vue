<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, RouterLink } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import ProjectTaskCard from '@/components/projects/ProjectTaskCard.vue'
import ProjectTaskForm from '@/components/projects/ProjectTaskForm.vue'
import ProjectProgressNoteEditor from '@/components/projects/ProjectProgressNoteEditor.vue'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import { onProgressNoteDeleted, projectProgressApi } from '@/services/projectProgressApi'
import type { ProgressNote } from '@/types/projectProgress'
import type {
  CalendarReviews,
  CalendarTaskData,
  CalendarReadRequest,
  CalendarExecutionResult,
  TaskOccurrence,
} from '@/types/projectTasks'
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
const overduePage = ref(0)
const overdueLast = ref(1)
const overdueLoading = ref(false)
const overdueError = ref('')
let overdueSequence = 0
const activePageDirty = ref(false)
const overduePageDirty = ref(false)
const completedPageDirty = ref(false)
const busyCount = ref(0)
const busy = computed(() => busyCount.value > 0)
const noteTask = ref<ProjectTask | null>(null)
const noteDate = ref<string>()
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
const headerDate = computed(() => {
  const [year, month, day] = date.value.split('-').map(Number)
  const weekday = new Date(Date.UTC(year!, month! - 1, day!)).getUTCDay()
  return { label: ['Du', 'Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ'][weekday], day }
})
const rowKey = (row: DailyTaskRow) =>
  `${row.task.project_id}:${row.task.id}:${row.occurrence.occurrence_key}`
const overdueRows = computed(() => rows.value.filter((row) => row.section === 'overdue'))
const overdueIndex = ref(0)
const pendingOverdueCommand = ref(false)
const selectedOccurrence = ref('')
const currentOverdue = computed(() => overdueRows.value[overdueIndex.value])
const visible = computed(() => {
  const pending = rows.value.filter((row) => row.section !== 'overdue')
  return expanded.value ? pending : pending.slice(0, 5)
})
const completion = computed(() =>
  data.value?.summary.scheduled
    ? Math.min(100, (100 * data.value.summary.completed) / data.value.summary.scheduled)
    : 0,
)
watch(
  overdueRows,
  (items) => {
    const preserved = items.findIndex((row) => rowKey(row) === selectedOccurrence.value)
    overdueIndex.value =
      preserved >= 0 ? preserved : Math.min(overdueIndex.value, Math.max(0, items.length - 1))
    selectedOccurrence.value = items[overdueIndex.value] ? rowKey(items[overdueIndex.value]!) : ''
  },
  { flush: 'sync' },
)
function navigateOverdue(delta: number): void {
  if (busy.value || loading.value || pendingOverdueCommand.value) return
  overdueIndex.value = Math.max(
    0,
    Math.min(overdueRows.value.length - 1, overdueIndex.value + delta),
  )
  selectedOccurrence.value = currentOverdue.value ? rowKey(currentOverdue.value) : ''
}
let swipe: { x: number; y: number; id: number } | null = null
let suppressClick = false
function swipeStart(event: PointerEvent): void {
  suppressClick = false
  if (
    event.pointerType !== 'touch' ||
    pendingOverdueCommand.value ||
    busy.value ||
    loading.value ||
    (event.target as HTMLElement).closest('.task-widget-icon, details, input, select')
  )
    return
  swipe = { x: event.clientX, y: event.clientY, id: event.pointerId }
}
function swipeEnd(event: PointerEvent): void {
  if (!swipe || swipe.id !== event.pointerId) return
  const dx = event.clientX - swipe.x,
    dy = event.clientY - swipe.y
  swipe = null
  if (Math.abs(dx) >= 50 && Math.abs(dx) > Math.abs(dy) * 1.5) {
    suppressClick = true
    navigateOverdue(dx < 0 ? 1 : -1)
  }
}
function swipeClick(event: MouseEvent): void {
  if (!suppressClick) return
  event.preventDefault()
  event.stopPropagation()
  suppressClick = false
}
function overdueAge(row: DailyTaskRow): string {
  const deadline = row.task.due_date ?? row.task.scheduled_date
  if (!deadline) return 'Restant'
  const days = Math.round(
    (Date.parse(`${date.value}T00:00:00Z`) - Date.parse(`${deadline}T00:00:00Z`)) / 86400000,
  )
  return days <= 0
    ? 'Restant de astăzi'
    : days === 1
      ? '1 zi întârziere'
      : `${days} zile întârziere`
}
const sections = [
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
    const requestedPage = more
      ? Math.max(1, (data.value?.pagination.page ?? 0) + (activePageDirty.value ? 0 : 1))
      : 1
    const response = (await projectDailyTasksApi.list(requestedPage)).data
    if (request !== sequence || disposed) return
    if (more && data.value && response.date !== data.value.date) {
      void refresh()
      return
    }
    activePageDirty.value = false
    if (!more) {
      overdueSequence++
      overdueLoading.value = false
      overdueError.value = ''
      overduePage.value = response.overdue_pagination?.page ?? 1
      overdueLast.value = response.overdue_pagination?.last_page ?? 1
      overduePageDirty.value = false
    }
    const sameDay = data.value?.date === response.date
    data.value = response
    remember(response.projects)
    rows.value =
      more && sameDay
        ? [...new Map([...rows.value, ...response.rows].map((row) => [rowKey(row), row])).values()]
        : response.rows
    if (!sameDay) {
      completedPageDirty.value = false
      completedRows.value = []
      completedPage.value = 0
    }
  } catch (failure) {
    if (request === sequence && !disposed) {
      // Keep network-error drafts, but never retain rows after access is revoked.
      if (failure instanceof ApiError && [401, 403, 404].includes(failure.status)) {
        rows.value = []
        data.value = null
        completedRows.value = []
      }
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
    const response = (
      await projectDailyTasksApi.list(
        more ? Math.max(1, completedPage.value + (completedPageDirty.value ? 0 : 1)) : 1,
        true,
      )
    ).data
    if (request !== completedSequence || disposed) return
    if (data.value && response.date !== data.value.date) {
      await load()
      if (request !== completedSequence || disposed) return
    }
    completedPageDirty.value = false
    remember(response.projects)
    completedRows.value = more
      ? [
          ...new Map(
            [...completedRows.value, ...response.rows].map((row) => [rowKey(row), row]),
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
  completedPageDirty.value = false
  await load()
  if (completedOpen.value && !disposed) await loadCompleted()
}
function reviewsFor(row: DailyTaskRow): CalendarReviews {
  return (
    row.reviews ?? {
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
      review_context: {
        mode: row.task.review_mode ?? 'single',
        can_create: false,
        active_count: 0,
      },
    }
  )
}
function cardData(row: DailyTaskRow): CalendarTaskData {
  return {
    task: row.task,
    has_schedule: row.has_schedule ?? false,
    current_rule: row.current_rule ?? null,
    latest_rule: row.latest_rule ?? null,
    occurrences: [{ ...row.occurrence, reviews: reviewsFor(row) }],
  }
}
function allRows(): DailyTaskRow[] {
  return [...rows.value, ...completedRows.value]
}
function selectedDate(row: DailyTaskRow): string {
  return row.occurrence.occurrence_key === 'single' ? date.value : row.occurrence.occurrence_key
}
const reviewReads = new WeakMap<DailyTaskRow, number>()
function reviewChanged(note: ProgressNote): void {
  if (!note.review || !note.review_context) return
  for (const row of allRows()) {
    if (
      row.task.project_id !== note.project_id ||
      row.task.id !== note.review.task_id ||
      row.occurrence.occurrence_key !== note.review.occurrence_key
    )
      continue
    reviewReads.set(row, (reviewReads.get(row) ?? 0) + 1)
    const previous = reviewsFor(row)
    const old = previous.data.find((value) => value.id === note.id)
    const total = previous.meta.total + (old ? 0 : 1)
    row.reviews = {
      data: [note, ...previous.data.filter((value) => value.id !== note.id)],
      meta: { ...previous.meta, total, last_page: Math.max(1, Math.ceil(total / 20)) },
      review_context: {
        ...previous.review_context,
        ...note.review_context,
        active_count:
          (previous.review_context.active_count ?? 0) +
          (note.deleted_at ? 0 : 1) -
          (old && !old.deleted_at ? 1 : 0),
      },
    }
  }
}
async function read(row: DailyTaskRow, request: CalendarReadRequest): Promise<void> {
  const project = row.task.project_id
  const key = row.occurrence.occurrence_key
  const version = sequence
  const reviewRequest = (reviewReads.get(row) ?? 0) + 1
  if (request.kind === 'reviews') reviewReads.set(row, reviewRequest)
  try {
    let response: unknown
    if (request.kind === 'history')
      response = await projectTasksApi.history(
        project,
        request.taskId,
        request.from!,
        request.to!,
        request.page,
      )
    else if (request.kind === 'next')
      response = await projectTasksApi.occurrences(
        project,
        request.taskId,
        request.from!,
        request.to!,
        request.page,
      )
    else if (request.kind === 'reviews') {
      const result = await projectProgressApi.reviews(
        project,
        request.taskId,
        request.key!,
        request.page,
      )
      if (
        !disposed &&
        version === sequence &&
        reviewRequest === reviewReads.get(row) &&
        key === row.occurrence.occurrence_key &&
        allRows().includes(row)
      ) {
        const previous = reviewsFor(row)
        const existing = (request.page ?? 1) > 1 ? previous.data : []
        const notes = [
          ...new Map([...existing, ...result.data].map((note) => [note.id, note])).values(),
        ]
        row.reviews = {
          ...result,
          data: notes,
          review_context: {
            ...previous.review_context,
            ...result.review_context,
            active_count:
              result.review_context.mode === 'single'
                ? notes.filter((note) => !note.deleted_at).length
                : Math.max(0, result.meta.total - notes.filter((note) => note.deleted_at).length),
          },
        }
        if (row.task.review_mode !== result.review_context.mode)
          row.task = { ...row.task, review_mode: result.review_context.mode }
      }
      response = result
    } else {
      await refresh()
      response = allRows().find(
        (value) =>
          value.task.project_id === project &&
          value.task.id === request.taskId &&
          value.occurrence.occurrence_key === key,
      )
    }
    request.resolve(response)
  } catch (failure) {
    request.reject(failure)
  }
}
// DELETE keeps its existing 204 contract. Revalidate only the affected review
// context after a confirmed deletion, never the daily dataset or task status.
const stopNoteDeleted = onProgressNoteDeleted((project, note) => {
  for (const row of allRows()) {
    if (row.task.project_id !== project || !reviewsFor(row).data.some((value) => value.id === note))
      continue
    void read(row, {
      taskId: row.task.id,
      kind: 'reviews',
      key: row.occurrence.occurrence_key,
      page: 1,
      resolve: () => {},
      reject: (failure) => {
        notifications.error(taskError(failure))
        if (failure instanceof ApiError && [401, 403, 404].includes(failure.status)) accessChanged()
      },
    })
  }
})
async function loadOverdue(): Promise<void> {
  if (overdueLoading.value || busy.value || pendingOverdueCommand.value) return
  const request = ++overdueSequence
  const version = sequence
  overdueLoading.value = true
  overdueError.value = ''
  try {
    const response = (
      await projectDailyTasksApi.overdue(
        Math.max(1, overduePage.value + (overduePageDirty.value ? 0 : 1)),
      )
    ).data
    if (request !== overdueSequence || version !== sequence || disposed) return
    if (response.date !== data.value?.date) {
      await refresh()
      return
    }
    remember(response.projects)
    rows.value = [
      ...new Map([...rows.value, ...response.rows].map((row) => [rowKey(row), row])).values(),
    ]
    overduePage.value = response.pagination.page
    overdueLast.value = response.pagination.last_page
    overduePageDirty.value = false
    if (data.value) {
      data.value.counts.overdue = response.counts.overdue
      data.value.overdue_pagination = response.overdue_pagination
    }
  } catch (failure) {
    if (request !== overdueSequence || version !== sequence || disposed) return
    overdueError.value = taskError(failure)
    if (failure instanceof ApiError && [401, 403, 404].includes(failure.status)) accessChanged()
  } finally {
    if (request === overdueSequence && !disposed) overdueLoading.value = false
  }
}
function sortRows(values: DailyTaskRow[]): DailyTaskRow[] {
  const priority = { urgent: 0, high: 1, medium: 2, low: 3 }
  const section = { overdue: 0, today: 1, persistent: 2, completed: 3 }
  return values.sort(
    (a, b) =>
      section[a.section] - section[b.section] ||
      priority[a.task.priority] - priority[b.task.priority] ||
      (a.task.due_date ?? a.task.scheduled_date ?? '9999-12-31').localeCompare(
        b.task.due_date ?? b.task.scheduled_date ?? '9999-12-31',
      ) ||
      a.task.id - b.task.id,
  )
}
function occurrenceChanged(result: CalendarExecutionResult): void {
  const row = allRows().find(
    (value) =>
      value.task.project_id === result.task.project_id &&
      value.task.id === result.task.id &&
      value.occurrence.occurrence_key === result.data.occurrence_key,
  )
  if (
    !row ||
    !data.value ||
    today(data.value.timezone) !== data.value.date ||
    (result.task.execution_type !== 'one_time' &&
      (result.data.occurrence_key !== data.value.date ||
        row.occurrence.is_scheduled === undefined)) ||
    result.task.scheduled_date !== row.task.scheduled_date ||
    result.task.due_date !== row.task.due_date ||
    result.task.scheduled_time !== row.task.scheduled_time ||
    result.task.due_time !== row.task.due_time ||
    result.task.deleted_at ||
    result.task.status === 'archived' ||
    !result.task.show_in_daily_widget
  ) {
    void refresh()
    return
  }
  reviewReads.set(row, (reviewReads.get(row) ?? 0) + 1)
  const oldSection = row.section
  const wasDone = row.occurrence.status === 'done'
  const done = result.data.status === 'done'
  const scheduled = row.occurrence.is_scheduled === true
  row.task = result.task
  row.occurrence = { ...row.occurrence, ...result.data }
  const previous = reviewsFor(row)
  row.reviews = {
    ...previous,
    review_context: { ...previous.review_context, ...result.review_context },
  }
  let next: DailyTaskRow['section'] | null
  if (done) next = 'completed'
  else if (row.task.execution_type === 'persistent') next = 'persistent'
  else if (row.task.execution_type === 'recurring') next = 'today'
  else {
    const deadline = row.task.due_date ?? row.task.scheduled_date
    const time = row.task.due_date !== null ? row.task.due_time : row.task.scheduled_time
    const now = new Intl.DateTimeFormat('en-GB', {
      timeZone: data.value.timezone,
      hourCycle: 'h23',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date())
    const overdue =
      deadline !== null &&
      (deadline < date.value || (deadline === date.value && time !== null && time < now))
    next = overdue
      ? 'overdue'
      : row.task.scheduled_date === date.value || row.task.due_date === date.value
        ? 'today'
        : null
  }
  rows.value = rows.value.filter((value) => rowKey(value) !== rowKey(row))
  completedRows.value = completedRows.value.filter((value) => rowKey(value) !== rowKey(row))
  if (next) {
    row.section = next
    if (next === 'completed') completedRows.value = sortRows([...completedRows.value, row])
    else rows.value = sortRows([...rows.value, row])
  }
  if (scheduled) data.value.summary.completed += Number(done) - Number(wasDone)
  if (oldSection !== next) {
    data.value.counts[oldSection] = Math.max(0, data.value.counts[oldSection] - 1)
    if (next) data.value.counts[next]++
    const active = (section: DailyTaskRow['section'] | null) =>
      section === 'today' || section === 'persistent'
    if (active(oldSection) || active(next)) activePageDirty.value = true
    if (oldSection === 'overdue' || next === 'overdue') overduePageDirty.value = true
    if (oldSection === 'completed' || next === 'completed') completedPageDirty.value = true
    data.value.pagination.total = data.value.counts.today + data.value.counts.persistent
    data.value.pagination.last_page = Math.max(1, Math.ceil(data.value.pagination.total / 20))
    overdueLast.value = Math.max(1, Math.ceil(data.value.counts.overdue / 10))
    completedLast.value = Math.max(1, Math.ceil(data.value.counts.completed / 20))
  }
}
async function selectOccurrence(row: DailyTaskRow, selected: string): Promise<void> {
  // Preserve the existing explicit execution-date picker. Arrow navigation never calls this.
  if (busy.value || selected === row.occurrence.occurrence_key) return
  setBusy(true)
  const version = sequence
  try {
    const [occurrence, reviews] = await Promise.all([
      (row.task.execution_type === 'recurring'
        ? projectTasksApi
            .occurrences(row.task.project_id, row.task.id, selected, selected)
            .then((response) => ({ data: response.data[0] ?? null }))
        : projectTasksApi.occurrence(row.task.project_id, row.task.id, selected)
      ).catch((failure) => {
        if (failure instanceof ApiError && failure.status === 404) return { data: null }
        throw failure
      }),
      projectProgressApi.reviews(row.task.project_id, row.task.id, selected),
    ])
    if (disposed || version !== sequence || !allRows().includes(row)) return
    row.occurrence =
      occurrence.data ??
      ({
        id: null,
        occurrence_key: selected,
        scheduled_date: selected,
        scheduled_time: row.task.scheduled_time,
        status: 'pending',
        lock_version: 0,
        is_recorded: false,
        is_scheduled: false,
      } as TaskOccurrence)
    row.reviews = reviews
  } catch (failure) {
    notifications.error(taskError(failure))
    if (failure instanceof ApiError && [401, 403, 404].includes(failure.status)) accessChanged()
  } finally {
    setBusy(false)
  }
}
function closeEditor(): void {
  noteTask.value = null
  noteProject.value = null
  creating.value = false
}
function openNote(task: ProjectTask, selectedDate = date.value): void {
  if (busy.value) return
  closeEditor()
  noteTask.value = task
  noteDate.value = selectedDate
  noteProject.value = projects.value[task.project_id] ?? null
}
function saved(): void {
  closeEditor()
  refresh()
  notifications.success('Salvat.')
}
function accessChanged(): void {
  rows.value = []
  data.value = null
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
  if (
    !busy.value &&
    !loading.value &&
    !pendingOverdueCommand.value &&
    !document.querySelector('dialog[open]') &&
    data.value &&
    today(data.value.timezone) !== data.value.date
  )
    void refresh()
}
function foreground(): void {
  if (
    document.visibilityState === 'visible' &&
    !busy.value &&
    !loading.value &&
    !pendingOverdueCommand.value &&
    !document.querySelector('dialog[open]')
  )
    void refresh()
}
let dateTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void load()
  document.addEventListener('visibilitychange', foreground)
  dateTimer = setInterval(checkDate, 60_000)
})
onBeforeUnmount(() => {
  stopNoteDeleted()
  overdueSequence++
  disposed = true
  sequence++
  completedSequence++
  document.removeEventListener('visibilitychange', foreground)
  clearInterval(dateTimer)
})
</script>

<template>
  <section class="daily-tasks" aria-label="Taskurile zilei">
    <header class="daily-tasks__heading">
      <time
        class="daily-tasks__date-tile"
        :datetime="date"
        :aria-label="displayDate(date)"
        :title="displayDate(date)"
      >
        <span>{{ headerDate.label }}</span
        ><strong>{{ headerDate.day }}</strong>
      </time>
      <div class="daily-tasks__title">
        <h2>Taskurile zilei</h2>
        <span v-if="data" class="daily-tasks__completion"
          >{{ data.summary.completed }}/{{ data.summary.scheduled }} finalizate</span
        >
      </div>
      <AppButton variant="secondary" :disabled="busy" @click="openCreate">+ Task</AppButton>
    </header>
    <div v-if="data" class="daily-tasks__summary">
      <progress
        :value="completion"
        max="100"
        :aria-label="`${data.summary.completed} din ${data.summary.scheduled} taskuri programate astăzi finalizate`"
      />
    </div>
    <p v-if="loading" role="status" class="projects-muted">Se încarcă taskurile…</p>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <button class="task-quick" type="button" :disabled="busy || loading" @click="load()">
        Reîncearcă
      </button>
    </div>
    <ProjectProgressNoteEditor
      v-if="noteTask && noteProject"
      :key="noteTask.id"
      :project="noteProject"
      :initial-tasks="[{ id: noteTask.id, title: noteTask.title, deleted_at: null }]"
      :initial-date="noteDate"
      :timezone="data?.timezone ?? 'UTC'"
      @busy="setBusy"
      @saved="saved"
      @changed="refresh"
      @cancel="closeEditor"
      @access-changed="accessChanged"
    />
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
    <section
      v-if="currentOverdue || (data?.counts.overdue ?? 0) > 0"
      class="daily-tasks__group"
      aria-label="Taskuri restante"
    >
      <header class="daily-tasks__carousel-heading">
        <h3>Restante · {{ data?.counts.overdue ?? overdueRows.length }}</h3>
        <nav v-if="overdueRows.length > 1" aria-label="Navigare restante">
          <button
            type="button"
            aria-label="Restanța anterioară"
            title="Anterior"
            :disabled="busy || loading || pendingOverdueCommand || overdueIndex === 0"
            @click="navigateOverdue(-1)"
          >
            ‹
          </button>
          <span aria-live="polite">{{ overdueIndex + 1 }}/{{ overdueRows.length }}</span>
          <button
            type="button"
            aria-label="Restanța următoare"
            title="Următor"
            :disabled="
              busy || loading || pendingOverdueCommand || overdueIndex === overdueRows.length - 1
            "
            @click="navigateOverdue(1)"
          >
            ›
          </button>
        </nav>
      </header>
      <article
        v-if="currentOverdue"
        class="daily-tasks__row daily-tasks__row--overdue daily-tasks__slide"
        @pointerdown="swipeStart"
        @pointerup="swipeEnd"
        @pointercancel="swipe = null"
        @click.capture="swipeClick"
      >
        <ProjectTaskCard
          v-if="projects[currentOverdue.task.project_id]"
          :key="rowKey(currentOverdue)"
          :project="projects[currentOverdue.task.project_id]!"
          :task="currentOverdue.task"
          :timezone="data?.timezone ?? 'UTC'"
          :selected-date="
            currentOverdue.occurrence.occurrence_key === 'single'
              ? date
              : currentOverdue.occurrence.occurrence_key
          "
          :calendar-occurrence="currentOverdue.occurrence"
          :calendar-reviews="reviewsFor(currentOverdue)"
          :calendar-data="cardData(currentOverdue)"
          @review-changed="reviewChanged"
          @occurrence-changed="occurrenceChanged"
          @calendar-read="read(currentOverdue!, $event)"
          @calendar-date="selectOccurrence(currentOverdue!, $event)"
          :locked="busy || loading"
          compact
          widget-compact
          @pending-command="pendingOverdueCommand = $event"
          @busy="setBusy"
          @changed="refresh"
          @progress="openNote"
          @access-changed="accessChanged"
        >
          <template #widget-meta>
            <div class="daily-tasks__secondary">
              <RouterLink
                :to="{ name: 'project-detail', params: { id: currentOverdue.task.project_id } }"
                >{{ projects[currentOverdue.task.project_id]?.name }}</RouterLink
              ><span
                :title="
                  displayDate(currentOverdue.task.due_date ?? currentOverdue.task.scheduled_date)
                "
                >{{ overdueAge(currentOverdue) }}</span
              >
            </div>
          </template>
        </ProjectTaskCard>
      </article>
      <p v-if="overdueError" class="projects-error" role="alert">{{ overdueError }}</p>
      <button
        v-if="
          overdueError ||
          overduePage < overdueLast ||
          (!currentOverdue && (data?.counts.overdue ?? 0) > 0)
        "
        class="task-quick"
        type="button"
        :disabled="busy || loading || overdueLoading || pendingOverdueCommand"
        @click="loadOverdue"
      >
        {{ overdueLoading ? 'Se încarcă…' : 'Încarcă încă 10 restante' }}
      </button>
    </section>
    <template v-for="section in sections" :key="section.key">
      <section v-if="visible.some((row) => row.section === section.key)" class="daily-tasks__group">
        <h3>
          {{ section.label }} <span>{{ data?.counts[section.key] }}</span>
        </h3>
        <article
          v-for="row in visible.filter((row) => row.section === section.key)"
          :key="rowKey(row)"
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
            :selected-date="selectedDate(row)"
            :calendar-occurrence="row.occurrence"
            :calendar-reviews="reviewsFor(row)"
            :calendar-data="cardData(row)"
            @review-changed="reviewChanged"
            @occurrence-changed="occurrenceChanged"
            @calendar-read="read(row, $event)"
            @calendar-date="selectOccurrence(row, $event)"
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
      v-if="
        !expanded &&
        ((data?.pagination.total ?? 0) > 5 ||
          rows.filter((row) => row.section !== 'overdue').length > 5)
      "
      class="daily-tasks__footer-link"
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
        :key="rowKey(row)"
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
          :selected-date="selectedDate(row)"
          :calendar-occurrence="row.occurrence"
          :calendar-reviews="reviewsFor(row)"
          :calendar-data="cardData(row)"
          @review-changed="reviewChanged"
          @occurrence-changed="occurrenceChanged"
          @calendar-read="read(row, $event)"
          @calendar-date="selectOccurrence(row, $event)"
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
  display: grid;
  grid-template-columns: 56px minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
}
.daily-tasks__title {
  min-width: 0;
  text-align: center;
}
.daily-tasks__completion {
  display: block;
  margin-top: 4px;
  font-size: 0.76rem;
  color: var(--color-text-muted);
}
.daily-tasks__heading h2 {
  text-align: center;
  overflow-wrap: anywhere;
}
.daily-tasks__date-tile {
  display: grid;
  justify-items: center;
  gap: 4px;
  padding: 8px 4px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.daily-tasks__date-tile span {
  font-size: 0.8rem;
}
.daily-tasks__date-tile strong {
  font-size: 1.3rem;
  line-height: 1.1;
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

<style scoped>
.daily-tasks {
  position: relative;
  min-width: 0;
  gap: 8px;
  padding: 16px 12px 8px;
}
.daily-tasks::before {
  position: absolute;
  inset: 0 0 auto;
  height: 7px;
  border-radius: var(--radius-md) var(--radius-md) 0 0;
  background: linear-gradient(
    90deg,
    var(--color-primary) 0 52%,
    var(--color-yellow) 52% 74%,
    var(--color-pink) 74%
  );
  content: '';
}
.daily-tasks__summary {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 4px 8px;
  margin: 0;
  font-size: 0.78rem;
}
.daily-tasks__summary span {
  display: inline;
  margin: 0;
}
.daily-tasks__summary progress {
  width: 100%;
  height: 4px;
  border: 0;
  border-radius: 2px;
  overflow: hidden;
  appearance: none;
  background: var(--color-surface-soft);
  color: var(--color-primary);
}
.daily-tasks__summary progress::-webkit-progress-bar {
  background: var(--color-surface-soft);
}
.daily-tasks__summary progress::-webkit-progress-value {
  background: var(--color-primary);
}
.daily-tasks__summary progress::-moz-progress-bar {
  background: var(--color-primary);
}
.daily-tasks__carousel-heading {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 4px;
}
.daily-tasks__carousel-heading nav {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}
.daily-tasks__carousel-heading button {
  width: 44px;
  height: 44px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-primary-strong);
  font: inherit;
  font-size: 1.3rem;
  cursor: pointer;
}
.daily-tasks__carousel-heading button:disabled {
  opacity: 0.4;
  cursor: default;
}
.daily-tasks__slide {
  touch-action: pan-y;
}
.daily-tasks__secondary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px 8px;
  font-size: 0.72rem;
  color: var(--color-text-muted);
}
.daily-tasks__secondary a {
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
  color: inherit;
}
.daily-tasks__secondary span {
  flex: none;
}
.daily-tasks__footer-link {
  justify-self: start;
  min-height: 44px;
  padding: 4px 0;
  border: 0;
  background: transparent;
  font: inherit;
  font-size: 0.8rem;
  color: var(--color-primary-strong);
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 3px;
}
.daily-tasks__completed > summary {
  padding-block: 4px;
}
.daily-tasks__group {
  gap: 4px;
}
.daily-tasks__row :deep(.task-card--compact) {
  padding: 4px 6px;
  gap: 4px;
}
.daily-tasks__row :deep(.task-card--widget > details:not([open])) {
  margin: 0;
  padding: 0;
}
</style>
