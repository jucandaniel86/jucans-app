<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskReviews from '@/components/projects/ProjectTaskReviews.vue'
import TaskOccurrenceStatusControl from '@/components/projects/TaskOccurrenceStatusControl.vue'
import ProjectTaskForm from '@/components/projects/ProjectTaskForm.vue'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { ProgressNote } from '@/types/projectProgress'
import type { Project } from '@/types/projects'
import type {
  ExecutionAction,
  CalendarTaskData,
  CalendarReviews,
  CalendarReadRequest,
  CalendarExecutionResult,
  TaskPage,
  ProjectTask,
  TaskCompletionEvent,
  TaskOccurrence,
  TaskSchedule,
} from '@/types/projectTasks'
import {
  displayDate,
  scheduleLabel,
  shiftDate,
  taskError,
  taskPriorities,
  taskStates,
  taskTypes,
  taskIsOverdue,
  executionRequestId,
  today,
} from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    project: Project
    task: ProjectTask
    timezone?: string
    trash?: boolean
    locked?: boolean
    compact?: boolean
    widgetCompact?: boolean
    selectedDate?: string
    calendarOccurrence?: TaskOccurrence | null
    calendarData?: CalendarTaskData
    calendarReviews?: CalendarReviews
  }>(),
  { timezone: 'UTC', trash: false, locked: false, compact: false, widgetCompact: false },
)
const emit = defineEmits<{
  changed: []
  reviewChanged: [note: ProgressNote]
  occurrenceChanged: [result: CalendarExecutionResult]
  calendarRead: [request: CalendarReadRequest]
  calendarDate: [date: string]
  pendingCommand: [value: boolean]
  busy: [value: boolean]
  accessChanged: []
  progress: [task: ProjectTask, date: string]
}>()
const auth = useAuthStore()
const notifications = useNotificationStore()
const task = ref(props.task)
const expanded = ref(false)
const widgetDetails = ref<HTMLDetailsElement | null>(null)
const editing = ref(false)
const detailsId = useId()
const actionMenu = ref<HTMLDetailsElement | null>(null)
const menuPanel = ref<HTMLElement | null>(null)
const menuOpen = ref(false)
const menuStyle = ref<Record<string, string>>({})
const shownStatus = ref<TaskOccurrence['status']>(props.calendarOccurrence?.status ?? 'pending')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const executionError = ref('')
const occurrence = ref<TaskOccurrence | null>(null)
const eligible = ref(false)
const next = ref<TaskOccurrence | null>(null)
const nextLoaded = ref(false)
const nextError = ref('')
const date = ref(props.selectedDate ?? today(props.timezone))
const currentRule = ref<TaskSchedule | null>(null)
const latestRule = ref<TaskSchedule | null>(null)
const ruleError = ref('')
const history = ref<TaskCompletionEvent[]>([])
const historyLoading = ref(false)
const historyError = ref('')
const historyPage = ref(0)
const historyLastPage = ref(1)
const historyEnd = ref(today(props.timezone))
const historyStart = ref(shiftDate(historyEnd.value, -29))
const command = ref<{
  key: string
  action: ExecutionAction
  request_id: string
  lock_version: number
} | null>(null)
watch(command, (value) => emit('pendingCommand', Boolean(value)), { flush: 'sync' })
let readSequence = 0
let historySequence = 0
let nextSequence = 0
const key = computed(() => (task.value.execution_type === 'one_time' ? 'single' : date.value))
const activeProject = computed(() => props.project.status === 'active')
const executionWritable = computed(
  () =>
    activeProject.value &&
    !props.trash &&
    task.value.status !== 'archived' &&
    (task.value.status === 'active' || task.value.execution_type === 'one_time'),
)
const canManage = computed(
  () =>
    activeProject.value &&
    (props.project.created_by === auth.user?.id || task.value.created_by === auth.user?.id),
)
const canExecute = computed(
  () =>
    activeProject.value &&
    !props.trash &&
    eligible.value &&
    !executionError.value &&
    !loading.value &&
    (task.value.status === 'active' ||
      (task.value.execution_type === 'one_time' &&
        task.value.status === 'completed' &&
        occurrence.value?.status === 'done')),
)
const disabled = computed(() => busy.value || props.locked)
const occurrenceDate = computed(
  () =>
    occurrence.value?.scheduled_date ??
    (task.value.execution_type === 'one_time' ? task.value.scheduled_date : date.value),
)
const overdue = computed(() => {
  const projected = { ...task.value, scheduled_date: occurrenceDate.value }
  const relevant = projected.due_date ?? projected.scheduled_date
  return (
    !props.trash &&
    !loading.value &&
    eligible.value &&
    shownStatus.value === 'pending' &&
    relevant !== null &&
    relevant < today(props.timezone) &&
    taskIsOverdue(projected, props.timezone)
  )
})
const displayedRule = computed(() => {
  const rules = [latestRule.value, currentRule.value].filter(
    (rule): rule is TaskSchedule =>
      rule !== null &&
      rule.effective_from <= date.value &&
      (!rule.start_date || rule.start_date <= date.value) &&
      (!rule.end_date || date.value <= rule.end_date),
  )
  return rules.sort((a, b) => b.effective_from.localeCompare(a.effective_from))[0]
})
const recurrenceLabel = computed(() => {
  const rule = displayedRule.value
  if (!rule) return 'Recurent'
  if (rule.recurrence_type === 'weekdays') return 'Săptămânal'
  if (rule.recurrence_type === 'monthly') return 'Lunar'
  return scheduleLabel({ ...rule, scheduled_time: null })
})
watch(
  occurrence,
  (value) => {
    if (value) shownStatus.value = value.status
  },
  { flush: 'sync' },
)
watch(
  [key, () => props.task.id],
  () => {
    shownStatus.value = 'pending'
  },
  { flush: 'sync' },
)

watch(canManage, (value) => {
  if (!value) closeMenu()
})
function closeMenu(): void {
  if (actionMenu.value) actionMenu.value.open = false
  menuOpen.value = false
}
async function positionMenu(): Promise<void> {
  await nextTick()
  const trigger = actionMenu.value?.querySelector('summary')
  if (!menuOpen.value || !trigger || !menuPanel.value) return
  const rect = trigger.getBoundingClientRect()
  const viewport = window.visualViewport
  const left = viewport?.offsetLeft ?? 0,
    top = viewport?.offsetTop ?? 0
  const width = viewport?.width ?? window.innerWidth,
    height = viewport?.height ?? window.innerHeight
  const panelWidth = Math.min(200, width - 24)
  const panelHeight = Math.min(menuPanel.value.scrollHeight, height - 24)
  menuStyle.value = {
    width: `${panelWidth}px`,
    maxHeight: `${height - 24}px`,
    left: `${Math.max(left + 12, Math.min(rect.right - panelWidth, left + width - panelWidth - 12))}px`,
    top: `${Math.max(top + 12, Math.min(rect.bottom + 4, top + height - panelHeight - 12))}px`,
  }
}
function outsideMenu(event: PointerEvent): void {
  if (
    !actionMenu.value?.contains(event.target as Node) &&
    !menuPanel.value?.contains(event.target as Node)
  )
    closeMenu()
}
function menuKey(event: KeyboardEvent): void {
  if (event.key !== 'Escape') return
  event.preventDefault()
  closeMenu()
  actionMenu.value?.querySelector('summary')?.focus({ preventScroll: true })
}
async function keyboardMenu(): Promise<void> {
  if (!actionMenu.value) return
  actionMenu.value.open = !actionMenu.value.open
  menuOpen.value = actionMenu.value.open
  await positionMenu()
  if (menuOpen.value) menuPanel.value?.querySelector('button')?.focus({ preventScroll: true })
}
function editFromMenu(): void {
  closeMenu()
  actionMenu.value?.querySelector('summary')?.focus({ preventScroll: true })
  editing.value = true
  if (widgetDetails.value) widgetDetails.value.open = true
}
function deleteFromMenu(): void {
  closeMenu()
  actionMenu.value?.querySelector('summary')?.focus({ preventScroll: true })
  void manage('delete')
}
watch(menuOpen, (value) => {
  if (value) {
    void positionMenu()
    document.addEventListener('pointerdown', outsideMenu)
    document.addEventListener('keydown', menuKey)
    window.addEventListener('resize', positionMenu)
    document.addEventListener('scroll', positionMenu, true)
    window.visualViewport?.addEventListener('resize', positionMenu)
  } else {
    document.removeEventListener('pointerdown', outsideMenu)
    document.removeEventListener('keydown', menuKey)
    window.removeEventListener('resize', positionMenu)
    document.removeEventListener('scroll', positionMenu, true)
    window.visualViewport?.removeEventListener('resize', positionMenu)
  }
})
function setBusy(value: boolean): void {
  busy.value = value
  emit('busy', value)
}
function accessFailure(failure: unknown): void {
  if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
}
async function loadOccurrence(force = false): Promise<void> {
  if (props.trash) return
  const request = ++readSequence
  if (task.value.execution_type !== 'one_time' && !/^\d{4}-\d{2}-\d{2}$/.test(date.value)) {
    eligible.value = false
    occurrence.value = null
    loading.value = false
    return
  }
  loading.value = true
  executionError.value = ''
  eligible.value = false
  occurrence.value = null
  if (!force && props.calendarOccurrence !== undefined && date.value === props.selectedDate) {
    occurrence.value = props.calendarOccurrence
    eligible.value =
      task.value.execution_type !== 'recurring' || props.calendarOccurrence?.is_scheduled === true
    loading.value = false
    return
  }
  try {
    const response = await projectTasksApi.occurrence(props.project.id, task.value.id, key.value)
    if (request !== readSequence) return
    occurrence.value = response.data
    eligible.value = true
  } catch (failure) {
    if (request !== readSequence) return
    if (failure instanceof ApiError && failure.status === 404) {
      try {
        await projectTasksApi.get(props.project.id, task.value.id)
      } catch (missing) {
        if (request === readSequence) {
          executionError.value = taskError(missing)
          accessFailure(missing)
        }
        return
      }
      if (request !== readSequence) return
      // Missing single/persistent projections are valid initial state; recurring dates require eligibility.
      eligible.value = task.value.execution_type !== 'recurring'
    } else {
      executionError.value = taskError(failure)
      accessFailure(failure)
    }
  } finally {
    if (request === readSequence) loading.value = false
  }
}
function calendarRead<T>(
  input: Omit<CalendarReadRequest, 'taskId' | 'resolve' | 'reject'>,
): Promise<T> {
  return new Promise((resolve, reject) =>
    emit('calendarRead', {
      ...input,
      taskId: task.value.id,
      resolve: (value) => resolve(value as T),
      reject,
    }),
  )
}
async function loadNext(): Promise<void> {
  if (props.compact && !expanded.value) return
  if (task.value.execution_type !== 'recurring' || props.trash) return
  const request = ++nextSequence
  nextLoaded.value = false
  nextError.value = ''
  next.value = null
  try {
    const from = today(props.timezone)
    const to = shiftDate(from, 365)
    let page = 1
    do {
      const response = props.calendarData
        ? await calendarRead<TaskPage<TaskOccurrence>>({ kind: 'next', from, to, page })
        : await projectTasksApi.occurrences(props.project.id, task.value.id, from, to, page)
      if (request !== nextSequence) return
      next.value = response.data.find((row) => row.is_scheduled && row.status === 'pending') ?? null
      if (next.value || page >= response.meta.last_page) break
      page++
    } while (page <= 4)
    nextLoaded.value = true
  } catch (failure) {
    if (request === nextSequence) {
      nextError.value = taskError(failure)
      accessFailure(failure)
    }
  }
}
async function loadRules(): Promise<void> {
  if (props.calendarData) {
    currentRule.value = props.calendarData.current_rule
    latestRule.value = props.calendarData.latest_rule
    return
  }
  if (task.value.execution_type !== 'recurring' || props.trash) return
  ruleError.value = ''
  try {
    const [current, latest] = await Promise.all([
      projectTasksApi.schedule(props.project.id, task.value.id),
      projectTasksApi.scheduleVersions(props.project.id, task.value.id),
    ])
    currentRule.value = current.data
    latestRule.value = latest.data[0] ?? null
  } catch (failure) {
    ruleError.value = taskError(failure)
    accessFailure(failure)
  }
}
async function loadHistory(more = false): Promise<void> {
  if (props.trash) return
  const request = ++historySequence
  historyLoading.value = true
  historyError.value = ''
  if (!more) {
    history.value = []
    historyPage.value = 0
    historyLastPage.value = 1
  }
  try {
    const response = props.calendarData
      ? await calendarRead<TaskPage<TaskCompletionEvent>>({
          kind: 'history',
          from: historyStart.value,
          to: historyEnd.value,
          page: more ? historyPage.value + 1 : 1,
        })
      : await projectTasksApi.history(
          props.project.id,
          task.value.id,
          historyStart.value,
          historyEnd.value,
          more ? historyPage.value + 1 : 1,
        )
    if (request !== historySequence) return
    history.value = more ? [...history.value, ...response.data] : response.data
    historyPage.value = response.meta.current_page
    historyLastPage.value = response.meta.last_page
  } catch (failure) {
    if (request === historySequence) {
      historyError.value = taskError(failure)
      accessFailure(failure)
    }
  } finally {
    if (request === historySequence) historyLoading.value = false
  }
}
function historyPeriod(days: number): void {
  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(historyStart.value) ||
    !/^\d{4}-\d{2}-\d{2}$/.test(historyEnd.value)
  ) {
    historyError.value = 'Alege ambele date ale intervalului.'
    return
  }
  historyStart.value = shiftDate(historyStart.value, days)
  historyEnd.value = shiftDate(historyEnd.value, days)
  void loadHistory()
}
async function refresh(force = false): Promise<void> {
  if (props.calendarData) {
    if (force) await calendarRead<CalendarTaskData>({ kind: 'refresh' })
    return
  }
  eligible.value = false
  try {
    task.value = (await projectTasksApi.get(props.project.id, task.value.id)).data
    await Promise.all([
      loadOccurrence(true),
      loadNext(),
      ...(expanded.value ? [loadHistory(), loadRules()] : []),
    ])
  } catch (failure) {
    error.value = taskError(failure)
    accessFailure(failure)
  }
}
async function execute(action: ExecutionAction, retry = false): Promise<void> {
  if (
    disabled.value ||
    !executionWritable.value ||
    (!retry && (!canExecute.value || command.value))
  )
    return
  setBusy(true)
  error.value = ''
  if (!retry)
    command.value = {
      key: key.value,
      action,
      request_id: executionRequestId(),
      lock_version: occurrence.value?.lock_version ?? 0,
    }
  const pending = command.value!
  try {
    const response = await projectTasksApi.execute(
      props.project.id,
      task.value.id,
      pending.key,
      pending.action,
      pending,
    )
    if (props.calendarData) {
      if (!response.task || !response.review_context)
        throw new Error('Răspunsul serverului nu include datele PERF-01. Reîncarcă intervalul.')
      command.value = null
      task.value = response.task
      occurrence.value = { ...occurrence.value, ...response.data }
      emit('occurrenceChanged', {
        data: response.data,
        task: response.task,
        review_context: response.review_context,
      })
      nextLoaded.value = false
      if (expanded.value) await Promise.all([loadHistory(), loadNext()])
    } else {
      command.value = null
      await refresh()
      emit('changed')
    }
  } catch (failure) {
    const ambiguous =
      !(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500
    error.value = ambiguous
      ? 'Rezultatul nu a fost confirmat. Reîncearcă aceeași cerere în siguranță.'
      : taskError(failure)
    if (!ambiguous) {
      command.value = null
      await refresh(true)
      if (!props.calendarData) emit('changed')
      accessFailure(failure)
    }
  } finally {
    setBusy(false)
  }
}
async function manage(
  action: 'archive' | 'reactivate' | 'delete' | 'restore' | 'complete',
): Promise<void> {
  if (!canManage.value || disabled.value || command.value) return
  setBusy(true)
  error.value = ''
  try {
    const titles = {
      archive: 'Arhivezi taskul?',
      reactivate: 'Reactivezi taskul?',
      delete: 'Muți taskul în Trash?',
      restore: 'Restaurezi taskul?',
      complete: 'Finalizezi definitiv taskul persistent?',
    }
    if (
      !(await notifications.confirm({
        title: titles[action],
        message:
          action === 'complete'
            ? 'Taskul nu va mai primi Done Today. Istoricul se păstrează.'
            : action === 'delete'
              ? 'Taskul poate fi restaurat din Trash. Istoricul se păstrează.'
              : 'Istoricul execuțiilor se păstrează.',
        confirmText: 'Confirmă',
      }))
    )
      return
    if (action === 'delete') await projectTasksApi.delete(props.project.id, task.value.id)
    else if (action === 'restore') await projectTasksApi.restore(props.project.id, task.value.id)
    else if (action === 'complete') await projectTasksApi.complete(props.project.id, task.value.id)
    else
      await projectTasksApi.update(props.project.id, task.value.id, {
        status: action === 'archive' ? 'archived' : 'active',
      })
    if (!props.trash && action !== 'delete') await refresh()
    emit('changed')
  } catch (failure) {
    error.value = taskError(failure)
    if (failure instanceof ApiError && [403, 404, 409].includes(failure.status)) {
      emit('changed')
      accessFailure(failure)
    }
  } finally {
    setBusy(false)
  }
}
function toggle(event: Event): void {
  expanded.value = (event.target as HTMLDetailsElement).open
  if (expanded.value) {
    void loadNext()
    void loadHistory()
    void loadRules()
  }
}
async function saved(): Promise<void> {
  editing.value = false
  await refresh()
  emit('changed')
}
watch(
  [() => props.task, () => props.calendarOccurrence],
  ([value]) => {
    if (props.selectedDate && !command.value) date.value = props.selectedDate
    task.value = value
    if (!props.trash) {
      void loadOccurrence()
      if (!props.calendarData) void loadNext()
      void loadRules()
    }
  },
  { immediate: true },
)
watch(
  () => props.selectedDate,
  (value) => {
    if (value && !command.value) date.value = value
  },
)
watch(date, () => {
  if (command.value || task.value.execution_type === 'one_time') return
  if (
    props.calendarData &&
    date.value !== props.selectedDate &&
    /^\d{4}-\d{2}-\d{2}$/.test(date.value)
  ) {
    emit('calendarDate', date.value)
    return
  }
  void loadOccurrence()
})
onBeforeUnmount(() => {
  closeMenu()
  document.removeEventListener('pointerdown', outsideMenu)
  document.removeEventListener('keydown', menuKey)
  window.removeEventListener('resize', positionMenu)
  document.removeEventListener('scroll', positionMenu, true)
  window.visualViewport?.removeEventListener('resize', positionMenu)
  emit('pendingCommand', false)
  readSequence++
  nextSequence++
  historySequence++
  if (busy.value) emit('busy', false)
})
</script>

<template>
  <article
    class="task-card task-card--v2"
    :class="{
      'task-card--compact': compact,
      'task-card--expanded': expanded,
      'task-card--widget': widgetCompact,
    }"
  >
    <div class="task-card__information">
      <div class="task-card__topline">
        <button
          class="task-card__title"
          type="button"
          :title="task.title"
          :aria-label="`Detalii: ${task.title}`"
          :aria-expanded="expanded"
          :aria-controls="detailsId"
          @click="widgetDetails && (widgetDetails.open = !widgetDetails.open)"
        >
          {{ task.title }}
        </button>
        <span
          class="task-card__priority"
          :aria-label="`Prioritate: ${taskPriorities[task.priority]}`"
        >
          {{ taskPriorities[task.priority] }}
        </span>
        <details
          v-if="canManage && !trash"
          ref="actionMenu"
          class="task-card__menu"
          @toggle="menuOpen = ($event.target as HTMLDetailsElement).open"
        >
          <summary
            class="task-widget-icon"
            :aria-label="`Acțiuni pentru ${task.title}`"
            title="Acțiuni task"
            @keydown.enter.prevent="keyboardMenu"
            @keydown.space.prevent="keyboardMenu"
            @keydown.down.prevent="keyboardMenu"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <circle cx="12" cy="5" r="1.8" />
              <circle cx="12" cy="12" r="1.8" />
              <circle cx="12" cy="19" r="1.8" />
            </svg>
          </summary>
        </details>
      </div>
      <div class="task-card__timing">
        <span class="task-card__date" :class="{ 'task-card__date--overdue': overdue }">
          <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            aria-hidden="true"
          >
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M16 3v4M8 3v4M3 11h18" />
          </svg>
          {{ displayDate(occurrenceDate, occurrence?.scheduled_time ?? task.scheduled_time) }}
          <span v-if="overdue">· Restant</span>
        </span>
        <template v-if="task.execution_type === 'recurring'">
          <span aria-hidden="true">·</span>
          <span
            class="task-card__recurrence"
            :title="displayedRule ? scheduleLabel(displayedRule) : recurrenceLabel"
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              aria-hidden="true"
            >
              <path
                d="m17 2 4 4-4 4M3 11V8a2 2 0 0 1 2-2h16M7 22l-4-4 4-4m14-1v3a2 2 0 0 1-2 2H3"
              />
            </svg>
            {{ recurrenceLabel }}
          </span>
        </template>
      </div>
      <slot v-if="widgetCompact" name="widget-meta" />
    </div>
    <div v-if="!trash" class="task-card__primary-actions">
      <ProjectTaskReviews
        :project="project"
        :task="task"
        :occurrence-key="key"
        :status="shownStatus"
        :date="date"
        :timezone="timezone"
        :calendar-reviews="calendarReviews"
        @review-changed="emit('reviewChanged', $event)"
        @calendar-read="emit('calendarRead', $event)"
        :disabled="disabled || loading || Boolean(command)"
        @busy="setBusy"
        @changed="emit('changed')"
        @access-changed="emit('accessChanged')"
      />
      <TaskOccurrenceStatusControl
        :status="shownStatus"
        :label="`Status pentru ${task.title}`"
        :disabled="disabled || !canExecute || Boolean(command)"
        :aria-busy="loading || busy"
        @change="execute"
      />
    </div>
    <p v-if="executionError" class="projects-error" role="alert">{{ executionError }}</p>
    <p v-else-if="!trash && !loading && !eligible" class="projects-muted">
      Fără execuție eligibilă
    </p>
    <Teleport to="body">
      <div
        v-if="menuOpen && canManage && !trash"
        ref="menuPanel"
        class="task-card__menu-items"
        :style="menuStyle"
        role="group"
        :aria-label="`Acțiuni pentru ${task.title}`"
      >
        <button
          type="button"
          class="task-quick"
          :disabled="disabled || Boolean(command)"
          @click="editFromMenu"
        >
          Editează taskul
        </button>
        <button
          type="button"
          class="task-quick task-quick--danger"
          :disabled="disabled || Boolean(command)"
          @click="deleteFromMenu"
        >
          Șterge taskul
        </button>
      </div>
    </Teleport>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <button
        v-if="!command"
        class="task-quick"
        type="button"
        :disabled="disabled"
        @click="refresh(true)"
      >
        Reîncarcă taskul
      </button>
    </div>
    <button
      v-if="command"
      class="task-quick"
      type="button"
      :disabled="disabled || !executionWritable"
      @click="execute(command.action, true)"
    >
      Reîncearcă {{ command.action }} · {{ command.key }}
    </button>
    <details :id="detailsId" ref="widgetDetails" class="task-card__details" @toggle="toggle">
      <summary v-show="false">Detalii și istoric</summary>
      <div class="task-details">
        <div class="task-meta">
          <span>Programat: {{ displayDate(task.scheduled_date, task.scheduled_time) }}</span>
          <span
            v-if="task.due_date !== task.scheduled_date || task.due_time !== task.scheduled_time"
            >Termen: {{ displayDate(task.due_date, task.due_time) }}</span
          >
        </div>
        <p v-if="task.status !== 'active'" class="projects-muted">
          {{ task.status === 'archived' ? 'Arhivat' : 'Finalizat' }}
        </p>
        <p v-if="task.execution_type === 'recurring' && !trash" class="projects-muted">
          {{
            nextError ||
            (next
              ? `Următoarea: ${displayDate(next.scheduled_date, next.scheduled_time)}`
              : nextLoaded
                ? 'Nicio execuție programată de făcut în următoarele 366 de zile.'
                : 'Se caută următoarea execuție…')
          }}
        </p>
        <p v-if="!trash" class="projects-muted">
          {{ task.execution_type === 'one_time' ? 'Execuție unică' : displayDate(date) }} ·
          {{
            occurrence
              ? taskStates[occurrence.status]
              : eligible
                ? 'De făcut'
                : 'Fără execuție eligibilă'
          }}
          {{ occurrence?.is_recorded ? '· înregistrată' : eligible ? '· neînregistrată' : '' }}
        </p>
        <button
          v-if="!trash && !activeProject"
          class="progress-text-action"
          type="button"
          :disabled="disabled"
          @click="emit('progress', task, props.selectedDate ?? date)"
        >
          Vezi progresul
        </button>
        <p v-if="task.description" class="projects-description">{{ task.description }}</p>
        <p class="projects-muted">
          {{ taskTypes[task.execution_type] }} ·
          {{
            task.show_in_daily_widget ? 'Vizibil în widgetul zilnic' : 'Ascuns din widgetul zilnic'
          }}
        </p>
        <template v-if="!trash">
          <label v-if="task.execution_type !== 'one_time'" class="task-date-picker"
            >Data execuției<input
              v-model="date"
              type="date"
              :disabled="disabled || Boolean(command)"
              required
          /></label>
          <button
            v-if="task.execution_type !== 'one_time' && date !== today(timezone)"
            class="task-quick"
            type="button"
            :disabled="disabled || Boolean(command)"
            @click="date = today(timezone)"
          >
            Astăzi
          </button>
          <button
            v-if="executionError || nextError"
            class="projects-text-button"
            type="button"
            :disabled="disabled"
            @click="refresh(true)"
          >
            Reîncarcă starea
          </button>
          <div v-if="task.execution_type === 'recurring'" class="task-rule">
            <p v-if="ruleError" role="alert">{{ ruleError }}</p>
            <p v-else>
              {{
                currentRule
                  ? `Regula curentă: ${scheduleLabel(currentRule)}`
                  : 'Nu există regulă curentă.'
              }}
            </p>
            <p v-if="currentRule">
              {{ displayDate(currentRule.start_date) }} → {{ displayDate(currentRule.end_date) }}
            </p>
            <p v-if="latestRule && latestRule.id !== currentRule?.id">
              Din {{ displayDate(latestRule.effective_from) }}: {{ scheduleLabel(latestRule) }}
            </p>
          </div>
        </template>
        <div v-if="canManage" class="task-buttons">
          <template v-if="trash"
            ><button
              class="task-quick"
              type="button"
              :disabled="disabled"
              @click="manage('restore')"
            >
              Restaurează
            </button></template
          >
          <template v-else>
            <button
              v-if="task.status !== 'archived'"
              class="task-quick"
              type="button"
              :disabled="disabled || Boolean(command)"
              @click="manage('archive')"
            >
              Arhivează
            </button>
            <button
              v-if="task.status !== 'active'"
              class="task-quick"
              type="button"
              :disabled="disabled || Boolean(command)"
              @click="manage('reactivate')"
            >
              Reactivează
            </button>
            <button
              v-if="task.execution_type === 'persistent' && task.status === 'active'"
              class="task-quick"
              type="button"
              :disabled="disabled || Boolean(command)"
              @click="manage('complete')"
            >
              Finalizează definitiv
            </button>
          </template>
        </div>
        <ProjectTaskForm
          v-if="editing && canManage && !trash"
          :project="project"
          :task="task"
          :occurrence-date="date"
          @busy="setBusy"
          @cancel="editing = false"
          @changed="emit('changed')"
          @saved="saved"
          @access-changed="emit('accessChanged')"
        />
        <section v-if="!trash" class="task-history">
          <h4>Istoric execuții</h4>
          <div class="task-form__columns">
            <label
              >De la<input v-model="historyStart" type="date" :disabled="historyLoading" /></label
            ><label
              >Până la<input v-model="historyEnd" type="date" :disabled="historyLoading"
            /></label>
          </div>
          <div class="task-buttons">
            <button
              class="task-quick"
              type="button"
              :disabled="historyLoading"
              @click="historyPeriod(-30)"
            >
              ‹ 30 zile</button
            ><button
              class="task-quick"
              type="button"
              :disabled="historyLoading"
              @click="loadHistory()"
            >
              Aplică intervalul</button
            ><button
              class="task-quick"
              type="button"
              :disabled="historyLoading || historyEnd >= today(timezone)"
              @click="historyPeriod(30)"
            >
              30 zile ›
            </button>
          </div>
          <p class="projects-muted">Maximum 366 de zile per interval. Ordine cronologică.</p>
          <p v-if="historyLoading" role="status">Se încarcă istoricul…</p>
          <p v-if="historyError" class="task-overdue" role="alert">{{ historyError }}</p>
          <p v-if="!historyLoading && !historyError && !history.length" class="projects-muted">
            Nicio acțiune în acest interval.
          </p>
          <ul>
            <li v-for="event in history" :key="event.id">
              <strong
                >{{ taskStates[event.previous_status] }} →
                {{ taskStates[event.new_status] }}</strong
              >
              · {{ displayDate(event.performed_on) }} ·
              {{
                event.actor_id === auth.user?.id
                  ? 'Tu'
                  : (event.actor?.username ?? `Utilizator #${event.actor_id}`)
              }}
              · {{ new Date(event.recorded_at).toLocaleString('ro-RO') }}
            </li>
          </ul>
          <AppButton
            v-if="historyPage < historyLastPage"
            variant="secondary"
            :loading="historyLoading"
            @click="loadHistory(true)"
            >Mai mult istoric</AppButton
          >
        </section>
      </div>
    </details>
  </article>
</template>

<style scoped>
.task-card.task-card--v2 {
  grid-template-columns: minmax(0, 1fr);
  gap: 0;
  min-height: 0;
  padding: 12px;
  opacity: 1;
}
.task-card__information {
  display: grid;
  gap: 4px;
  min-width: 0;
}
.task-card__topline {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
}
.task-card__title {
  flex: 1;
  min-width: 0;
  min-height: 44px;
  padding: 2px 0;
  border: 0;
  background: transparent;
  color: var(--color-text);
  text-align: left;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 650;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  overflow-wrap: anywhere;
  cursor: pointer;
}
.task-card__priority {
  flex: none;
  margin-top: 8px;
  padding: 3px 6px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-soft);
  color: var(--color-text-muted);
  font-size: 0.68rem;
  white-space: nowrap;
}
.task-card__menu {
  flex: none;
  min-width: 0;
}
.task-card__menu > summary.task-widget-icon {
  position: static;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-sm);
  list-style: none;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}
.task-card__menu > summary::-webkit-details-marker {
  display: none;
}
.task-card__timing {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 6px;
  min-width: 0;
  padding-bottom: 8px;
  color: var(--color-text-muted);
  font-size: 0.73rem;
}
.task-card__date,
.task-card__recurrence {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.task-card__timing svg {
  flex: none;
}
.task-card__date--overdue {
  color: var(--color-error);
}
.task-card__primary-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-width: 0;
  border-top: 1px solid var(--color-border);
  padding-top: 6px;
}
.task-card__primary-actions :deep(.task-status-control) {
  flex: 0 1 auto;
}
.task-card.task-card--v2 > .task-card__details {
  margin: 0;
  padding: 0;
  min-width: 0;
}
.task-card.task-card--v2 > .task-card__details[open] {
  border-top: 1px solid var(--color-border);
  margin-top: 8px;
  padding-top: 8px;
}
.task-card__menu-items {
  position: fixed;
  z-index: 1000;
  display: grid;
  gap: 4px;
  padding: 4px;
  box-sizing: border-box;
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}
.task-card__menu-items button {
  justify-content: flex-start;
  text-align: left;
  overflow-wrap: anywhere;
}
.task-card__title:focus-visible,
.task-card__menu > summary:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: -2px;
}
</style>
