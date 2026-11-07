<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { projectChartDefinitionsApi } from '@/services/projectChartDefinitionsApi'
import { ApiError } from '@/services/api'
import { projectError } from '@/utils/projectPresentation'
import type { ProjectCustomField } from '@/types/projectNoteConfiguration'
import type {
  ProjectChartDefinition,
  ProjectChartPayload,
  ProjectChartType,
  PieAggregation,
} from '@/types/projectChartDefinitions'

const props = defineProps<{
  projectId: number
  fields: ProjectCustomField[]
  chart?: ProjectChartDefinition
}>()
const emit = defineEmits<{ saved: []; cancel: []; busy: [value: boolean] }>()
const form = reactive({
  title: props.chart?.title ?? '',
  type: props.chart?.type ?? ('line' as ProjectChartType),
  priority: props.chart?.priority ?? 0,
  active: props.chart?.active ?? true,
  total: props.chart?.total ?? '',
  y_min: props.chart?.y_min ?? '',
  y_max: props.chart?.y_max ?? '',
  field_ids: [...(props.chart?.field_ids ?? [])],
  pie_aggregation: props.chart?.pie_aggregation ?? ('count' as PieAggregation),
  pie_value_field_id: String(props.chart?.pie_value_field_id ?? ''),
})
const busy = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const compatible = (field: ProjectCustomField) =>
  form.type === 'pie'
    ? ['boolean', 'select'].includes(field.type) ||
      (field.type === 'number' && field.aggregation === 'sum')
    : field.type === 'number' &&
      ['average', 'sum', 'min', 'max', 'latest'].includes(field.aggregation ?? '')
const choices = computed(() =>
  props.fields.filter(
    (field) =>
      compatible(field) && (!field.archived_at || props.chart?.field_ids.includes(field.id)),
  ),
)
const pieField = computed(() => props.fields.find((field) => field.id === form.field_ids[0]))
const numericPie = computed(() => form.type === 'pie' && pieField.value?.type === 'number')
const selectPie = computed(() => form.type === 'pie' && pieField.value?.type === 'select')
const valueChoices = computed(() =>
  props.fields.filter(
    (field) =>
      field.type === 'number' &&
      (!field.archived_at || field.id === props.chart?.pie_value_field_id),
  ),
)
watch(
  () => form.pie_aggregation,
  (value) => {
    if (value !== 'sum') form.pie_value_field_id = ''
  },
)
watch(selectPie, (value) => {
  if (!value) {
    form.pie_aggregation = 'count'
    form.pie_value_field_id = ''
  }
})
const pieSelected = computed({
  get: () => String(form.field_ids[0] ?? ''),
  set: (value) => {
    form.field_ids = value ? [Number(value)] : []
  },
})
function changeType(): void {
  form.field_ids = form.field_ids.filter((id) => choices.value.some((field) => field.id === id))
  if (form.type === 'pie') form.field_ids = form.field_ids.slice(0, 1)
}
async function save(): Promise<void> {
  if (busy.value) return
  error.value = ''
  errors.value = {}
  if (!form.title.trim() || !form.field_ids.length) {
    error.value = 'Completează titlul și selectează câmpurile graficului.'
    return
  }
  if (!Number.isInteger(Number(form.priority))) {
    error.value = 'Priority trebuie să fie un număr întreg.'
    return
  }
  if (
    selectPie.value &&
    form.pie_aggregation === 'sum' &&
    !valueChoices.value.some((field) => String(field.id) === form.pie_value_field_id)
  ) {
    error.value = 'Selectează un Value Field de tip Number pentru SUM.'
    return
  }
  busy.value = true
  emit('busy', true)
  try {
    const payload: ProjectChartPayload = {
      title: form.title.trim(),
      type: form.type,
      priority: Number(form.priority),
      active: form.active,
      field_ids: [...form.field_ids],
      ...(selectPie.value
        ? {
            pie_aggregation: form.pie_aggregation,
            pie_value_field_id:
              form.pie_aggregation === 'sum' ? Number(form.pie_value_field_id) : null,
          }
        : {}),
      total: numericPie.value ? decimalInput(form.total) : null,
      y_min: form.type === 'line' ? decimalInput(form.y_min) : null,
      y_max: form.type === 'line' ? decimalInput(form.y_max) : null,
    }
    if (props.chart)
      await projectChartDefinitionsApi.update(props.projectId, props.chart.id, payload)
    else await projectChartDefinitionsApi.create(props.projectId, payload)
    emit('saved')
  } catch (failure) {
    if (failure instanceof ApiError) errors.value = failure.errors
    error.value = projectError(failure, 'Nu am putut salva graficul.')
  } finally {
    busy.value = false
    emit('busy', false)
  }
}
function decimalInput(value: string): string | null {
  return value.trim().replace(',', '.') || null
}
</script>

<template>
  <form class="chart-config-form" novalidate @submit.prevent="save">
    <h3>{{ chart ? 'Editează graficul' : 'Adaugă grafic' }}</h3>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <p v-for="(messages, field) in errors" :key="field">{{ messages.join(' ') }}</p>
    </div>
    <fieldset :disabled="busy" class="chart-config-fields">
      <AppInput v-model="form.title" label="Titlu" :maxlength="255" :error="errors.title?.[0]" />
      <AppSelect
        v-model="form.type"
        label="Tip grafic"
        :options="[
          { value: 'pie', label: 'Pie / Donut' },
          { value: 'line', label: 'Line' },
          { value: 'bar', label: 'Bar' },
        ]"
        @update:model-value="changeType"
      />
      <div class="chart-config-row">
        <label>Priority<input v-model="form.priority" type="number" step="1" /></label
        ><label class="chart-config-check"
          ><input v-model="form.active" type="checkbox" /> Activ</label
        >
      </div>
      <AppSelect
        v-if="form.type === 'pie'"
        v-model="pieSelected"
        :label="selectPie ? 'Group By' : 'Custom Field'"
        :options="[
          { value: '', label: 'Alege câmpul' },
          ...choices.map((field) => ({
            value: String(field.id),
            label: field.name + (field.archived_at ? ' (arhivat)' : ''),
          })),
        ]"
      />
      <div v-else class="chart-config-fields">
        <span>Custom Fields ({{ form.field_ids.length }}/12)</span
        ><label v-for="field in choices" :key="field.id" class="chart-config-check"
          ><input
            v-model="form.field_ids"
            type="checkbox"
            :value="field.id"
            :disabled="form.field_ids.length >= 12 && !form.field_ids.includes(field.id)"
          />{{ field.name }}
          <small>{{ field.aggregation }}{{ field.archived_at ? ' · arhivat' : '' }}</small></label
        >
      </div>
      <template v-if="selectPie">
        <AppSelect
          v-model="form.pie_aggregation"
          label="Aggregation"
          :options="[
            { value: 'count', label: 'COUNT' },
            { value: 'sum', label: 'SUM' },
          ]"
          :error="errors.pie_aggregation?.[0]"
        />
        <AppSelect
          v-if="form.pie_aggregation === 'sum'"
          v-model="form.pie_value_field_id"
          label="Value Field *"
          :options="[
            { value: '', label: 'Alege câmpul numeric' },
            ...valueChoices.map((field) => ({
              value: String(field.id),
              label: field.name + (field.archived_at ? ' (arhivat)' : ''),
            })),
          ]"
          :error="errors.pie_value_field_id?.[0]"
        />
      </template>
      <p v-if="!choices.length" class="projects-muted">
        Nu există câmpuri compatibile. Configurează mai întâi Custom Fields.
      </p>
      <label v-if="numericPie"
        >Total<input
          v-model="form.total"
          type="text"
          inputmode="decimal"
          placeholder="Ex. 60000"
        /><span class="projects-muted">Total fix, pozitiv.</span></label
      >
      <div v-if="form.type === 'line'" class="chart-config-row">
        <label>Y Min (opțional)<input v-model="form.y_min" type="text" inputmode="decimal" /></label
        ><label
          >Y Max (opțional)<input v-model="form.y_max" type="text" inputmode="decimal"
        /></label>
      </div>
    </fieldset>
    <div class="task-buttons">
      <AppButton type="submit" :loading="busy">Salvează</AppButton
      ><AppButton variant="secondary" :disabled="busy" @click="emit('cancel')">Renunță</AppButton>
    </div>
  </form>
</template>
