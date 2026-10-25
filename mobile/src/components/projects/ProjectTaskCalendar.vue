<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskCard from '@/components/projects/ProjectTaskCard.vue'
import { projectTasksApi } from '@/services/projectTasksApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'
import type { ProjectTask, TaskOccurrence } from '@/types/projectTasks'
import { shiftDate, taskError, today } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{ project: Project; timezone?: string; locked?: boolean }>(),
  { timezone: 'UTC', locked: false },
)
const emit = defineEmits<{
  changed: []
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
const tasks = ref<ProjectTask[]>([])
const page = ref(0)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const undatedOpen = ref(false)
const error = ref('')
type WeekTask = { occurrences: TaskOccurrence[]; hasSchedule: boolean }
const data = ref(new Map<number, WeekTask>())
let cachedWeek = ''
let sequence = 0
let disposed = false
const occurrenceFor = (task: ProjectTask) =>
  data.value.get(task.id)?.occurrences.find((row) => row.scheduled_date === selected.value)
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
      (task.execution_type !== 'recurring' || data.value.get(task.id)?.hasSchedule === false),
  ),
)
async function loadWeek(request: number): Promise<void> {
  const from = week.value
  const to = end.value
  const cache = cachedWeek === from ? new Map(data.value) : new Map<number, WeekTask>()
  const pending = tasks.value.filter(
    (task) => task.execution_type !== 'one_time' && !cache.has(task.id),
  )
  // A range query per dated task covers all seven days, with bounded concurrency.
  for (let index = 0; index < pending.length; index += 4) {
    await Promise.all(
      pending.slice(index, index + 4).map(async (task) => {
        const response = await projectTasksApi.occurrences(props.project.id, task.id, from, to)
        let hasSchedule =
          task.execution_type === 'recurring' && response.data.some((row) => row.is_scheduled)
        if (task.execution_type === 'recurring' && !hasSchedule) {
          hasSchedule =
            (await projectTasksApi.scheduleVersions(props.project.id, task.id)).data.length > 0
        }
        cache.set(task.id, { occurrences: response.data, hasSchedule })
      }),
    )
    if (request !== sequence || disposed) return
  }
  if (request !== sequence || disposed) return
  cachedWeek = from
  data.value = cache
}
async function load(catalog: boolean, more = false): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  try {
    if (catalog) {
      const response = await projectTasksApi.list(props.project.id, {
        page: more ? page.value + 1 : 1,
        per_page: 20,
      })
      if (request !== sequence || disposed) return
      tasks.value = more
        ? [...new Map([...tasks.value, ...response.data].map((task) => [task.id, task])).values()]
        : response.data
      page.value = response.meta.current_page
      lastPage.value = response.meta.last_page
      total.value = response.meta.total
      if (!more) {
        data.value = new Map()
        cachedWeek = ''
      }
    }
    await loadWeek(request)
  } catch (failure) {
    if (request === sequence && !disposed) {
      error.value = taskError(failure)
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
    }
  } finally {
    if (request === sequence && !disposed) loading.value = false
  }
}
function reload(): void {
  void load(true)
}
function moveWeek(offset: number): void {
  if (props.locked || loading.value) return
  selected.value = shiftDate(selected.value, offset * 7)
  week.value = monday(selected.value)
}
function goToday(): void {
  if (props.locked || loading.value) return
  selected.value = localToday()
  week.value = monday(selected.value)
}
watch(week, () => {
  void load(false)
})
watch(() => props.project.id, reload, { immediate: true })
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
        :disabled="locked || loading"
        @click="moveWeek(-1)"
      >
        ‹
      </button>
      <p>{{ longDate(week) }} – {{ longDate(end) }}</p>
      <button
        class="task-quick"
        type="button"
        aria-label="Săptămâna următoare"
        :disabled="locked || loading"
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
        :disabled="locked || loading"
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
        :disabled="locked || loading"
        @click="goToday"
      >
        Astăzi
      </button>
    </div>
    <p v-if="loading" role="status" class="projects-muted">Se încarcă săptămâna…</p>
    <div v-else-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" :disabled="locked" @click="reload">Reîncearcă</AppButton>
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
          :calendar-occurrence="
            task.execution_type === 'one_time' ? undefined : (occurrenceFor(task) ?? null)
          "
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
            :calendar-occurrence="
              task.execution_type === 'one_time' ? undefined : (occurrenceFor(task) ?? null)
            "
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
        @click="load(true, true)"
        >Încarcă încă 20 de taskuri</AppButton
      >
    </template>
  </section>
</template>
