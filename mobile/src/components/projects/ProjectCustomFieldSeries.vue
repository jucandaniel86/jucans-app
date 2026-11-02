<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectFieldSeriesChart from '@/components/projects/ProjectFieldSeriesChart.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { projectFieldSeriesApi } from '@/services/projectFieldSeriesApi'
import { ApiError } from '@/services/api'
import { projectError } from '@/utils/projectPresentation'
import { shiftDate, today } from '@/utils/projectTasks'
import type { ProjectCustomField, ProjectNoteType } from '@/types/projectNoteConfiguration'
import type { FieldSeries, FieldSeriesInterval } from '@/types/projectFieldSeries'

const props = defineProps<{ projectId: number }>()
const emit = defineEmits<{ accessChanged: [] }>()
const fields = ref<ProjectCustomField[]>([])
const types = ref<ProjectNoteType[]>([])
const field = ref('')
const noteType = ref('')
const to = ref(today('UTC'))
const from = ref(shiftDate(to.value, -29))
const interval = ref<FieldSeriesInterval>('day')
const loadingOptions = ref(false)
const optionsError = ref('')
const loading = ref(false)
const error = ref('')
const incompatible = ref(false)
const report = ref<FieldSeries | null>(null)
const page = ref(1)
let sequence = 0
let optionsSequence = 0
let disposed = false
const labels = {
  average: 'Medie',
  sum: 'Sumă',
  min: 'Minim',
  max: 'Maxim',
  latest: 'Ultima valoare',
}
function calendarDate(value: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith('0000')) return null
  const date = new Date(`${value}T00:00:00Z`)
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value
    ? date.getTime()
    : null
}
const invalidRange = computed(() => {
  const a = calendarDate(from.value),
    b = calendarDate(to.value)
  if (a === null || b === null) return 'Alege două date valide.'
  if (a > b) return 'Data de început trebuie să fie înaintea datei de sfârșit.'
  return (b - a) / 86400000 >= 366 ? 'Intervalul maxim este de 366 de zile calendaristice.' : ''
})
function access(failure: unknown): void {
  if (failure instanceof ApiError && [401, 403].includes(failure.status)) emit('accessChanged')
}
async function loadOptions(): Promise<void> {
  const request = ++optionsSequence
  loadingOptions.value = true
  optionsError.value = ''
  try {
    const [fieldResponse, typeResponse] = await Promise.all([
      projectNoteConfigurationApi.fields(props.projectId),
      projectNoteConfigurationApi.noteTypes(props.projectId),
    ])
    if (disposed || request !== optionsSequence) return
    fields.value = fieldResponse.data.filter((item) => item.type === 'number')
    types.value = typeResponse.data
  } catch (failure) {
    if (disposed || request !== optionsSequence) return
    optionsError.value = projectError(failure, 'Nu am putut încărca lista câmpurilor.')
    access(failure)
  } finally {
    if (!disposed && request === optionsSequence) loadingOptions.value = false
  }
}
async function load(): Promise<void> {
  const request = ++sequence
  report.value = null
  error.value = ''
  incompatible.value = false
  loading.value = false
  if (!field.value || invalidRange.value) return
  loading.value = true
  try {
    const response = await projectFieldSeriesApi.series(props.projectId, Number(field.value), {
      from: from.value,
      to: to.value,
      interval: interval.value,
      note_type_id: noteType.value,
      page: page.value,
    })
    if (!disposed && request === sequence) report.value = response.data
  } catch (failure) {
    if (disposed || request !== sequence) return
    incompatible.value =
      failure instanceof ApiError && failure.status === 409 && Boolean(failure.errors.history)
    error.value =
      incompatible.value && failure instanceof ApiError
        ? (failure.errors.history?.[0] ?? 'Istoric incompatibil.')
        : projectError(failure, 'Nu am putut încărca seria numerică.')
    access(failure)
  } finally {
    if (!disposed && request === sequence) loading.value = false
  }
}
watch([field, noteType, from, to, interval], () => {
  page.value = 1
  void load()
})
function navigate(value: number): void {
  page.value = value
  void load()
}
watch(
  () => props.projectId,
  () => {
    sequence++
    fields.value = []
    types.value = []
    field.value = ''
    noteType.value = ''
    report.value = null
    error.value = ''
    loading.value = false
    void loadOptions()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  disposed = true
  sequence++
  optionsSequence++
})
</script>

<template>
  <section class="project-chart-card project-chart-stack" aria-label="Valori Custom Field">
    <h3>Valori Custom Field</h3>
    <p v-if="loadingOptions" role="status">Se încarcă lista câmpurilor…</p>
    <div v-else-if="optionsError" role="alert" class="projects-error">
      <p>{{ optionsError }}</p>
      <AppButton variant="secondary" @click="loadOptions">Reîncearcă</AppButton>
    </div>
    <p v-else-if="!fields.length" class="projects-muted">
      Proiectul nu are câmpuri numerice configurate.
    </p>
    <template v-else>
      <div class="field-series-controls">
        <label
          >Câmp numeric
          <select v-model="field">
            <option value="">Alege un câmp</option>
            <option v-for="item in fields" :key="item.id" :value="String(item.id)">
              {{ item.name }} · #{{ item.id }}{{ item.archived_at ? ' · arhivat' : '' }}
            </option>
          </select>
        </label>
        <label
          >Note Type
          <select v-model="noteType">
            <option value="">Toate tipurile</option>
            <option v-for="item in types" :key="item.id" :value="String(item.id)">
              {{ item.name }}{{ item.archived_at ? ' · arhivat' : '' }}
            </option>
          </select>
        </label>
        <label
          >De la<input v-model="from" type="date" :aria-invalid="Boolean(invalidRange)"
        /></label>
        <label
          >Până la<input v-model="to" type="date" :aria-invalid="Boolean(invalidRange)"
        /></label>
        <label
          >Grupare<select v-model="interval">
            <option value="raw">Observații individuale</option>
            <option value="day">Zilnic</option>
            <option value="week">Săptămânal</option>
            <option value="month">Lunar</option>
          </select></label
        >
      </div>
      <p v-if="invalidRange" role="alert" class="projects-error">{{ invalidRange }}</p>
      <p class="projects-muted">
        Datele sunt datele observațiilor; „astăzi” este calculat în UTC. Maximum 366 de zile,
        săptămâni de luni.
      </p>
      <p v-if="!field" class="projects-muted">
        Alege un câmp numeric pentru a vedea valorile salvate.
      </p>
      <p v-if="loading" role="status">Se încarcă observațiile…</p>
      <div v-if="error" role="alert" class="projects-error">
        <p>{{ incompatible ? 'Istoric incompatibil: ' : '' }}{{ error }}</p>
        <AppButton variant="secondary" @click="load">Reîncearcă</AppButton>
      </div>
      <template v-if="report">
        <p>
          {{ report.field.name }}{{ report.field.archived_at ? ' · arhivat' : '' }} · Unitate:
          {{
            report.field.unit === null
              ? 'fără unitate'
              : report.field.unit === ''
                ? '(goală)'
                : report.field.unit
          }}
          · Agregare: {{ labels[report.aggregation] }} · {{ report.observation_count }} observații
        </p>
        <p class="projects-muted">
          Gruparea folosește agregarea curentă a câmpului; observațiile istorice rămân neschimbate.
          Ultima valoare este aleasă după data observației, apoi ID-ul notei.
        </p>
        <ProjectFieldSeriesChart :report="report" />
        <nav v-if="report.pagination" class="project-chart-pages" aria-label="Pagini observații">
          <button
            type="button"
            class="task-quick"
            :disabled="loading || report.pagination.page <= 1"
            @click="navigate(page - 1)"
          >
            ‹ Anterior
          </button>
          <span
            >{{ report.pagination.page }}/{{ report.pagination.last_page }} ·
            {{ report.pagination.total }} observații</span
          >
          <button
            type="button"
            class="task-quick"
            :disabled="loading || report.pagination.page >= report.pagination.last_page"
            @click="navigate(page + 1)"
          >
            Următor ›
          </button>
        </nav>
      </template>
    </template>
  </section>
</template>

<style scoped>
.field-series-controls {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
  gap: 10px;
}
label {
  display: grid;
  gap: 4px;
  min-width: 0;
}
input,
select {
  min-width: 0;
  width: 100%;
  min-height: 44px;
  font: inherit;
  font-size: 16px;
  box-sizing: border-box;
}
p {
  margin: 0;
  overflow-wrap: anywhere;
}
</style>
