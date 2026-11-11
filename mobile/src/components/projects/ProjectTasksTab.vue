<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import PlusIcon from '@/components/icons/plusIcon.vue'
import TrashIcon from '@/components/icons/trashIcon.vue'
import ProjectTaskCard from '@/components/projects/ProjectTaskCard.vue'
import ProjectTaskForm from '@/components/projects/ProjectTaskForm.vue'
import ProjectTaskCalendar from '@/components/projects/ProjectTaskCalendar.vue'
import ProjectTaskViewToggle from '@/components/projects/ProjectTaskViewToggle.vue'
import { projectProgressApi } from '@/services/projectProgressApi'
import type { ProgressNote } from '@/types/projectProgress'
import { projectTasksApi } from '@/services/projectTasksApi'
import { ApiError } from '@/services/api'
import type { Project, ProjectExecutionType, ProjectStatus } from '@/types/projects'
import type {
  ProjectTask,
  TaskPriority,
  CalendarTaskData,
  CalendarReadRequest,
  CalendarExecutionResult,
} from '@/types/projectTasks'
import { taskError, taskPriorities, taskTypes, today } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    project: Project
    actionsTarget: string
    timezone?: string
    externalBusy?: boolean
  }>(),
  { timezone: 'UTC', externalBusy: false },
)
const emit = defineEmits<{
  changed: []
  busy: [value: boolean]
  accessChanged: []
  progress: [task: ProjectTask, date: string]
}>()
const search = ref('')
const status = ref('active')
const priority = ref('')
const trash = ref(false)
const creating = ref(false)
const mode = ref<'calendar' | 'list'>('calendar')
const calendar = ref<InstanceType<typeof ProjectTaskCalendar> | null>(null)
const busyCount = ref(0)
const locked = computed(() => busyCount.value > 0 || props.externalBusy)
const groups = reactive(
  (Object.keys(taskTypes) as ProjectExecutionType[]).map((type) => ({
    type,
    open: true,
    tasks: [] as ProjectTask[],
    contexts: {} as Record<number, CalendarTaskData>,
    contextDate: today(props.timezone),
    loading: false,
    error: '',
    page: 0,
    lastPage: 1,
    total: 0,
    sequence: 0,
    retryMore: false,
  })),
)
const statuses = [
  { value: 'active', label: 'Active' },
  { value: 'completed', label: 'Finalizate' },
  { value: 'archived', label: 'Arhivate' },
  { value: '', label: 'Toate stările' },
]
const priorities = [
  { value: '', label: 'Toate prioritățile' },
  ...Object.entries(taskPriorities).map(([value, label]) => ({ value, label })),
]
let timer: ReturnType<typeof setTimeout> | undefined
let disposed = false
const inFlight = new Map<ProjectExecutionType, { key: string; controller: AbortController }>()
function cancelLoads(): void {
  for (const entry of inFlight.values()) entry.controller.abort()
  inFlight.clear()
  for (const group of groups) {
    group.sequence++
    group.loading = false
  }
}
function busy(value: boolean): void {
  busyCount.value = Math.max(0, busyCount.value + (value ? 1 : -1))
  emit('busy', locked.value)
}
async function load(group: (typeof groups)[number], more = false): Promise<void> {
  const date = today(props.timezone)
  const page = more ? group.page + 1 : 1
  const filters = {
    execution_type: group.type,
    search: search.value.trim(),
    status: status.value ? (status.value as ProjectStatus) : undefined,
    priority: priority.value ? (priority.value as TaskPriority) : undefined,
    page,
    ...(!trash.value ? { include_context: true, occurrence_date: date } : {}),
  }
  const identity = JSON.stringify([props.project.id, trash.value, filters])
  const previous = inFlight.get(group.type)
  if (previous?.key === identity) return
  previous?.controller.abort()
  const controller = new AbortController()
  inFlight.set(group.type, { key: identity, controller })
  const request = ++group.sequence
  group.loading = true
  group.error = ''
  group.retryMore = more
  try {
    const response = await projectTasksApi.list(props.project.id, filters, trash.value, {
      signal: controller.signal,
    })
    if (request !== group.sequence || disposed || controller.signal.aborted) return
    if (
      !trash.value &&
      (!response.contexts ||
        response.data.some((task) => !response.contexts?.[task.id]?.occurrences[0]?.reviews))
    )
      throw new ApiError('Nu am putut încărca toate datele taskurilor. Reîncearcă.', 503)
    group.contexts = more ? { ...group.contexts, ...response.contexts } : (response.contexts ?? {})
    group.contextDate = date
    group.tasks = more
      ? [...new Map([...group.tasks, ...response.data].map((task) => [task.id, task])).values()]
      : response.data
    group.page = response.meta.current_page
    group.lastPage = response.meta.last_page
    group.total = response.meta.total
  } catch (failure) {
    if (request === group.sequence && !disposed && !controller.signal.aborted) {
      group.error = taskError(failure)
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
        group.tasks = []
        emit('accessChanged')
      }
    }
  } finally {
    if (inFlight.get(group.type)?.controller === controller) inFlight.delete(group.type)
    if (request === group.sequence && !disposed) group.loading = false
  }
}
function reload(): void {
  clearTimeout(timer)
  if (mode.value === 'calendar' && !trash.value) {
    calendar.value?.reload()
    return
  }
  for (const group of groups) void load(group)
}
function changed(): void {
  cancelLoads()
  reload()
  emit('changed')
}
function closeCreate(): void {
  creating.value = false
  reload()
}
function created(task: ProjectTask): void {
  creating.value = false
  if (mode.value === 'calendar' && !trash.value) {
    calendar.value?.reload(task)
    emit('changed')
  } else changed()
}
function toggleGroup(group: (typeof groups)[number], event: Event): void {
  group.open = (event.target as HTMLDetailsElement).open
}
function switchTrash(): void {
  if (locked.value) return
  trash.value = !trash.value
  status.value = ''
  creating.value = false
}
watch(search, (value, previous) => {
  if (value.trim() === previous.trim()) return
  cancelLoads()
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
})
watch(
  [mode, status, priority, trash, () => props.project.id, () => props.project.status],
  () => {
    cancelLoads()
    for (const group of groups) {
      group.tasks = []
      group.contexts = {}
      group.page = 0
      group.total = 0
    }
    reload()
  },
  { immediate: true },
)
function contextDate(group: (typeof groups)[number], task: ProjectTask): string {
  const key = group.contexts[task.id]?.occurrences[0]?.occurrence_key
  return key && key !== 'single' ? key : group.contextDate
}
function reviewChanged(group: (typeof groups)[number], note: ProgressNote): void {
  if (!note.review || note.project_id !== props.project.id || !note.review_context) return
  const context = group.contexts[note.review.task_id]
  const occurrence = context?.occurrences.find(
    (value) => value.occurrence_key === note.review!.occurrence_key,
  )
  if (!occurrence) return
  const previous = occurrence.reviews
  const old = previous.data.find((value) => value.id === note.id)
  const total = previous.meta.total + (old ? 0 : 1)
  occurrence.reviews = {
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
function executed(group: (typeof groups)[number], result: CalendarExecutionResult): void {
  const context = group.contexts[result.task.id]
  const occurrence = context?.occurrences[0]
  // Task status filters/counts are still refreshed by the existing list endpoint.
  if (!context || !occurrence || context.task.status !== result.task.status) {
    changed()
    return
  }
  context.task = result.task
  context.occurrences[0] = {
    ...occurrence,
    ...result.data,
    reviews: {
      ...occurrence.reviews,
      review_context: { ...occurrence.reviews.review_context, ...result.review_context },
    },
  }
  const index = group.tasks.findIndex((task) => task.id === result.task.id)
  if (index >= 0) group.tasks[index] = result.task
  emit('changed')
}
async function read(group: (typeof groups)[number], request: CalendarReadRequest): Promise<void> {
  const project = props.project.id
  const version = group.sequence
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
      const occurrence = group.contexts[request.taskId]?.occurrences.find(
        (value) => value.occurrence_key === request.key,
      )
      if (!disposed && version === group.sequence && project === props.project.id && occurrence) {
        const existing = (request.page ?? 1) > 1 ? occurrence.reviews.data : []
        occurrence.reviews = {
          ...result,
          data: [...new Map([...existing, ...result.data].map((note) => [note.id, note])).values()],
          review_context: { ...occurrence.reviews.review_context, ...result.review_context },
        }
      }
      response = result
    } else {
      await load(group)
      response = group.contexts[request.taskId]
    }
    request.resolve(response)
  } catch (failure) {
    request.reject(failure)
  }
}
async function selectDate(
  group: (typeof groups)[number],
  task: ProjectTask,
  date: string,
): Promise<void> {
  const context = group.contexts[task.id]
  if (!context || contextDate(group, task) === date || locked.value) return
  const version = group.sequence
  const project = props.project.id
  busy(true)
  try {
    const [response, reviews] = await Promise.all([
      projectTasksApi.occurrences(project, task.id, date, date),
      projectProgressApi.reviews(project, task.id, date),
    ])
    if (disposed || version !== group.sequence || project !== props.project.id) return
    const occurrence = response.data[0]
    group.contexts[task.id] = {
      ...context,
      occurrence_eligible: task.execution_type !== 'recurring' || !!occurrence,
      occurrences: [
        {
          ...(occurrence ?? {
            id: null,
            occurrence_key: date,
            scheduled_date: date,
            scheduled_time: task.scheduled_time,
            status: 'pending' as const,
            lock_version: 0,
            is_recorded: false,
            is_scheduled: false,
          }),
          reviews,
        },
      ],
    }
  } catch (failure) {
    group.error = taskError(failure)
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
  } finally {
    busy(false)
  }
}
onBeforeUnmount(() => {
  disposed = true
  cancelLoads()
  clearTimeout(timer)
  emit('busy', false)
})
</script>

<template>
  <section class="project-tasks" aria-label="Taskurile proiectului">
    <div v-if="trash || mode === 'list'" class="task-view-heading">
      <h3>{{ trash ? 'Trash' : 'Taskuri' }}</h3>
      <ProjectTaskViewToggle v-if="!trash" v-model="mode" :disabled="locked" />
    </div>
    <Teleport :to="actionsTarget">
      <div class="task-summary-actions">
        <AppButton
          v-if="project.status === 'active' && !trash"
          :disabled="locked"
          :aria-expanded="creating"
          aria-label="Adaugă task"
          title="Adaugă task"
          @click="creating = !creating"
          ><PlusIcon aria-hidden="true"
        /></AppButton>
        <button
          class="task-quick"
          type="button"
          :disabled="locked"
          :aria-pressed="trash"
          :aria-label="trash ? 'Înapoi la taskuri' : 'Trash'"
          :title="trash ? 'Înapoi la taskuri' : 'Trash'"
          @click="switchTrash"
        >
          <TrashIcon />
        </button>
      </div>
    </Teleport>
    <p v-if="trash" class="projects-notice">
      Trash · numai taskurile pe care le poți administra. Restaurarea nu reactivează automat un task
      arhivat.
    </p>
    <ProjectTaskForm
      v-if="creating && project.status === 'active'"
      :project="project"
      @cancel="closeCreate"
      @busy="busy"
      @changed="changed"
      @saved="created"
      @access-changed="emit('accessChanged')"
    />
    <ProjectTaskCalendar
      v-if="mode === 'calendar' && !trash"
      ref="calendar"
      :project="project"
      :timezone="timezone"
      :locked="locked"
      @busy="busy"
      @changed="changed"
      @summary-changed="emit('changed')"
      @access-changed="emit('accessChanged')"
      @progress="(task, date) => emit('progress', task, date)"
    >
      <template #controls><ProjectTaskViewToggle v-model="mode" :disabled="locked" /></template>
    </ProjectTaskCalendar>
    <template v-else>
      <fieldset class="task-fields task-filters" :disabled="locked">
        <AppInput
          v-model="search"
          label="Caută taskuri"
          placeholder="Titlu sau descriere"
          :maxlength="255"
        />
        <div class="task-form__columns">
          <AppSelect v-model="status" label="Stare" :options="statuses" /><AppSelect
            v-model="priority"
            label="Prioritate"
            :options="priorities"
          />
        </div>
      </fieldset>
      <details
        v-for="group in groups"
        :key="group.type"
        class="task-group"
        :open="group.open"
        @toggle="toggleGroup(group, $event)"
      >
        <summary @click="locked && $event.preventDefault()">
          {{ taskTypes[group.type] }} <span>{{ group.page ? group.total : '…' }}</span>
        </summary>
        <p v-if="group.loading" role="status" class="projects-muted">Se încarcă taskurile…</p>
        <div v-if="group.error" class="projects-error" role="alert">
          <p>{{ group.error }}</p>
          <AppButton variant="secondary" @click="load(group, group.retryMore)"
            >Încearcă din nou</AppButton
          >
        </div>
        <p v-if="!group.loading && !group.error && !group.tasks.length" class="task-empty">
          {{
            trash
              ? 'Niciun task șters în această secțiune.'
              : 'Niciun task pentru filtrele selectate.'
          }}
        </p>
        <div v-if="group.open" class="task-list">
          <ProjectTaskCard
            v-for="task in group.tasks"
            :key="`${trash}-${task.id}`"
            :project="project"
            :task="task"
            :timezone="timezone"
            :trash="trash"
            list-view
            :selected-date="contextDate(group, task)"
            :calendar-data="group.contexts[task.id]"
            :calendar-occurrence="group.contexts[task.id]?.occurrences[0]"
            :calendar-reviews="group.contexts[task.id]?.occurrences[0]?.reviews"
            @review-changed="reviewChanged(group, $event)"
            @occurrence-changed="executed(group, $event)"
            @calendar-read="read(group, $event)"
            @calendar-date="selectDate(group, task, $event)"
            :locked="locked"
            compact
            @busy="busy"
            @changed="changed"
            @access-changed="emit('accessChanged')"
            @progress="(task, date) => emit('progress', task, date)"
          />
        </div>
        <AppButton
          v-if="group.page < group.lastPage"
          variant="secondary"
          block
          :disabled="locked"
          :loading="group.loading"
          @click="load(group, true)"
          >Mai multe taskuri</AppButton
        >
      </details>
    </template>
  </section>
</template>

<style src="./projectTasks.css"></style>
