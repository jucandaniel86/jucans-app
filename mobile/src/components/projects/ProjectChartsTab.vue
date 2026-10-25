<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTaskPicker from '@/components/projects/ProjectTaskPicker.vue'
import ProjectAnalyticsPanel from '@/components/projects/ProjectAnalyticsPanel.vue'
import { projectChartsApi } from '@/services/projectChartsApi'
import { projectsApi } from '@/services/projectsApi'
import { ApiError } from '@/services/api'
import type { Project, ProjectProgressSummary } from '@/types/projects'
import type { NoteTask } from '@/types/projectProgress'
import type { AnalyticsTimeline, ChartRange, TaskAnalytics } from '@/types/projectCharts'
import { projectError, projectProgressLabels } from '@/utils/projectPresentation'
import { displayDate, taskTypes, taskStates } from '@/utils/projectTasks'

const props = defineProps<{ project: Project; summaryData?: ProjectProgressSummary | null }>()
const emit = defineEmits<{ accessChanged: [] }>()
const range = ref<ChartRange>('30')
const ranges: { value: ChartRange; label: string }[] = [
  { value: '7', label: '7 zile' },
  { value: '30', label: '30 zile' },
  { value: '90', label: '90 zile' },
  { value: 'all', label: 'Tot istoricul' },
]
const summary = ref<ProjectProgressSummary | null>(props.summaryData ?? null)
const summaryLoading = ref(false)
const summaryError = ref('')
const report = ref<AnalyticsTimeline | null>(null)
const loading = ref(false)
const error = ref('')
const anchor = ref<string>()
const selected = ref<NoteTask[]>([])
const taskReport = ref<TaskAnalytics | null>(null)
const taskLoading = ref(false)
const taskError = ref('')
const recorded = computed(() => taskReport.value?.executions.filter((row) => row.is_recorded) ?? [])
let reportRequest = 0,
  taskRequest = 0
let disposed = false
function message(failure: unknown): string {
  return failure instanceof ApiError && failure.status === 404
    ? 'Statistica nu este disponibilă sau nu mai ai acces la aceste date.'
    : projectError(failure, 'Nu am putut încărca statisticile. Încearcă din nou.')
}
function access(failure: unknown): void {
  if (failure instanceof ApiError && [401, 403].includes(failure.status)) emit('accessChanged')
}
async function loadSummary(): Promise<void> {
  summaryLoading.value = true
  summaryError.value = ''
  try {
    const response = await projectsApi.summary(props.project.id)
    if (!disposed) summary.value = response.data
  } catch (failure) {
    if (!disposed) {
      summary.value = null
      summaryError.value = message(failure)
      access(failure)
    }
  } finally {
    if (!disposed) summaryLoading.value = false
  }
}
async function load(page = 1): Promise<void> {
  const request = ++reportRequest
  const chosenRange = range.value
  loading.value = true
  error.value = ''
  report.value = null
  try {
    const response = await projectChartsApi.timeline(
      props.project.id,
      chosenRange,
      page,
      anchor.value,
    )
    if (request !== reportRequest || disposed) return
    report.value = response.data
    // All pages keep the same server-local inclusive end date, including across midnight.
    if (page === 1) anchor.value = response.data.range.to
  } catch (failure) {
    if (request === reportRequest && !disposed) {
      error.value = message(failure)
      access(failure)
    }
  } finally {
    if (request === reportRequest && !disposed) loading.value = false
  }
}
async function loadTask(page = 1): Promise<void> {
  const task = selected.value[0]
  const request = ++taskRequest
  taskReport.value = null
  taskError.value = ''
  if (!task) {
    taskLoading.value = false
    return
  }
  taskLoading.value = true
  try {
    const response = await projectChartsApi.task(
      props.project.id,
      task.id,
      range.value,
      page,
      anchor.value,
    )
    if (request !== taskRequest || disposed) return
    taskReport.value = response.data
  } catch (failure) {
    if (request === taskRequest && !disposed) {
      taskError.value = message(failure)
      access(failure)
    }
  } finally {
    if (request === taskRequest && !disposed) taskLoading.value = false
  }
}
watch(range, () => {
  void load()
  void loadTask()
})
watch(
  () => props.summaryData,
  (value) => {
    if (value) {
      summary.value = value
      summaryError.value = ''
    }
  },
)
watch(
  () => selected.value[0]?.id,
  () => {
    void loadTask()
  },
)
onMounted(() => {
  if (!summary.value) void loadSummary()
  void load()
})
onBeforeUnmount(() => {
  disposed = true
  reportRequest++
  taskRequest++
})
</script>

<template>
  <section class="project-charts project-chart-stack" aria-label="Grafice și statistici">
    <header class="project-chart-heading">
      <h3>Grafice și statistici</h3>
      <p class="projects-muted">{{ projectProgressLabels[project.progress_type] }}</p>
    </header>
    <section
      class="project-chart-card project-chart-stack project-chart-summary"
      aria-label="Sumar proiect"
    >
      <h4>Proiectul acum</h4>
      <p v-if="summaryLoading" role="status">Se încarcă sumarul…</p>
      <div v-if="summaryError" class="projects-error" role="alert">
        <p>{{ summaryError }}</p>
        <button class="task-quick" type="button" @click="loadSummary">Reîncearcă sumarul</button>
      </div>
      <template v-if="summary">
        <div class="project-chart-stats">
          <div>
            <strong>{{ summary.completion.completed_tasks }}</strong
            ><span>Taskuri finalizate</span>
          </div>
          <div>
            <strong>{{ summary.completion.remaining_tasks }}</strong
            ><span>Taskuri rămase</span>
          </div>
          <div>
            <strong>{{ summary.completion.archived_tasks }}</strong
            ><span>Taskuri arhivate</span>
          </div>
        </div>
        <details v-if="summary.completion.by_execution_type">
          <summary>Distribuție după tip</summary>
          <dl class="project-chart-breakdown">
            <template v-for="(counts, type) in summary.completion.by_execution_type" :key="type"
              ><dt>{{ taskTypes[type] }}</dt>
              <dd>
                {{ counts.completed_tasks }} finalizate · {{ counts.remaining_tasks }} rămase ·
                {{ counts.archived_tasks }} arhivate
              </dd></template
            >
          </dl>
        </details>
        <p class="projects-muted">Taskurile arhivate nu intră în numărul celor rămase.</p>
      </template>
    </section>
    <label class="project-chart-date"
      >Interval
      <select v-model="range">
        <option v-for="option in ranges" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
    </label>
    <p v-if="range === 'all'" class="projects-muted">
      Statisticile descriu perioada afișată. Folosește săgețile pentru perioadele mai vechi.
    </p>
    <section class="project-chart-stack" aria-label="Evoluția proiectului">
      <h3>Evoluția proiectului</h3>
      <p v-if="loading" role="status">Se încarcă graficele…</p>
      <div v-if="error" class="projects-error" role="alert">
        <p>{{ error }}</p>
        <AppButton variant="secondary" @click="load()">Reîncearcă</AppButton>
      </div>
      <template v-if="report">
        <p v-if="project.progress_type === 'completion'" class="projects-muted">
          Taskurile finalizate sunt numărate separat de execuțiile zilnice.
        </p>
        <ProjectAnalyticsPanel :report="report" />
        <nav v-if="range === 'all'" class="project-chart-pages" aria-label="Perioade proiect">
          <button
            class="task-quick"
            type="button"
            :disabled="report.range.page <= 1"
            @click="load(report.range.page - 1)"
          >
            ‹ Mai recent</button
          ><span>{{ report.range.page }}/{{ report.range.last_page }}</span
          ><button
            class="task-quick"
            type="button"
            :disabled="report.range.next_page === null"
            @click="load(report.range.next_page!)"
          >
            Mai vechi ›
          </button>
        </nav>
      </template>
    </section>
    <section class="project-chart-card project-chart-stack" aria-label="Statistici pe task">
      <h3>Analiză pe task</h3>
      <ProjectTaskPicker
        v-model="selected"
        :project-id="project.id"
        :disabled="!anchor"
        label="Alege un task pentru analiză"
        @access-changed="emit('accessChanged')"
      />
      <p v-if="!selected.length" class="projects-muted">
        Alege un task pentru scoruri și istoricul execuțiilor.
      </p>
      <p v-if="taskLoading" role="status">Se încarcă istoricul taskului…</p>
      <div v-if="taskError" class="projects-error" role="alert">
        <p>{{ taskError }}</p>
        <AppButton variant="secondary" @click="loadTask()">Reîncearcă</AppButton>
      </div>
      <template v-if="taskReport">
        <ProjectAnalyticsPanel :report="taskReport" task />
        <p class="projects-muted">{{ recorded.length }} execuții înregistrate în interval.</p>
        <details v-if="recorded.length">
          <summary>Execuții înregistrate · starea curentă</summary>
          <ul class="project-chart-history">
            <li v-for="row in recorded" :key="row.occurrence_key">
              <span>{{ displayDate(row.date) }}</span
              ><strong>{{ taskStates[row.status] }}</strong>
            </li>
          </ul>
        </details>
        <nav v-if="range === 'all'" class="project-chart-pages" aria-label="Perioade task">
          <button
            class="task-quick"
            type="button"
            :disabled="taskReport.range.page <= 1"
            @click="loadTask(taskReport.range.page - 1)"
          >
            ‹ Mai recent</button
          ><span>{{ taskReport.range.page }}/{{ taskReport.range.last_page }}</span
          ><button
            class="task-quick"
            type="button"
            :disabled="taskReport.range.next_page === null"
            @click="loadTask(taskReport.range.next_page!)"
          >
            Mai vechi ›
          </button>
        </nav>
      </template>
    </section>
  </section>
</template>

<style src="./projectCharts.css"></style>
