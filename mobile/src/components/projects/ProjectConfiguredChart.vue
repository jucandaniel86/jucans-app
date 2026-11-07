<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { BarGrouping, ConfiguredChartData } from '@/types/projectChartDefinitions'
import { displayDate } from '@/utils/projectTasks'

const props = defineProps<{
  chart: ConfiguredChartData
  start: string
  end: string
  grouping: BarGrouping
}>()
const emit = defineEmits<{ grouping: [value: BarGrouping] }>()
const clipId = useId()
const selected = ref(0)
const colors = [
  '#087f8a',
  '#294761',
  '#bf7c16',
  '#ad4278',
  '#438354',
  '#6c58b3',
  '#c05438',
  '#3c80b0',
  '#8c6c48',
  '#50737a',
  '#a15c9d',
  '#677d28',
]
const color = (index: number) => colors[index % colors.length]
const points = computed(() => props.chart.series[0]?.points ?? [])
watch(
  () => props.chart,
  () => {
    selected.value = Math.max(0, points.value.length - 1)
  },
)
const selectedValues = computed(() =>
  props.chart.series
    .map((series) => ({ series, point: series.points[selected.value] }))
    .filter((item) => item.point?.value !== null && item.point?.value !== undefined),
)
function decimal(value: string): string {
  const [integer = '', fraction] = value.split('.')
  const trimmed = fraction?.replace(/0+$/, '')
  return integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (trimmed ? `,${trimmed}` : '')
}
const values = computed(() =>
  props.chart.series
    .flatMap((series) =>
      series.points.flatMap((point) => (point.value === null ? [] : [Number(point.value)])),
    )
    .filter(Number.isFinite),
)
const limits = computed(() => {
  let min = values.value.length ? Math.min(...values.value) : 0
  let max = values.value.length ? Math.max(...values.value) : 1
  if (props.chart.type === 'bar') {
    min = Math.min(0, min)
    max = Math.max(0, max)
  }
  const padding = min === max ? Math.max(Math.abs(min) * 0.05, 1) : (max - min) * 0.08
  min -= padding
  max += padding
  if (props.chart.y_min !== null) min = Number(props.chart.y_min)
  if (props.chart.y_max !== null) max = Number(props.chart.y_max)
  if (max <= min) {
    if (props.chart.y_min !== null && props.chart.y_max === null) max = min + padding
    else min = max - padding
  }
  return { min, max }
})
const y = (value: number) =>
  140 - ((value - limits.value.min) / (limits.value.max - limits.value.min || 1)) * 122
const x = (index: number) =>
  points.value.length <= 1 ? 184 : 40 + (index * 288) / (points.value.length - 1)
const paths = computed(() =>
  props.chart.series.map((series) =>
    series.points
      .map((point, index) => ({ point, index }))
      .filter((item) => item.point.value !== null)
      .map((item, index) => `${index ? 'L' : 'M'}${x(item.index)},${y(Number(item.point.value))}`)
      .join(' '),
  ),
)
const slotWidth = computed(() => 288 / Math.max(1, points.value.length))
const barWidth = computed(() =>
  Math.max(0.2, (slotWidth.value * 0.82) / Math.max(1, props.chart.series.length)),
)
const barX = (bucket: number, series: number) =>
  40 + slotWidth.value * bucket + slotWidth.value * 0.09 + series * barWidth.value
const axis = (value: number) =>
  new Intl.NumberFormat('ro-RO', { notation: 'compact', maximumFractionDigits: 2 }).format(value)
const negativePie = computed(() =>
  props.chart.segments.some((segment) => Number(segment.value) < 0),
)
const pieTotal = computed(() =>
  props.chart.segments.reduce((sum, segment) => sum + Math.max(0, Number(segment.value)), 0),
)
const pieArcs = computed(() => {
  let offset = 0
  return props.chart.segments.map((segment) => {
    const length = pieTotal.value ? (Math.max(0, Number(segment.value)) / pieTotal.value) * 100 : 0
    const arc = { length, offset }
    offset += length
    return arc
  })
})
function segmentText(segment: ConfiguredChartData['segments'][number]): string {
  if (props.chart.pie_aggregation === 'sum') {
    const amount = `${decimal(segment.value)} ${props.chart.unit ?? ''}`.trim()
    return segment.percentage === undefined
      ? amount
      : `${amount} · ${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 2 }).format(segment.percentage)}%`
  }
  if (segment.percentage === undefined)
    return `${decimal(segment.value)} ${props.chart.unit ?? ''}`.trim()
  const percentage = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 2 }).format(
    segment.percentage,
  )
  return `${decimal(segment.value)} note · ${percentage}%`
}
function selectOnPlot(event: MouseEvent): void {
  const rect = (event.currentTarget as SVGElement).getBoundingClientRect()
  if (!rect.width) return
  const position = (((event.clientX - rect.left) / rect.width) * 348 - 40) / 288
  selected.value = Math.min(
    points.value.length - 1,
    Math.max(0, Math.round(position * (points.value.length - 1))),
  )
}
</script>

<template>
  <article class="configured-chart">
    <header class="configured-chart-heading">
      <h3>{{ chart.title }}</h3>
      <label v-if="chart.type === 'bar'" class="configured-chart-group"
        ><span class="chart-sr-only">Grupare pentru {{ chart.title }}</span
        ><select
          :value="grouping"
          @change="emit('grouping', ($event.target as HTMLSelectElement).value as BarGrouping)"
        >
          <option value="auto">Auto</option>
          <option value="day">Zi</option>
          <option value="week">Săptămână</option>
          <option value="month">Lună</option>
        </select></label
      >
    </header>
    <p v-if="chart.error" class="projects-error" role="alert">{{ chart.error }}</p>
    <p v-else-if="!chart.observation_count" class="projects-muted">
      Nu există înregistrări pentru acest grafic în intervalul selectat.
    </p>
    <template v-else-if="chart.type === 'pie'">
      <p v-if="chart.total" class="configured-chart-total">
        Total: <strong>{{ decimal(chart.total) }} {{ chart.unit }}</strong>
      </p>
      <p v-if="chart.pie_message" class="projects-muted">{{ chart.pie_message }}</p>
      <p v-else-if="negativePie" class="projects-muted">
        Sumele negative sunt afișate mai jos, fără reprezentare în cerc.
      </p>
      <svg
        v-else-if="pieTotal > 0"
        class="configured-chart-pie"
        viewBox="0 0 180 180"
        role="img"
        :aria-label="
          chart.segments.map((segment) => `${segment.label}: ${segmentText(segment)}`).join('; ')
        "
      >
        <circle
          v-for="(segment, index) in chart.segments"
          :key="segment.option_id ?? segment.label"
          cx="90"
          cy="90"
          r="62"
          pathLength="100"
          fill="none"
          :stroke="color(index)"
          stroke-width="27"
          :stroke-dasharray="`${pieArcs[index]!.length} ${100 - pieArcs[index]!.length}`"
          :stroke-dashoffset="-pieArcs[index]!.offset"
          transform="rotate(-90 90 90)"
        >
          <title>{{ segment.label }}: {{ segmentText(segment) }}</title>
        </circle>
      </svg>
      <dl class="configured-chart-segments">
        <div v-for="(segment, index) in chart.segments" :key="segment.option_id ?? segment.label">
          <dt><i :style="{ background: color(index) }" />{{ segment.label }}</dt>
          <dd>{{ segmentText(segment) }}</dd>
        </div>
      </dl>
      <p v-if="chart.excess && Number(chart.excess) > 0" class="configured-chart-total">
        Depășire: <strong>{{ decimal(chart.excess) }} {{ chart.unit }}</strong>
      </p>
    </template>
    <template v-else>
      <div class="configured-chart-legend">
        <span v-for="(series, index) in chart.series" :key="series.field_id"
          ><i :style="{ background: color(index) }" />{{ series.name
          }}{{ series.unit ? ` (${series.unit})` : ''
          }}{{ series.archived ? ' · arhivat' : '' }}</span
        >
      </div>
      <svg
        class="project-chart-svg"
        viewBox="0 0 348 172"
        role="img"
        :aria-label="`${chart.title}. Valorile exacte sunt disponibile mai jos.`"
        @click="selectOnPlot"
      >
        <defs>
          <clipPath :id="clipId"><rect x="39" y="12" width="290" height="135" /></clipPath>
        </defs>
        <line
          v-for="level in [limits.min, (limits.min + limits.max) / 2, limits.max]"
          :key="level"
          x1="40"
          x2="328"
          :y1="y(level)"
          :y2="y(level)"
          class="project-chart-grid"
        />
        <text x="0" y="22">{{ axis(limits.max) }}</text>
        <text x="0" y="142">{{ axis(limits.min) }}</text>
        <g :clip-path="`url(#${clipId})`">
          <template v-for="(series, seriesIndex) in chart.series" :key="series.field_id">
            <path
              v-if="chart.type === 'line'"
              :d="paths[seriesIndex]"
              fill="none"
              :stroke="color(seriesIndex)"
              stroke-width="2"
            />
            <template v-for="(point, index) in series.points" :key="point.date">
              <circle
                v-if="chart.type === 'line' && point.value !== null"
                :cx="x(index)"
                :cy="y(Number(point.value))"
                :r="selected === index ? 4 : 2.5"
                :fill="color(seriesIndex)"
              >
                <title>
                  {{ displayDate(point.from) }} · {{ series.name }}: {{ decimal(point.value) }}
                </title>
              </circle>
              <rect
                v-else-if="chart.type === 'bar' && point.value !== null"
                :x="barX(index, seriesIndex)"
                :y="Math.min(y(0), y(Number(point.value)))"
                :width="barWidth"
                :height="Math.max(1, Math.abs(y(0) - y(Number(point.value))))"
                :fill="color(seriesIndex)"
              >
                <title>
                  {{ displayDate(point.from) }} – {{ displayDate(point.to) }} · {{ series.name }}:
                  {{ decimal(point.value) }}
                </title>
              </rect>
            </template>
          </template>
        </g>
        <text x="40" y="166">{{ displayDate(start) }}</text>
        <text x="328" y="166" text-anchor="end">{{ displayDate(end) }}</text>
      </svg>
      <label class="configured-chart-point"
        >{{ chart.type === 'line' ? 'Zi' : 'Perioadă'
        }}<select v-model.number="selected">
          <option v-for="(point, index) in points" :key="point.date" :value="index">
            {{ displayDate(point.from)
            }}{{ point.from !== point.to ? ` – ${displayDate(point.to)}` : '' }}
          </option>
        </select></label
      >
      <dl v-if="selectedValues.length" class="configured-chart-segments">
        <div v-for="item in selectedValues" :key="item.series.field_id">
          <dt>{{ item.series.name }}</dt>
          <dd>
            {{ decimal(item.point!.value!) }} {{ item.series.unit }}
            <small>· {{ item.point!.count }} obs.</small>
          </dd>
        </div>
      </dl>
      <p v-else class="projects-muted">Fără înregistrări pentru data selectată.</p>
    </template>
  </article>
</template>

<style src="./projectChartDefinitions.css"></style>
