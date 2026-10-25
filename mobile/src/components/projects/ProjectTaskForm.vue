<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { ApiError } from '@/services/api'
import { projectTasksApi } from '@/services/projectTasksApi'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type {
  ProjectTask,
  TaskPayload,
  TaskPriority,
  TaskSchedule,
  TaskSchedulePayload,
} from '@/types/projectTasks'
import { taskError, taskPriorities, taskTypes, weekdays } from '@/utils/projectTasks'

const props = defineProps<{ project: Project; task?: ProjectTask; initialDate?: string }>()
const emit = defineEmits<{
  saved: []
  changed: []
  cancel: []
  busy: [value: boolean]
  accessChanged: []
}>()
const notifications = useNotificationStore()
const initial = props.task
const record = ref<ProjectTask | null>(initial ?? null)
const busy = ref(false)
const loading = ref(false)
const loadFailed = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const uncertainCreation = ref(false)
const latest = ref<TaskSchedule | null>(null)
let originalSchedule = ''
const form = reactive({
  title: initial?.title ?? '',
  description: initial?.description ?? '',
  execution_type: initial?.execution_type ?? props.project.default_execution_type,
  priority: initial?.priority ?? ('medium' as TaskPriority),
  scheduled_date: initial?.scheduled_date ?? props.initialDate ?? '',
  scheduled_time: initial?.scheduled_time ?? '',
  due_date: initial?.due_date ?? '',
  due_time: initial?.due_time ?? '',
  show_in_daily_widget: initial?.show_in_daily_widget ?? true,
})
const rule = reactive({
  recurrence_type: 'daily' as TaskSchedulePayload['recurrence_type'],
  interval_days: '2',
  weekdays: [] as number[],
  month_day: '1',
  start_date: '',
  end_date: '',
  scheduled_time: '',
})
const recurring = computed(() => form.execution_type === 'recurring')
const canSchedule = computed(() => recurring.value && (!initial || initial.status === 'active'))
const typeOptions = Object.entries(taskTypes).map(([value, label]) => ({ value, label }))
const priorityOptions = Object.entries(taskPriorities).map(([value, label]) => ({ value, label }))
const recurrenceOptions = [
  { value: 'daily', label: 'Zilnic' },
  { value: 'every_n_days', label: 'La fiecare N zile' },
  { value: 'weekdays', label: 'Zile ale săptămânii' },
  { value: 'monthly', label: 'Lunar' },
]
function schedulePayload(): TaskSchedulePayload {
  return {
    recurrence_type: rule.recurrence_type,
    start_date: rule.start_date || null,
    end_date: rule.end_date || null,
    scheduled_time: rule.scheduled_time || null,
    ...(rule.recurrence_type === 'every_n_days'
      ? { interval_days: Number(rule.interval_days) }
      : {}),
    ...(rule.recurrence_type === 'monthly' ? { month_day: Number(rule.month_day) } : {}),
    ...(rule.recurrence_type === 'weekdays'
      ? { weekdays: [...rule.weekdays].sort((a, b) => a - b) }
      : {}),
  }
}
async function loadSchedule(): Promise<void> {
  if (!initial || initial.execution_type !== 'recurring') return
  loading.value = true
  loadFailed.value = false
  error.value = ''
  try {
    const response = await projectTasksApi.scheduleVersions(props.project.id, initial.id)
    latest.value = response.data[0] ?? null
    const value = latest.value
    if (value)
      Object.assign(rule, {
        recurrence_type: value.recurrence_type,
        interval_days: String(value.interval_days ?? 2),
        weekdays: [...(value.weekdays ?? [])],
        month_day: String(value.month_day ?? 1),
        start_date: value.start_date ?? '',
        end_date: value.end_date ?? '',
        scheduled_time: value.scheduled_time ?? '',
      })
    originalSchedule = value ? JSON.stringify(schedulePayload()) : ''
  } catch (failure) {
    error.value = taskError(failure)
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}
const scheduleLoadFailed = computed(() => canSchedule.value && loadFailed.value)
async function save(): Promise<void> {
  if (
    busy.value ||
    props.project.status !== 'active' ||
    uncertainCreation.value ||
    scheduleLoadFailed.value
  )
    return
  busy.value = true
  emit('busy', true)
  error.value = ''
  errors.value = {}
  let taskSaved = false
  try {
    const desired = schedulePayload()
    const scheduleChanged =
      canSchedule.value && (!latest.value || JSON.stringify(desired) !== originalSchedule)
    if (
      scheduleChanged &&
      latest.value &&
      !(await notifications.confirm({
        title: 'Actualizezi recurența?',
        message: 'Modificările se aplică de mâine. Execuțiile și istoricul existente se păstrează.',
        confirmText: 'Actualizează',
      }))
    )
      return
    const payload: TaskPayload = {
      title: form.title.trim(),
      description: form.description.trim() || null,
      priority: form.priority,
      scheduled_date: form.scheduled_date || null,
      scheduled_time: form.scheduled_time || null,
      due_date: form.due_date || null,
      due_time: form.due_time || null,
      show_in_daily_widget: form.show_in_daily_widget,
      ...(!initial ? { execution_type: form.execution_type } : {}),
    }
    if (!record.value) {
      try {
        record.value = (await projectTasksApi.create(props.project.id, payload)).data
      } catch (failure) {
        if (!(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500) {
          uncertainCreation.value = true
          emit('changed')
          throw new Error(
            'Nu putem confirma dacă taskul a fost creat. Închide formularul și verifică lista înainte de o nouă creare.',
          )
        }
        throw failure
      }
    } else if (initial)
      record.value = (await projectTasksApi.update(props.project.id, record.value.id, payload)).data
    taskSaved = true
    if (scheduleChanged) {
      // After a partial failure, inspect the server before attempting another initial POST.
      if (!initial) {
        const existing = await projectTasksApi.scheduleVersions(props.project.id, record.value.id)
        if (existing.data.length)
          throw new Error(
            'Taskul există deja cu o regulă de recurență. Închide formularul și verifică/editează regula din task.',
          )
      }
      latest.value = (
        await projectTasksApi.saveSchedule(
          props.project.id,
          record.value.id,
          desired,
          latest.value !== null,
        )
      ).data
      originalSchedule = JSON.stringify(desired)
    }
    emit('saved')
  } catch (failure) {
    if (failure instanceof ApiError) errors.value = failure.errors
    const message =
      failure instanceof Error && !(failure instanceof ApiError)
        ? failure.message
        : taskError(failure)
    error.value = taskSaved
      ? `Taskul #${record.value!.id} a fost salvat, dar recurența nu a fost salvată. ${message}`
      : message
    if (taskSaved) emit('changed')
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
      notifications.error(taskError(failure))
      emit('accessChanged')
    }
  } finally {
    busy.value = false
    emit('busy', false)
  }
}
onMounted(loadSchedule)
</script>

<template>
  <form class="task-form" @submit.prevent="save">
    <h3>{{ initial ? 'Editează taskul' : 'Adaugă task' }}</h3>
    <p v-if="loading" role="status">Se încarcă recurența…</p>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <p v-for="(messages, field) in errors" :key="field">{{ messages.join(' ') }}</p>
      <AppButton v-if="scheduleLoadFailed" variant="secondary" @click="loadSchedule"
        >Reîncarcă regula</AppButton
      >
    </div>
    <p v-if="record && !initial" class="projects-notice">
      Taskul #{{ record.id }} există deja. Reîncercarea salvează doar recurența.
    </p>
    <fieldset
      class="task-fields"
      :disabled="busy || loading || uncertainCreation || (Boolean(record) && !initial)"
    >
      <AppInput v-model="form.title" label="Titlu *" :maxlength="255" :error="errors.title?.[0]" />
      <AppTextarea
        v-model="form.description"
        label="Descriere (opțional)"
        :rows="2"
        :error="errors.description?.[0]"
      />
      <div class="task-form__columns">
        <AppSelect
          v-model="form.execution_type"
          label="Tip execuție"
          :options="typeOptions"
          :disabled="Boolean(initial)"
        /><AppSelect v-model="form.priority" label="Prioritate" :options="priorityOptions" />
      </div>
      <details
        :open="
          Boolean(form.scheduled_date || form.due_date || form.scheduled_time || form.due_time)
        "
      >
        <summary>Programare, termen și afișare (opțional)</summary>
        <div class="task-fields">
          <div class="task-form__columns">
            <label>Data programată<input v-model="form.scheduled_date" type="date" /></label
            ><label>Ora programată<input v-model="form.scheduled_time" type="time" /></label>
          </div>
          <div class="task-form__columns">
            <label>Termen-limită<input v-model="form.due_date" type="date" /></label
            ><label>Ora-limită<input v-model="form.due_time" type="time" /></label>
          </div>
          <label class="task-check"
            ><input v-model="form.show_in_daily_widget" type="checkbox" /> Afișează în widgetul
            zilnic</label
          >
        </div>
      </details>
    </fieldset>
    <fieldset
      v-if="canSchedule"
      class="task-fields task-recurrence"
      :disabled="busy || loading || uncertainCreation"
    >
      <legend>Recurență</legend>
      <p v-if="latest" class="projects-muted">
        Editezi versiunea {{ latest.version }} (din {{ latest.effective_from }}). Schimbările se
        aplică de mâine.
      </p>
      <AppSelect v-model="rule.recurrence_type" label="Se repetă" :options="recurrenceOptions" />
      <label v-if="rule.recurrence_type === 'every_n_days'"
        >La fiecare N zile<input
          v-model="rule.interval_days"
          type="number"
          min="1"
          max="4294967295"
          required
      /></label>
      <div v-if="rule.recurrence_type === 'weekdays'" class="task-weekdays">
        <label v-for="(day, index) in weekdays" :key="day" class="task-check"
          ><input v-model="rule.weekdays" type="checkbox" :value="index + 1" />{{ day }}</label
        >
      </div>
      <label v-if="rule.recurrence_type === 'monthly'"
        >Ziua lunii<input v-model="rule.month_day" type="number" min="1" max="31" required
      /></label>
      <p v-if="rule.recurrence_type === 'monthly'" class="projects-muted">
        Lunile fără ziua aleasă (29, 30 sau 31) sunt omise.
      </p>
      <div class="task-form__columns">
        <label>Începe la (opțional)<input v-model="rule.start_date" type="date" /></label
        ><label>Se termină la (opțional)<input v-model="rule.end_date" type="date" /></label>
      </div>
      <label>Ora recurenței (opțional)<input v-model="rule.scheduled_time" type="time" /></label>
    </fieldset>
    <div class="task-buttons">
      <AppButton
        type="submit"
        :loading="busy"
        :disabled="loading || uncertainCreation || scheduleLoadFailed"
        >{{ record && !initial ? 'Reîncearcă recurența' : 'Salvează' }}</AppButton
      ><AppButton variant="secondary" :disabled="busy" @click="emit('cancel')">{{
        (record && !initial) || uncertainCreation ? 'Închide și verifică lista' : 'Renunță'
      }}</AppButton>
    </div>
  </form>
</template>
