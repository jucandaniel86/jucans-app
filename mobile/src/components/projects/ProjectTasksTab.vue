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
import { projectTasksApi } from '@/services/projectTasksApi'
import { ApiError } from '@/services/api'
import type { Project, ProjectExecutionType, ProjectStatus } from '@/types/projects'
import type { ProjectTask, TaskPriority } from '@/types/projectTasks'
import { taskError, taskPriorities, taskTypes } from '@/utils/projectTasks'

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
function busy(value: boolean): void {
  busyCount.value = Math.max(0, busyCount.value + (value ? 1 : -1))
  emit('busy', locked.value)
}
async function load(group: (typeof groups)[number], more = false): Promise<void> {
  const request = ++group.sequence
  group.loading = true
  group.error = ''
  group.retryMore = more
  try {
    const response = await projectTasksApi.list(
      props.project.id,
      {
        execution_type: group.type,
        search: search.value.trim(),
        status: status.value ? (status.value as ProjectStatus) : undefined,
        priority: priority.value ? (priority.value as TaskPriority) : undefined,
        page: more ? group.page + 1 : 1,
      },
      trash.value,
    )
    if (request !== group.sequence || disposed) return
    group.tasks = more
      ? [...new Map([...group.tasks, ...response.data].map((task) => [task.id, task])).values()]
      : response.data
    group.page = response.meta.current_page
    group.lastPage = response.meta.last_page
    group.total = response.meta.total
  } catch (failure) {
    if (request === group.sequence && !disposed) {
      group.error = taskError(failure)
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
        group.tasks = []
        emit('accessChanged')
      }
    }
  } finally {
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
  reload()
  emit('changed')
}
function closeCreate(): void {
  creating.value = false
  reload()
}
function created(): void {
  creating.value = false
  changed()
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
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
})
watch(mode, reload)
watch(
  [status, priority, trash, () => props.project.id, () => props.project.status],
  () => {
    for (const group of groups) {
      group.tasks = []
      group.page = 0
      group.total = 0
    }
    reload()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  disposed = true
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
          @click="creating = !creating"
          ><PlusIcon aria-hidden="true" /> Adaugă task</AppButton
        >
        <button
          class="task-quick"
          type="button"
          :disabled="locked"
          :aria-pressed="trash"
          @click="switchTrash"
        >
          <TrashIcon v-if="!trash" />
          {{ trash ? '‹ Taskuri' : 'Trash' }}
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
