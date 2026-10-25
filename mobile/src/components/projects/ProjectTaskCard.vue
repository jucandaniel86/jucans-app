<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import PencilIcon from '@/components/icons/pencilIcon.vue'
import ProjectTaskForm from '@/components/projects/ProjectTaskForm.vue'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type {
  ExecutionAction,
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
    selectedDate?: string
    calendarOccurrence?: TaskOccurrence | null
  }>(),
  { timezone: 'UTC', trash: false, locked: false, compact: false },
)
const emit = defineEmits<{
  changed: []
  busy: [value: boolean]
  accessChanged: []
  progress: [task: ProjectTask, date: string]
}>()
const auth = useAuthStore()
const notifications = useNotificationStore()
const task = ref(props.task)
const expanded = ref(false)
const editing = ref(false)
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
const doneLabel = computed(() =>
  task.value.execution_type === 'persistent' && date.value === today(props.timezone)
    ? 'Done Today'
    : task.value.execution_type === 'one_time' || (props.compact && !expanded.value)
      ? 'Done'
      : `Done · ${displayDate(date.value)}`,
)
const overdue = computed(() => taskIsOverdue(task.value, props.timezone))
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
      const response = await projectTasksApi.occurrences(
        props.project.id,
        task.value.id,
        from,
        to,
        page,
      )
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
    const response = await projectTasksApi.history(
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
async function refresh(): Promise<void> {
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
    await projectTasksApi.execute(
      props.project.id,
      task.value.id,
      pending.key,
      pending.action,
      pending,
    )
    command.value = null
    await refresh()
    emit('changed')
  } catch (failure) {
    const ambiguous =
      !(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500
    error.value = ambiguous
      ? 'Rezultatul nu a fost confirmat. Reîncearcă aceeași cerere în siguranță.'
      : taskError(failure)
    if (!ambiguous) {
      command.value = null
      await refresh()
      emit('changed')
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
      void loadNext()
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
  if (!command.value && task.value.execution_type !== 'one_time') void loadOccurrence()
})
onBeforeUnmount(() => {
  readSequence++
  nextSequence++
  historySequence++
  if (busy.value) emit('busy', false)
})
</script>

<template>
  <article
    class="task-card"
    :class="{ 'task-card--compact': compact, 'task-card--expanded': expanded }"
  >
    <div class="task-card__heading">
      <strong :title="task.title">{{ task.title }}</strong
      ><span class="task-priority" :class="`task-priority--${task.priority}`">{{
        taskPriorities[task.priority]
      }}</span>
    </div>
    <p v-if="overdue && (!compact || expanded)" class="task-overdue">Restant</p>
    <div v-if="!compact || task.scheduled_date || task.due_date" class="task-meta">
      <span v-if="!compact || task.scheduled_date"
        >Programat: {{ displayDate(task.scheduled_date, task.scheduled_time) }}</span
      ><span v-if="!compact || task.due_date"
        >Termen: {{ displayDate(task.due_date, task.due_time) }}</span
      >
    </div>
    <p v-if="task.status !== 'active' && (!compact || expanded)" class="projects-muted">
      {{ task.status === 'archived' ? 'Arhivat' : 'Finalizat' }}
    </p>
    <template v-if="!trash">
      <p
        v-if="task.execution_type === 'recurring' && (!compact || expanded)"
        class="projects-muted"
      >
        {{
          nextError ||
          (next
            ? `Următoarea: ${displayDate(next.scheduled_date, next.scheduled_time)}`
            : nextLoaded
              ? 'Nicio execuție programată de făcut în următoarele 366 de zile.'
              : 'Se caută următoarea execuție…')
        }}
      </p>
      <p v-if="loading" role="status" class="projects-muted">Se încarcă starea…</p>
      <p v-else class="task-state">
        {{
          executionError ||
          (occurrence
            ? occurrence.status === 'done'
              ? 'Finalizat'
              : taskStates[occurrence.status]
            : eligible
              ? 'De făcut'
              : 'Fără execuție eligibilă')
        }}
      </p>
      <div v-if="activeProject && !command" class="task-buttons task-card-actions">
        <button
          class="task-quick task-quick--note"
          type="button"
          :disabled="disabled"
          @click="emit('progress', task, props.selectedDate ?? date)"
        >
          <PencilIcon aria-hidden="true" /> Notă
        </button>
        <template v-if="canExecute">
          <button
            v-if="!occurrence || occurrence.status !== 'done'"
            class="task-quick task-quick--done"
            type="button"
            :disabled="disabled"
            @click="execute('done')"
          >
            {{ doneLabel }}
          </button>
          <button
            v-if="occurrence && occurrence.status !== 'pending'"
            class="task-quick"
            type="button"
            :disabled="disabled"
            @click="execute('undo')"
          >
            Undo
          </button>
          <button
            v-if="
              task.status === 'active' &&
              (!occurrence || occurrence.status === 'pending') &&
              (!compact || expanded)
            "
            class="task-quick"
            type="button"
            :disabled="disabled"
            @click="execute('skip')"
          >
            Skip
          </button>
        </template>
      </div>
    </template>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <button
        v-if="!command"
        class="task-quick"
        type="button"
        :disabled="disabled"
        @click="refresh"
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
    <details @toggle="toggle">
      <summary aria-label="Detalii și acțiuni">
        {{ compact ? '•••' : 'Detalii și acțiuni' }}
      </summary>
      <div class="task-details">
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
            @click="refresh"
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
              class="task-quick"
              type="button"
              :disabled="disabled || Boolean(command)"
              @click="editing = !editing"
            >
              Editează
            </button>
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
            <button
              class="task-quick task-quick--danger"
              type="button"
              :disabled="disabled || Boolean(command)"
              @click="manage('delete')"
            >
              Șterge
            </button>
          </template>
        </div>
        <ProjectTaskForm
          v-if="editing && canManage && !trash"
          :project="project"
          :task="task"
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
              <strong>{{ event.action }}</strong> · {{ displayDate(event.performed_on) }} ·
              {{ event.actor_id === auth.user?.id ? 'Tu' : `Utilizator #${event.actor_id}` }}
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
