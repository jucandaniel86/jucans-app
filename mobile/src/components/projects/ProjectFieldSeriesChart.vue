<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { FieldSeries } from '@/types/projectFieldSeries'
import { displayDate } from '@/utils/projectTasks'

const props = defineProps<{ report: FieldSeries }>()
const labelId = useId()
const selected = ref(0)
watch(
  () => props.report,
  () => {
    selected.value = 0
  },
)
// UTC is used for calendar arithmetic only, never local-time conversion.
function day(date: string): number {
  const [year = 0, month = 1, dateDay = 1] = date.split('-').map(Number)
  const utc = new Date(0)
  utc.setUTCFullYear(year, month - 1, dateDay)
  utc.setUTCHours(0, 0, 0, 0)
  return utc.getTime() / 86400000
}
const numeric = computed(() =>
  props.report.points.map((p) => (p.value === null ? null : Number(p.value))),
)
const limits = computed(() => {
  const values = numeric.value.filter((v): v is number => v !== null && Number.isFinite(v))
  const min = values.length ? Math.min(...values) : 0
  const max = values.length ? Math.max(...values) : 1
  const padding = max === min ? Math.max(Math.abs(min) * 0.05, 0.000001) : (max - min) * 0.08
  return { min: min - padding, max: max + padding }
})
const extrema = computed(() => {
  const valued = props.report.points.filter((p) => p.value !== null)
  if (!valued.length) return { min: '', max: '' }
  const scaled = (value: string) => BigInt(value.replace('.', ''))
  const ordered = [...valued].sort((a, b) => {
    const left = scaled(a.value!)
    const right = scaled(b.value!)
    return left < right ? -1 : left > right ? 1 : 0
  })
  return { min: ordered[0]?.value ?? '', max: ordered.at(-1)?.value ?? '' }
})
const from = computed(() => day(props.report.range.from))
const to = computed(() => day(props.report.range.to))
function x(date: string): number {
  return to.value === from.value
    ? 184
    : 36 + ((day(date) - from.value) / (to.value - from.value)) * 296
}
const positionDate = (point: FieldSeries['points'][number]) => point.included_from ?? point.date
const y = (value: number) =>
  136 - ((value - limits.value.min) / (limits.value.max - limits.value.min)) * 116
const paths = computed(() => {
  if (props.report.interval === 'raw') return []
  const result: string[] = []
  let path = ''
  props.report.points.forEach((point, index) => {
    const value = numeric.value[index]
    if (value === null || value === undefined || !Number.isFinite(value)) {
      if (path) result.push(path)
      path = ''
    } else path += `${path ? ' L' : 'M'}${x(positionDate(point))},${y(value)}`
  })
  if (path) result.push(path)
  return result
})
const current = computed(() => props.report.points[selected.value])
const hasData = computed(() => numeric.value.some((v) => v !== null && Number.isFinite(v)))
function detail(point: FieldSeries['points'][number]): string {
  const date = point.bucket_from
    ? `${displayDate(point.bucket_from)}–${displayDate(point.bucket_to ?? point.date)}`
    : displayDate(point.date)
  return `${date}${point.note_id ? ` · nota #${point.note_id}` : ''} · ${point.value ?? 'Fără valori'}${point.value !== null && props.report.field.unit !== null ? ` ${props.report.field.unit}` : ''} · ${point.count} observații${point.partial ? ' · interval parțial' : ''}`
}
</script>

<template>
  <div class="field-series-plot">
    <svg
      v-if="hasData"
      class="project-chart-svg"
      viewBox="0 0 348 166"
      role="img"
      :aria-labelledby="labelId"
    >
      <title :id="labelId">
        {{ report.field.name }} · valorile exacte sunt disponibile în selectorul de mai jos
      </title>
      <line
        v-for="level in [limits.min, (limits.min + limits.max) / 2, limits.max]"
        :key="level"
        x1="36"
        x2="332"
        :y1="y(level)"
        :y2="y(level)"
        class="project-chart-grid"
      />
      <text x="36" y="14">{{ extrema.max }}</text>
      <text x="36" y="149">{{ extrema.min }}</text>
      <path v-for="(path, index) in paths" :key="index" :d="path" class="project-chart-line" />
      <template v-for="(point, index) in report.points" :key="point.id">
        <circle
          v-if="point.value !== null"
          :cx="x(positionDate(point))"
          :cy="y(Number(point.value))"
          :r="selected === index ? 4.5 : 2.5"
          class="project-chart-dot"
          @click="selected = index"
        >
          <title>{{ detail(point) }}</title>
        </circle>
      </template>
      <text x="36" y="160">{{ displayDate(report.range.from) }}</text>
      <text x="332" y="160" text-anchor="end">{{ displayDate(report.range.to) }}</text>
    </svg>
    <p v-else class="projects-muted">Nu există observații numerice în intervalul selectat.</p>
    <label v-if="report.points.length"
      >Valori exacte
      <select v-model.number="selected">
        <option v-for="(point, index) in report.points" :key="point.id" :value="index">
          {{ detail(point) }}
        </option>
      </select>
    </label>
    <p v-if="current" aria-live="polite">{{ detail(current) }}</p>
    <p v-if="current?.partial" class="projects-muted">
      Observații incluse: {{ displayDate(current.included_from ?? null) }}–{{
        displayDate(current.included_to ?? null)
      }}.
    </p>
  </div>
</template>

<style scoped>
.field-series-plot {
  min-width: 0;
}
label {
  display: grid;
  gap: 4px;
}
select {
  min-width: 0;
  max-width: 100%;
  min-height: 44px;
  font: inherit;
  font-size: 16px;
}
p {
  overflow-wrap: anywhere;
  margin: 8px 0 0;
}
</style>
