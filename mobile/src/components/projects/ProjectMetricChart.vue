<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import type { ChartPoint } from '@/types/projectCharts'
import { chartNumber, chartSegments } from '@/utils/projectCharts'
import { displayDate } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    title: string
    points: ChartPoint[]
    minimum?: number
    maximum?: number
    empty: string
  }>(),
  { minimum: 0 },
)
const labelId = useId()
const selected = ref(0)
watch(
  () => props.points,
  (points) => {
    selected.value = Math.max(0, points.length - 1)
  },
  { immediate: true },
)
const ceiling = computed(
  () => props.maximum ?? Math.max(1, ...props.points.map((point) => point.value ?? 0)),
)
const x = (index: number) =>
  props.points.length < 2 ? 184 : 36 + (index * 296) / (props.points.length - 1)
const y = (value: number) =>
  136 - ((value - props.minimum) / (ceiling.value - props.minimum || 1)) * 116
const paths = computed(() =>
  chartSegments(props.points).map((group) =>
    group
      .map((point, index) => `${index ? 'L' : 'M'}${x(point.index)},${y(point.value)}`)
      .join(' '),
  ),
)
const hasData = computed(() => props.points.some((point) => point.value !== null))
const current = computed(() => props.points[selected.value])
function pick(event: MouseEvent): void {
  const bounds = (event.currentTarget as SVGSVGElement).getBoundingClientRect()
  if (!bounds.width) return
  selected.value = Math.max(
    0,
    Math.min(
      props.points.length - 1,
      Math.round(
        ((((event.clientX - bounds.left) / bounds.width) * 348 - 36) / 296) *
          (props.points.length - 1),
      ),
    ),
  )
}
</script>

<template>
  <section class="project-chart-card" :aria-labelledby="labelId">
    <h4 :id="labelId">{{ title }}</h4>
    <svg
      v-if="hasData"
      class="project-chart-svg"
      viewBox="0 0 348 166"
      role="img"
      :aria-label="`${title}. Selectează o dată mai jos pentru valorile exacte.`"
      @click="pick"
    >
      <title>{{ title }} · Selectează o dată mai jos pentru valorile exacte.</title>
      <line
        v-for="level in [minimum, (minimum + ceiling) / 2, ceiling]"
        :key="level"
        x1="36"
        x2="332"
        :y1="y(level)"
        :y2="y(level)"
        class="project-chart-grid"
      />
      <text x="2" y="24">{{ chartNumber(ceiling) }}</text>
      <text x="2" y="139">{{ minimum }}</text>
      <path v-for="(path, index) in paths" :key="index" :d="path" class="project-chart-line" />
      <template v-for="(point, index) in points" :key="point.date">
        <circle
          v-if="point.value !== null"
          :cx="x(index)"
          :cy="y(point.value)"
          :r="selected === index ? 4.5 : 2.5"
          class="project-chart-dot"
        >
          <title>{{ displayDate(point.date) }} · {{ point.detail }}</title>
        </circle>
      </template>
      <text x="36" y="160">{{ points[0]?.date.slice(5).split('-').reverse().join('.') }}</text>
      <text x="332" y="160" text-anchor="end">
        {{ points.at(-1)?.date.slice(5).split('-').reverse().join('.') }}
      </text>
    </svg>
    <p v-else class="projects-muted">{{ empty }}</p>
    <label v-if="points.length" class="project-chart-date"
      >Valori pe zi
      <select v-model.number="selected">
        <option v-for="(point, index) in points" :key="point.date" :value="index">
          {{ displayDate(point.date) }} · {{ point.detail }}
        </option>
      </select>
    </label>
    <p v-if="current" class="project-chart-value" aria-live="polite">
      {{ displayDate(current.date) }} · {{ current.detail }}
    </p>
  </section>
</template>
