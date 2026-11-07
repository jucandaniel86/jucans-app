<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectConfiguredChart from '@/components/projects/ProjectConfiguredChart.vue'
import { projectChartDefinitionsApi } from '@/services/projectChartDefinitionsApi'
import { ApiError } from '@/services/api'
import { projectError } from '@/utils/projectPresentation'
import { today } from '@/utils/projectTasks'
import type { Project, ProjectProgressSummary } from '@/types/projects'
import type { BarGrouping, ConfiguredChartsReport } from '@/types/projectChartDefinitions'

// summaryData remains accepted for the existing Project Detail integration.
const props = defineProps<{ project: Project; summaryData?: ProjectProgressSummary | null }>()
const emit = defineEmits<{ accessChanged: [] }>()
const end = ref(today(Intl.DateTimeFormat().resolvedOptions().timeZone))
const start = ref(`${end.value.slice(0, 7)}-01`)
const grouping = reactive<Record<number, BarGrouping>>({})
const report = ref<ConfiguredChartsReport | null>(null)
const loading = ref(false)
const error = ref('')
let sequence = 0
let disposed = false
let timer: ReturnType<typeof setTimeout> | undefined
function calendarDay(date: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date.startsWith('0000')) return null
  const parsed = new Date(`${date}T00:00:00Z`)
  return Number.isFinite(parsed.getTime()) && parsed.toISOString().slice(0, 10) === date
    ? parsed.getTime() / 86400000
    : null
}
const invalid = computed(() => {
  const a = calendarDay(start.value),
    b = calendarDay(end.value)
  if (a === null || b === null) return 'Alege două date valide.'
  if (a > b) return 'Data de început nu poate depăși data de sfârșit.'
  return b - a >= 366 ? 'Selectează maximum 366 de zile.' : ''
})
async function load(): Promise<void> {
  clearTimeout(timer)
  const request = ++sequence
  report.value = null
  error.value = ''
  loading.value = false
  if (invalid.value) return
  loading.value = true
  try {
    const response = await projectChartDefinitionsApi.data(
      props.project.id,
      start.value,
      end.value,
      grouping,
    )
    if (!disposed && request === sequence) report.value = response.data
  } catch (failure) {
    if (disposed || request !== sequence) return
    error.value = projectError(failure, 'Nu am putut încărca graficele.')
    if (failure instanceof ApiError && [401, 403].includes(failure.status)) emit('accessChanged')
  } finally {
    if (!disposed && request === sequence) loading.value = false
  }
}
watch(
  [start, end, grouping],
  () => {
    sequence++
    report.value = null
    loading.value = false
    error.value = ''
    clearTimeout(timer)
    if (!invalid.value)
      timer = setTimeout(() => {
        void load()
      }, 200)
  },
  { deep: true },
)
onMounted(() => {
  void load()
})
onBeforeUnmount(() => {
  disposed = true
  sequence++
  clearTimeout(timer)
})
</script>

<template>
  <section class="configured-charts" aria-label="Charts">
    <h3>Charts</h3>
    <div class="configured-chart-range">
      <label>De la<input v-model="start" type="date" :aria-invalid="Boolean(invalid)" /></label
      ><label>Până la<input v-model="end" type="date" :aria-invalid="Boolean(invalid)" /></label>
    </div>
    <p v-if="invalid" class="projects-error" role="alert">{{ invalid }}</p>
    <p v-if="loading" role="status">Se încarcă graficele…</p>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="load">Reîncearcă</AppButton>
    </div>
    <p v-if="report && !report.charts.length" class="projects-muted">
      Nu există grafice active. Graficele se configurează din Setări proiect → Charts.
    </p>
    <ProjectConfiguredChart
      v-for="chart in report?.charts ?? []"
      :key="chart.id"
      :chart="chart"
      :start="start"
      :end="end"
      :grouping="grouping[chart.id] ?? 'auto'"
      @grouping="grouping[chart.id] = $event"
    />
  </section>
</template>

<style src="./projectCharts.css"></style>
<style scoped>
.configured-charts {
  padding-bottom: max(var(--space-3), env(safe-area-inset-bottom));
}
</style>
