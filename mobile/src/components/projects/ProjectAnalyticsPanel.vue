<script setup lang="ts">
import { computed } from 'vue'
import ProjectMetricChart from '@/components/projects/ProjectMetricChart.vue'
import type { AnalyticsTimeline } from '@/types/projectCharts'
import { chartNumber, metricPoints, periodMetrics } from '@/utils/projectCharts'
import { displayDate } from '@/utils/projectTasks'

const props = defineProps<{ report: AnalyticsTimeline; task?: boolean }>()
const metrics = computed(() => periodMetrics(props.report.series))
const scores = computed(() => metricPoints(props.report.series, 'score'))
const ratios = computed(() => metricPoints(props.report.series, 'ratio'))
const completions = computed(() => metricPoints(props.report.series, 'done'))
</script>

<template>
  <div class="project-chart-stack">
    <p class="projects-muted">
      {{ displayDate(report.range.from) }} – {{ displayDate(report.range.to) }}
    </p>
    <div class="project-chart-stats" aria-label="Statistici pentru intervalul afișat">
      <div>
        <strong
          >{{ chartNumber(metrics.average)
          }}<small v-if="metrics.average !== null">/10</small></strong
        ><span>Media evaluărilor</span>
      </div>
      <div>
        <strong>{{ metrics.evaluationCount }}</strong
        ><span>Evaluări active</span>
      </div>
      <div v-if="report.progress_type === 'daily_completion'">
        <strong>{{ metrics.completed }}/{{ metrics.scheduled }}</strong
        ><span>Execuții programate Done</span>
      </div>
      <div v-if="report.progress_type === 'daily_completion'">
        <strong
          >{{ chartNumber(metrics.percentage)
          }}<small v-if="metrics.percentage !== null">%</small></strong
        ><span>Rată în interval</span>
      </div>
      <div>
        <strong>{{ metrics.done }}</strong
        ><span>Execuții Done în interval</span>
      </div>
    </div>
    <p class="projects-muted">Media include doar evaluările disponibile în perioada afișată.</p>
    <ProjectMetricChart
      v-if="report.progress_type === 'scored' || task || metrics.evaluationCount > 0"
      title="Evoluția evaluărilor"
      :points="scores"
      :minimum="1"
      :maximum="10"
      empty="Nu există evaluări în această perioadă."
    />
    <ProjectMetricChart
      v-if="report.progress_type === 'daily_completion'"
      title="Finalizări zilnice programate (%)"
      :points="ratios"
      :maximum="100"
      empty="Nu există execuții programate eligibile în acest interval."
    />
    <ProjectMetricChart
      title="Execuții Done pe zi"
      :points="completions"
      empty="Nu există execuții în acest interval."
    />
    <p class="projects-muted">
      Done arată execuțiile finalizate în prezent. Undo actualizează graficul.
    </p>
  </div>
</template>
