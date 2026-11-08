<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskCard from '@/components/projects/ProjectTaskCard.vue'
import { projectProgressApi } from '@/services/projectProgressApi'
import type { ProgressNote } from '@/types/projectProgress'
import { projectTasksApi } from '@/services/projectTasksApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type {
  CalendarTaskData,
  CalendarReadRequest,
  CalendarExecutionResult,
  CalendarReviews,
  ProjectTask,
} from '@/types/projectTasks'
import { shiftDate, taskError, today } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{ project: Project; timezone?: string; locked?: boolean }>(),
  { timezone: 'UTC', locked: false },
)
const emit = defineEmits<{
  changed: []
  summaryChanged: []
  busy: [value: boolean]
  accessChanged: []
  progress: [task: ProjectTask, date: string]
}>()
const localToday = () => today(Intl.DateTimeFormat().resolvedOptions().timeZone)
const selected = ref(localToday())
function monday(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  const weekday = new Date(Date.UTC(year!, month! - 1, day!)).getUTCDay()
  return shiftDate(date, -((weekday + 6) % 7))
}
const week = ref(monday(selected.value))
const end = computed(() => shiftDate(week.value, 6))
const days = computed(() =>
  ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'].map((label, index) => ({
    label,
    date: shiftDate(week.value, index),
  })),
)
const months = [
  'ianuarie',
  'februarie',
  'martie',
  'aprilie',
  'mai',
  'iunie',
  'iulie',
  'august',
  'septembrie',
  'octombrie',
  'noiembrie',
  'decembrie',
]
function longDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return `${day} ${months[month! - 1]} ${year}`
}
const rows = ref<CalendarTaskData[]>([])
const visibleLimit = ref(20)
const tasks = computed(() => rows.value.slice(0, visibleLimit.value).map((row) => row.task))
const page = computed(() => Math.ceil(visibleLimit.value / 20))
const total = computed(() => rows.value.length)
const lastPage = computed(() => Math.max(1, Math.ceil(total.value / 20)))
const loading = ref(false)
const pendingCommands = ref(new Set<number>())
const navigationLocked = computed(() => props.locked || pendingCommands.value.size > 0)
function pendingCommand(task: number, value: boolean): void {
  const pending = new Set(pendingCommands.value)
  if (value) pending.add(task)
  else pending.delete(task)
  pendingCommands.value = pending
}
const undatedOpen = ref(false)
const error = ref('')
const data = computed(() => new Map(rows.value.map((row) => [row.task.id, row])))
let sequence = 0
let disposed = false
let loadingKey = ''
const occurrenceFor = (task: ProjectTask) =>
  data.value
    .get(task.id)
    ?.occurrences.find(
      (row) =>
        row.occurrence_key === (task.execution_type === 'one_time' ? 'single' : selected.value),
    )
const reviewsFor = (task: ProjectTask): CalendarReviews =>
  occurrenceFor(task)?.reviews ?? {
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
    review_context: { mode: task.review_mode ?? 'single', can_create: false, active_count: 0 },
  }
const dated = computed(() =>
  tasks.value.filter((task) => {
    if (task.execution_type === 'one_time') return task.scheduled_date === selected.value
    const row = occurrenceFor(task)
    return task.execution_type === 'recurring'
      ? row?.is_scheduled === true
      : Boolean(row?.is_recorded) || task.scheduled_date === selected.value
  }),
)
const undated = computed(() =>
  tasks.value.filter(
    (task) =>
      task.scheduled_date === null &&
      !dated.value.includes(task) &&
      (task.execution_type !== 'recurring' || data.value.get(task.id)?.has_schedule === false),
  ),
)
async function load(): Promise<void> {
  const identity = `${props.project.id}:${week.value}:${end.value}`
  if (loading.value && loadingKey === identity) return
  const request = ++sequence
  loadingKey = identity
  loading.value = true
  error.value = ''
  try {
    const response = await projectTasksApi.calendar(props.project.id, week.value, end.value)
    if (request !== sequence || disposed) return
    rows.value = response.data
  } catch (failure) {
    if (request !== sequence || disposed) return
    error.value = taskError(failure)
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
  } finally {
    if (request === sequence && !disposed) loading.value = false
  }
}
function reload(task?: ProjectTask): void {
  // An edited visible task can move out of the range. Recurrence changes can
  // affect future dates, so reload those conservatively using the same algorithm.
  if (
    task &&
    !data.value.has(task.id) &&
    task.execution_type === 'one_time' &&
    task.scheduled_date !== null &&
    (task.scheduled_date < week.value || task.scheduled_date > end.value)
  )
    return
  void load()
}
function reviewChanged(note: ProgressNote): void {
  if (!note.review) return
  const row = data.value.get(note.review.task_id)
  const occurrence = row?.occurrences.find(
    (value) => value.occurrence_key === note.review!.occurrence_key,
  )
  if (!row || !occurrence) return
  const previous = occurrence.reviews
  const old = previous.data.find((value) => value.id === note.id)
  const notes = [note, ...previous.data.filter((value) => value.id !== note.id)]
  const total = previous.meta.total + (old ? 0 : 1)
  occurrence.reviews = {
    data: notes,
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
function occurrenceChanged(result: CalendarExecutionResult): void {
  const row = data.value.get(result.task.id)
  if (!row) return
  row.task = result.task
  const index = row.occurrences.findIndex(
    (value) => value.occurrence_key === result.data.occurrence_key,
  )
  const old = row.occurrences[index]
  if (!old) return
  emit('summaryChanged')
  row.occurrences[index] = {
    ...old,
    ...result.data,
    reviews: {
      ...old.reviews,
      review_context: { ...old.reviews.review_context, ...result.review_context },
    },
  }
}
async function read(request: CalendarReadRequest): Promise<void> {
  const id = props.project.id
  const version = sequence
  try {
    let response: unknown
    if (request.kind === 'history')
      response = await projectTasksApi.history(
        id,
        request.taskId,
        request.from!,
        request.to!,
        request.page,
      )
    else if (request.kind === 'next')
      response = await projectTasksApi.occurrences(
        id,
        request.taskId,
        request.from!,
        request.to!,
        request.page,
      )
    else if (request.kind === 'reviews') {
      const result = await projectProgressApi.reviews(
        id,
        request.taskId,
        request.key!,
        request.page,
      )
      if (!disposed && version === sequence) {
        const occurrence = data.value
          .get(request.taskId)
          ?.occurrences.find((value) => value.occurrence_key === request.key)
        if (occurrence) {
          const existing = (request.page ?? 1) > 1 ? occurrence.reviews.data : []
          occurrence.reviews = {
            ...result,
            review_context: { ...occurrence.reviews.review_context, ...result.review_context },
            data: [
              ...new Map([...existing, ...result.data].map((note) => [note.id, note])).values(),
            ],
          }
        }
      }
      response = result
    } else {
      await load()
      response = data.value.get(request.taskId)
    }
    request.resolve(response)
  } catch (failure) {
    request.reject(failure)
  }
}
function selectDate(date: string): void {
  if (navigationLocked.value || loading.value) return
  selected.value = date
  week.value = monday(date)
}
function moveWeek(offset: number): void {
  if (navigationLocked.value || loading.value) return
  selected.value = shiftDate(selected.value, offset * 7)
  week.value = monday(selected.value)
}
function goToday(): void {
  if (navigationLocked.value || loading.value) return
  selected.value = localToday()
  week.value = monday(selected.value)
}
watch(week, () => {
  void load()
})
watch(
  () => props.project.id,
  () => reload(),
  { immediate: true },
)
onBeforeUnmount(() => {
  disposed = true
  sequence++
})
defineExpose({ reload })
</script>

<template>
  <section class="task-calendar" aria-label="Calendar săptămânal">
    <div
      class="task-calendar__navigation"
      :class="{ 'task-calendar__navigation--controls': $slots.controls }"
    >
      <button
        class="task-quick"
        type="button"
        aria-label="Săptămâna precedentă"
        :disabled="navigationLocked || loading"
        @click="moveWeek(-1)"
      >
        ‹
      </button>
      <p>{{ longDate(week) }} – {{ longDate(end) }}</p>
      <button
        class="task-quick"
        type="button"
        aria-label="Săptămâna următoare"
        :disabled="navigationLocked || loading"
        @click="moveWeek(1)"
      >
        ›
      </button>
      <slot name="controls" />
    </div>
    <div class="task-calendar__days" role="group" aria-label="Alege ziua">
      <button
        v-for="day in days"
        :key="day.date"
        type="button"
        :aria-label="longDate(day.date)"
        :aria-pressed="selected === day.date"
        :aria-current="day.date === localToday() ? 'date' : undefined"
        :disabled="navigationLocked || loading"
        @click="selected = day.date"
      >
        <span>{{ day.label }}</span
        ><strong>{{ Number(day.date.slice(-2)) }}</strong>
      </button>
    </div>
    <div class="task-calendar__selection">
      <h3>{{ longDate(selected) }}</h3>
      <button
        class="projects-text-button"
        type="button"
        :disabled="navigationLocked || loading"
        @click="goToday"
      >
        Astăzi
      </button>
    </div>
    <p v-if="loading" role="status" class="projects-muted">Se încarcă săptămâna…</p>
    <div v-else-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" :disabled="locked" @click="reload()">Reîncearcă</AppButton>
    </div>
    <template v-else>
      <p v-if="!dated.length" class="task-empty">
        Niciun task pentru această zi{{ page < lastPage ? ' în paginile încărcate' : '' }}.
      </p>
      <div class="task-list">
        <ProjectTaskCard
          v-for="task in dated"
          :key="task.id"
          :project="project"
          :task="task"
          :timezone="timezone"
          :locked="locked"
          compact
          :selected-date="selected"
          :calendar-occurrence="occurrenceFor(task) ?? null"
          :calendar-data="data.get(task.id)"
          :calendar-reviews="reviewsFor(task)"
          @review-changed="reviewChanged"
          @occurrence-changed="occurrenceChanged"
          @pending-command="pendingCommand(task.id, $event)"
          @calendar-read="read"
          @calendar-date="selectDate"
          @busy="emit('busy', $event)"
          @changed="emit('changed')"
          @access-changed="emit('accessChanged')"
          @progress="(task, date) => emit('progress', task, date)"
        />
      </div>
      <details
        v-if="undated.length"
        class="task-group"
        :open="undatedOpen"
        @toggle="undatedOpen = ($event.target as HTMLDetailsElement).open"
      >
        <summary @click="locked && $event.preventDefault()">
          Fără dată <span>{{ undated.length }}</span>
        </summary>
        <div v-if="undatedOpen" class="task-list">
          <ProjectTaskCard
            v-for="task in undated"
            :key="task.id"
            :project="project"
            :task="task"
            :timezone="timezone"
            :locked="locked"
            compact
            :selected-date="selected"
            :calendar-occurrence="occurrenceFor(task) ?? null"
            :calendar-data="data.get(task.id)"
            :calendar-reviews="reviewsFor(task)"
            @review-changed="reviewChanged"
            @occurrence-changed="occurrenceChanged"
            @pending-command="pendingCommand(task.id, $event)"
            @calendar-read="read"
            @calendar-date="selectDate"
            @busy="emit('busy', $event)"
            @changed="emit('changed')"
            @access-changed="emit('accessChanged')"
            @progress="(task, date) => emit('progress', task, date)"
          />
        </div>
      </details>
      <p v-if="page < lastPage" class="projects-muted">
        Calendarul verifică {{ tasks.length }} din {{ total }} taskuri vizibile. Încarcă următoarea
        pagină pentru rezultate complete.
      </p>
      <AppButton
        v-if="page < lastPage"
        variant="secondary"
        block
        :disabled="locked"
        @click="visibleLimit += 20"
        >Încarcă încă 20 de taskuri</AppButton
      >
    </template>
  </section>
</template>
