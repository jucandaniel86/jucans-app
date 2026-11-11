<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskReviews from '@/components/projects/ProjectTaskReviews.vue'
import TaskOccurrenceStatusControl from '@/components/projects/TaskOccurrenceStatusControl.vue'
import ProjectTaskForm from '@/components/projects/ProjectTaskForm.vue'
import ProgressNoteConfirmDialog from '@/components/projects/ProgressNoteConfirmDialog.vue'
import './projectDateInputs.css'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import { addSavedTaskToPhoneCalendar, phoneCalendarAvailable } from '@/services/taskPhoneCalendar'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore, type ConfirmOptions } from '@/stores/notifications'
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
    listView?: boolean
    selectedDate?: string
    calendarOccurrence?: TaskOccurrence | null
    calendarData?: CalendarTaskData
    calendarReviews?: CalendarReviews
  }>(),
  {
    timezone: 'UTC',
    trash: false,
    locked: false,
    compact: false,
    widgetCompact: false,
    listView: false,
  },
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
const calendarAvailable = phoneCalendarAvailable()
const exportingCalendar = ref(false)
const task = ref(props.task)
const detailsDialog = ref<HTMLDialogElement | null>(null)
const editDialog = ref<HTMLDialogElement | null>(null)
const historyDialog = ref<HTMLDialogElement | null>(null)
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const detailsOpen = ref(false)
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
const date = ref(props.selectedDate ?? today(props.timezone))
const currentRule = ref<TaskSchedule | null>(null)
const latestRule = ref<TaskSchedule | null>(null)
const ruleError = ref('')
const rulesLoaded = ref(false)
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
const listRecurring = computed(() => props.listView && task.value.execution_type === 'recurring')
const noListOccurrence = computed(
  () =>
    listRecurring.value &&
    !loading.value &&
    !executionError.value &&
    occurrence.value !== null &&
    !eligible.value &&
    occurrence.value.is_scheduled !== true,
)
const noOccurrenceLabel = computed(() =>
  date.value === today(props.timezone) ? 'Fără apariție astăzi' : 'Fără apariție în această zi',
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
const disabled = computed(() => busy.value || props.locked || exportingCalendar.value)
const occurrenceDate = computed(() =>
  task.value.execution_type === 'one_time'
    ? task.value.scheduled_date
    : (occurrence.value?.scheduled_date ?? date.value),
)
const occurrenceTime = computed(() =>
  task.value.execution_type === 'one_time'
    ? task.value.scheduled_time
    : occurrence.value
      ? occurrence.value.scheduled_time
      : task.value.scheduled_time,
)
const occurrenceDeadline = computed(() =>
  task.value.execution_type !== 'one_time' && occurrence.value?.due_date !== undefined
    ? occurrence.value.due_date
    : task.value.due_date,
)
const occurrenceDeadlineTime = computed(() =>
  task.value.execution_type !== 'one_time' && occurrence.value?.due_time !== undefined
    ? occurrence.value.due_time
    : task.value.due_time,
)
const overdue = computed(
  () =>
    !props.trash &&
    !loading.value &&
    eligible.value &&
    shownStatus.value === 'pending' &&
    taskIsOverdue(
      {
        ...task.value,
        scheduled_date: occurrenceDate.value,
        scheduled_time: occurrenceTime.value,
        due_date: occurrenceDeadline.value,
        due_time: occurrenceDeadlineTime.value,
      },
      props.timezone,
    ),
)
const visualState = computed(() =>
  shownStatus.value === 'done'
    ? 'done'
    : shownStatus.value === 'skipped'
      ? 'skipped'
      : overdue.value
        ? 'overdue'
        : 'pending',
)
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
let previousOverflow: string | undefined
function showDialog(dialog: HTMLDialogElement | null): void {
  if (!dialog || dialog.open) return
  if (previousOverflow === undefined) {
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  }
  dialog.showModal()
}
function dialogClosed(): void {
  detailsOpen.value = detailsDialog.value?.open ?? false
  if (
    ![detailsDialog.value, editDialog.value, historyDialog.value].some((dialog) => dialog?.open) &&
    previousOverflow !== undefined
  ) {
    document.body.style.overflow = previousOverflow
    previousOverflow = undefined
  }
}
function openDetails(): void {
  closeMenu()
  showDialog(detailsDialog.value)
  detailsOpen.value = detailsDialog.value?.open ?? false
}
function cardClick(event: MouseEvent): void {
  const target = event.target
  if (
    event.defaultPrevented ||
    !(target instanceof Element) ||
    target.closest('button, a, input, select, textarea, summary, details, [role="button"]') ||
    window.getSelection()?.toString()
  )
    return
  openDetails()
}
async function editFromMenu(): Promise<void> {
  if (!canManage.value || disabled.value || command.value) return
  closeMenu()
  actionMenu.value?.querySelector('summary')?.focus({ preventScroll: true })
  editing.value = true
  await nextTick()
  showDialog(editDialog.value)
}
function closeEdit(): void {
  if (busy.value) return
  editDialog.value?.close()
  editing.value = false
}
function editClosed(): void {
  editing.value = false
  dialogClosed()
}
const canExportCalendar = computed(
  () =>
    calendarAvailable &&
    !props.trash &&
    !loading.value &&
    !executionError.value &&
    Boolean(occurrenceDate.value) &&
    (task.value.execution_type !== 'recurring' ||
      (eligible.value &&
        occurrence.value !== null &&
        (occurrence.value.is_scheduled === true || occurrence.value.id !== null))),
)
async function calendarFromMenu(): Promise<void> {
  if (!canExportCalendar.value || disabled.value || command.value) return
  closeMenu()
  exportingCalendar.value = true
  try {
    const result = await addSavedTaskToPhoneCalendar(
      props.project.id,
      task.value,
      date.value,
      occurrence.value ?? undefined,
    )
    if (result === 'saved')
      notifications.success('Evenimentul a fost adăugat în calendarul telefonului.')
    else if (result === 'cancelled')
      notifications.info('Adăugarea în calendar a fost anulată sau nu a fost confirmată.')
    else
      notifications.info('Calendarul nu confirmă salvarea; verifică evenimentul în aplicația de calendar.')
  } catch (failure) {
    notifications.warning(
      failure instanceof Error ? failure.message : 'Nu am putut deschide calendarul telefonului.',
    )
  } finally {
    exportingCalendar.value = false
    actionMenu.value?.querySelector('summary')?.focus({ preventScroll: true })
  }
}
function historyFromMenu(): void {
  closeMenu()
  actionMenu.value?.querySelector('summary')?.focus({ preventScroll: true })
  showDialog(historyDialog.value)
  void loadHistory()
}
function archiveFromMenu(): void {
  closeMenu()
  void manage('archive')
}
function confirmTask(options: ConfirmOptions): Promise<boolean> {
  return (
    confirmation.value?.ask({
      ...options,
      confirmText: options.confirmText ?? 'Confirmă',
      cancelText: options.cancelText ?? 'Anulează',
    }) ?? Promise.resolve(false)
  )
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
      props.calendarData?.occurrence_eligible ??
      (task.value.execution_type !== 'recurring' || props.calendarOccurrence?.is_scheduled === true)
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
async function loadRules(): Promise<void> {
  if (props.calendarData) {
    rulesLoaded.value = true
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
    rulesLoaded.value = true
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
    await Promise.all([loadOccurrence(true), ...(historyDialog.value?.open ? [loadHistory()] : [])])
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
      if (historyDialog.value?.open) await loadHistory()
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
      !(await confirmTask({
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
async function saved(): Promise<void> {
  editDialog.value?.close()
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
  detailsDialog.value?.close()
  editDialog.value?.close()
  historyDialog.value?.close()
  dialogClosed()
  closeMenu()
  document.removeEventListener('pointerdown', outsideMenu)
  document.removeEventListener('keydown', menuKey)
  window.removeEventListener('resize', positionMenu)
  document.removeEventListener('scroll', positionMenu, true)
  window.visualViewport?.removeEventListener('resize', positionMenu)
  emit('pendingCommand', false)
  readSequence++
  historySequence++
  if (busy.value) emit('busy', false)
})
</script>

<template>
  <article
    class="task-card task-card--v3"
    @click="cardClick"
    :class="{
      'task-card--compact': compact,
      [`task-card--${visualState}`]: true,
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
          :aria-expanded="detailsOpen"
          aria-haspopup="dialog"
          :aria-controls="detailsId"
          @click.stop="openDetails"
        >
          {{ task.title }}
        </button>
        <span
          class="task-card__priority"
          :class="`task-card__priority--${task.priority}`"
          :aria-label="`Prioritate: ${taskPriorities[task.priority]}`"
        >
          <svg
            width="12"
            height="12"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path
              v-if="task.priority === 'high' || task.priority === 'urgent'"
              d="m7 11 5-5 5 5m-10 7 5-5 5 5"
            />
            <path v-else-if="task.priority === 'low'" d="m7 10 5 5 5-5" />
            <path v-else d="M6 12h12" />
          </svg>
          {{ taskPriorities[task.priority] }}
        </span>
        <details
          v-if="!trash"
          ref="actionMenu"
          class="task-card__menu"
          @click.stop
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
      <slot v-if="widgetCompact" name="widget-meta" />
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
        <template v-if="listRecurring">Status la {{ displayDate(date, occurrenceTime) }}</template>
        <template v-else>{{ displayDate(occurrenceDate, occurrenceTime) }}</template>
        <span v-if="overdue">· Întârziat</span>
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
            <path d="m17 2 4 4-4 4M3 11V8a2 2 0 0 1 2-2h16M7 22l-4-4 4-4m14-1v3a2 2 0 0 1-2 2H3" />
          </svg>
          {{ recurrenceLabel }}
        </span>
      </template>
    </div>
    <div v-if="!trash" class="task-card__primary-actions" @click.stop>
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
      <span v-if="noListOccurrence" class="task-card__no-occurrence">
        {{ noOccurrenceLabel }}
      </span>
      <TaskOccurrenceStatusControl
        v-else
        :status="shownStatus"
        :label="`Status pentru ${task.title}`"
        :disabled="disabled || !canExecute || Boolean(command)"
        :aria-busy="loading || busy"
        @change="execute"
      />
    </div>
    <p v-if="executionError" class="projects-error" role="alert">{{ executionError }}</p>
    <p v-else-if="!trash && !loading && !eligible && !noListOccurrence" class="projects-muted">
      Fără execuție eligibilă
    </p>
    <Teleport to="body">
      <div
        v-if="menuOpen && !trash"
        ref="menuPanel"
        class="task-card__menu-items"
        @click.stop
        :style="menuStyle"
        role="group"
        :aria-label="`Acțiuni pentru ${task.title}`"
      >
        <button
          v-if="canManage"
          type="button"
          class="task-quick"
          :disabled="disabled || Boolean(command)"
          @click="editFromMenu"
        >
          Editează taskul
        </button>
        <button
          v-if="canExportCalendar"
          type="button"
          class="task-quick"
          :disabled="disabled || Boolean(command)"
          @click="calendarFromMenu"
        >
          Adaugă în calendar
        </button>
        <button type="button" class="task-quick" :disabled="disabled" @click="historyFromMenu">
          Istoric execuții
        </button>
        <button
          v-if="canManage"
          type="button"
          class="task-quick task-quick--danger"
          :disabled="disabled || Boolean(command) || task.status === 'archived'"
          @click="archiveFromMenu"
        >
          Arhivează
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
    <button
      v-if="trash && canManage"
      class="task-quick"
      type="button"
      :disabled="disabled"
      @click.stop="manage('restore')"
    >
      Restaurează
    </button>
    <Teleport to="body">
      <dialog
        :id="detailsId"
        ref="detailsDialog"
        class="task-card-dialog"
        :aria-labelledby="`${detailsId}-title`"
        @click.stop.self="detailsDialog?.close()"
        @close="dialogClosed"
      >
        <header class="task-card-dialog__header">
          <h3 :id="`${detailsId}-title`">{{ task.title }}</h3>
          <button
            type="button"
            class="task-dialog-close"
            autofocus
            aria-label="Închide detaliile"
            @click="detailsDialog?.close()"
          >
            ×
          </button>
        </header>
        <div class="task-card-dialog__body">
          <p v-if="task.description" class="task-card-description">{{ task.description }}</p>
          <dl class="task-card-facts">
            <div>
              <dt>Programat</dt>
              <dd>{{ displayDate(occurrenceDate, occurrenceTime) }}</dd>
            </div>
            <div>
              <dt>Termen-limită</dt>
              <dd>{{ displayDate(occurrenceDeadline, occurrenceDeadlineTime) }}</dd>
            </div>
            <div>
              <dt>Prioritate</dt>
              <dd>{{ taskPriorities[task.priority] }}</dd>
            </div>
            <div>
              <dt>Tip execuție</dt>
              <dd>{{ taskTypes[task.execution_type] }}</dd>
            </div>
            <div v-if="task.execution_type === 'recurring'">
              <dt>Recurență</dt>
              <dd>{{ displayedRule ? scheduleLabel(displayedRule) : recurrenceLabel }}</dd>
            </div>
            <div>
              <dt>Status</dt>
              <dd>{{ noListOccurrence ? noOccurrenceLabel : taskStates[shownStatus] }}<span v-if="overdue"> · Întârziat</span></dd>
            </div>
          </dl>
        </div>
      </dialog>
      <dialog
        ref="editDialog"
        class="task-card-dialog task-card-dialog--edit"
        :aria-labelledby="`${detailsId}-edit`"
        @click.stop.self="closeEdit"
        @cancel.prevent="closeEdit"
        @close="editClosed"
      >
        <header class="task-card-dialog__header">
          <h3 :id="`${detailsId}-edit`">Editează taskul</h3>
          <button
            type="button"
            class="task-dialog-close"
            autofocus
            :disabled="busy"
            aria-label="Închide editarea"
            @click="closeEdit"
          >
            ×
          </button>
        </header>
        <div class="task-card-dialog__body">
          <p v-if="error" class="projects-error" role="alert">{{ error }}</p>
          <ProjectTaskForm
            v-if="editing && canManage && !trash"
            :project="project"
            :task="task"
            :occurrence-date="date"
            :initial-schedule="rulesLoaded ? latestRule : undefined"
            :confirm="confirmTask"
            @busy="setBusy"
            @cancel="closeEdit"
            @changed="emit('changed')"
            @saved="saved"
            @access-changed="emit('accessChanged')"
          />
          <div v-if="canManage" class="task-buttons task-card-dialog__admin">
            <button
              v-if="task.status === 'archived'"
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
          </div>
        </div>
      </dialog>
      <dialog
        ref="historyDialog"
        class="task-card-dialog"
        :aria-labelledby="`${detailsId}-history`"
        @click.stop.self="historyDialog?.close()"
        @close="dialogClosed"
      >
        <header class="task-card-dialog__header">
          <h3 :id="`${detailsId}-history`">Istoric execuții · {{ task.title }}</h3>
          <button
            type="button"
            class="task-dialog-close"
            autofocus
            aria-label="Închide istoricul"
            @click="historyDialog?.close()"
          >
            ×
          </button>
        </header>
        <div class="task-card-dialog__body">
          <label v-if="task.execution_type !== 'one_time'" class="task-date-picker"
            >Data apariției afișate
            <input v-model="date" type="date" :disabled="disabled || Boolean(command)" required />
          </label>
          <section class="task-history">
            <div class="task-form__columns project-date-columns">
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
      </dialog>
      <ProgressNoteConfirmDialog ref="confirmation" class="task-card-confirm" />
    </Teleport>
  </article>
</template>

<style scoped>
.task-card.task-card--v3 {
  --task-accent: var(--color-yellow);
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 2px 8px;
  border-inline-start: 4px solid var(--task-accent);
  background: var(--color-surface);
  background-image: linear-gradient(
    90deg,
    color-mix(in srgb, var(--task-accent) 5%, transparent),
    transparent 40%
  );
  min-height: 0;
  padding: 8px 10px;
  opacity: 1;
}
.task-card__information {
  grid-column: 1 / -1;
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
  display: inline-flex;
  align-items: center;
  gap: 3px;
  flex: none;
  margin-top: 8px;
  padding: 3px 6px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-soft);
  color: var(--color-text-muted);
  font-size: 0.68rem;
  line-height: 1.3;
  white-space: nowrap;
}
.task-card__priority svg {
  flex: none;
}
.task-card__priority--high,
.task-card__priority--urgent {
  background: var(--color-error-soft);
  color: color-mix(in srgb, var(--color-error) 80%, var(--color-text));
}
.task-card__priority--medium {
  background: var(--color-yellow-soft);
  color: #835800;
}
.task-card__priority--low {
  background: color-mix(in srgb, var(--color-secondary) 8%, var(--color-surface));
  color: var(--color-secondary);
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
  padding-bottom: 0;
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
  gap: 4px;
  min-width: 0;
  min-height: 44px;
  max-width: 100%;
  padding-top: 0;
  justify-self: end;
}
.task-card__no-occurrence {
  min-width: 0;
  color: var(--color-text-muted);
  font-size: 0.78rem;
  text-align: right;
  overflow-wrap: anywhere;
}
.task-card__primary-actions :deep(.task-status-control) {
  flex: 0 1 auto;
  width: max-content;
  min-height: 38px;
  height: 38px;
  padding-inline: 6px;
  gap: 4px;
}
.task-card__primary-actions :deep(.task-status-control__icon) {
  width: 14px;
  height: 14px;
}
.task-card__primary-actions :deep(.task-status-control > svg:not(.task-status-control__icon)) {
  width: 10px;
  height: 10px;
}
.task-card__primary-actions :deep(.task-status-control select) {
  top: 50%;
  bottom: auto;
  height: 44px;
  min-height: 44px;
  transform: translateY(-50%);
}
.task-card__menu-items {
  max-width: calc(100vw - 24px);
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
.task-card--v3.task-card--done {
  --task-accent: var(--color-success);
}
.task-card--v3.task-card--overdue {
  --task-accent: var(--color-error);
}
.task-card--v3.task-card--skipped {
  --task-accent: var(--color-text-muted);
}
.task-card--v3 > :is(.projects-error, .projects-muted, .task-quick) {
  grid-column: 1 / -1;
}
.task-card-dialog,
.task-card-confirm {
  position: fixed;
  inset: 0;
  box-sizing: border-box;
  width: min(560px, calc(100% - 24px));
  max-width: calc(100% - 24px);
  max-height: calc(
    100dvh - 32px - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px)
  );
  margin: auto;
  padding: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  text-align: left;
  box-shadow: var(--shadow-md);
}
.task-card-dialog::backdrop,
.task-card-confirm::backdrop {
  background: var(--color-overlay);
}
.task-card-dialog__header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px 4px 12px;
  border-bottom: 1px solid var(--color-border);
}
.task-card-dialog__header h3 {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 1rem;
  overflow-wrap: anywhere;
}
.task-dialog-close {
  flex: none;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.65rem;
  cursor: pointer;
}
.task-dialog-close:disabled {
  opacity: 0.45;
  cursor: default;
}
.task-card-dialog__body {
  display: grid;
  gap: 12px;
  min-width: 0;
  padding: 12px;
}
.task-card-dialog--edit :deep(.task-form) {
  padding: 0;
  border: 0;
  background: transparent;
}
.task-card-dialog--edit :deep(.task-form > h3) {
  display: none;
}
.task-card-description {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.task-card-facts {
  display: grid;
  gap: 10px;
  margin: 0;
}
.task-card-facts > div {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
  gap: 8px;
}
.task-card-facts dt {
  color: var(--color-text-muted);
  font-size: 0.83rem;
}
.task-card-facts dd {
  min-width: 0;
  margin: 0;
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.task-history {
  display: grid;
  gap: 12px;
  min-width: 0;
}
.task-history ul {
  display: grid;
  gap: 8px;
  margin: 0;
  padding-inline-start: 18px;
  overflow-wrap: anywhere;
}
.task-card-confirm {
  padding: 16px;
}
.task-card-confirm :deep(h3) {
  margin: 0;
  font-size: 1rem;
}
.task-card-confirm :deep(p) {
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.task-card-confirm :deep(.note-editor-confirm__actions) {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
}
@media (max-width: 360px) {
  .task-card.task-card--v3 {
    grid-template-columns: minmax(0, 1fr);
  }
  .task-card__primary-actions {
    justify-self: stretch;
  }
}
</style>
