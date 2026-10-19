<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import type { IngredientDraftError } from '@/composables/recipeDraft'
import IngredientAssociationSelector from '@/components/recipe/IngredientAssociationSelector.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect, { type SelectOption } from '@/components/ui/AppSelect.vue'
import type { ExistingIngredient, IngredientReviewDraft } from '@/types/food'
import { compatibleUnits, convertUnit } from '@/utils/unitConvertor'

const props = defineProps<{
  item: IngredientReviewDraft
  index: number
  units: SelectOption[]
  error?: IngredientDraftError
  associationSaving: boolean
  associationError: string
}>()

const emit = defineEmits<{
  update: [item: IngredientReviewDraft]
  chooseCandidate: [candidate: ExistingIngredient]
  chooseNew: []
  remove: []
  associate: [ingredient: ExistingIngredient]
}>()

const associationOpen = ref(false)
const conversionOpen = ref(false)
const conversionTarget = ref('')
const conversionOptions = computed(() => {
  if (!props.item.value.trim()) return []
  const value = Number(props.item.value.trim().replace(',', '.'))
  const compatible = compatibleUnits(props.item.unit)
  return props.units.filter((option) =>
    compatible.includes(option.value) && convertUnit(value, props.item.unit, option.value) !== null,
  )
})
const manualConversion = computed(() => {
  if (!props.item.value.trim()) return null
  const target = conversionOptions.value.find((option) => option.value === conversionTarget.value)
  if (!target) return null
  const value = convertUnit(
    Number(props.item.value.trim().replace(',', '.')),
    props.item.unit,
    target.value,
  )
  return value === null ? null : {
    value,
    unit: target.value,
    label: target.label.match(/\(([^()]*)\)$/)?.[1] ?? target.label,
  }
})

watch(conversionOptions, (options) => {
  if (!options.some((option) => option.value === conversionTarget.value)) {
    conversionTarget.value = options[0]?.value ?? ''
  }
}, { immediate: true })

function applyManualConversion(): void {
  if (!manualConversion.value) return
  emit('update', {
    ...props.item,
    value: String(manualConversion.value.value),
    unit: manualConversion.value.unit,
  })
  conversionOpen.value = false
}

const conversion = computed(() => {
  const { value, unit, defaultUnit, resolution } = props.item
  if (resolution !== 'existing' || !value.trim() || !unit || !defaultUnit || unit === defaultUnit) {
    return null
  }
  const target = props.units.find((option) => option.value === defaultUnit)
  if (!target) return null
  const converted = convertUnit(Number(value.trim().replace(',', '.')), unit, defaultUnit)
  return converted === null ? null : {
    value: converted,
    unit: defaultUnit,
    label: target.label.match(/\(([^()]*)\)$/)?.[1] ?? target.label,
  }
})

function applyConversion(): void {
  if (!conversion.value) return
  emit('update', {
    ...props.item,
    value: String(conversion.value.value),
    unit: conversion.value.unit,
  })
}

const hasError = computed(() => Boolean(props.error && Object.keys(props.error).length > 0))

const state = computed(() => {
  if (props.item.sourceStatus === 'matched') {
    return { symbol: '✓', label: 'Potrivire sigură', className: 'matched' }
  }

  if (props.item.sourceStatus === 'candidates') {
    return { symbol: '?', label: 'Alege varianta', className: 'candidate' }
  }

  return { symbol: '+', label: 'Ingredient nou', className: 'new' }
})

function updateField<K extends keyof IngredientReviewDraft>(
  field: K,
  value: IngredientReviewDraft[K],
): void {
  emit('update', { ...props.item, [field]: value })
}

function selectIngredient(ingredient: ExistingIngredient): void {
  if (props.item.sourceStatus === 'candidates') {
    emit('chooseCandidate', ingredient)
    associationOpen.value = false
  } else {
    emit('associate', ingredient)
  }
}

watch(
  () => props.item.resolution,
  (resolution) => {
    if (resolution === 'existing') associationOpen.value = false
  },
)
</script>

<template>
  <article
    class="ingredient-card"
    :class="[`ingredient-card--${state.className}`, { 'ingredient-card--invalid': hasError }]"
    :data-ingredient-id="item.ingredientId"
    :aria-invalid="hasError"
  >
    <header class="ingredient-card__header">
      <div class="ingredient-card__state">
        <span class="ingredient-card__symbol" aria-hidden="true">{{ state.symbol }}</span>
        <span>{{ state.label }}</span>
      </div>
      <button
        class="ingredient-card__remove"
        type="button"
        :aria-label="`Șterge ingredientul ${index + 1}`"
        @click="$emit('remove')"
      >
        ×
      </button>
    </header>

    <p v-if="item.rawText" class="ingredient-card__raw">„{{ item.rawText }}”</p>

    <p v-if="error?.form" class="ingredient-card__error ingredient-card__error--form" role="alert">
      {{ error.form }}
    </p>

    <fieldset v-if="item.sourceStatus === 'candidates'" class="ingredient-card__choices">
      <legend>Ce ingredient este?</legend>
      <label
        v-for="candidate in item.candidates"
        :key="candidate.id"
        class="candidate-choice"
        :class="{ 'candidate-choice--selected': item.ingredientId === candidate.id }"
      >
        <input
          type="radio"
          :name="`${item.key}-candidate`"
          :checked="item.ingredientId === candidate.id && item.resolution === 'existing'"
          @change="associationOpen = false; $emit('chooseCandidate', candidate)"
        />
        <span>{{ candidate.name }}</span>
      </label>
      <button
        class="ingredient-card__associate"
        type="button"
        @click="associationOpen = true"
      >
        Caută alt ingredient existent
      </button>
      <label
        class="candidate-choice"
        :class="{ 'candidate-choice--selected': item.resolution === 'new' }"
      >
        <input
          type="radio"
          :name="`${item.key}-candidate`"
          :checked="item.resolution === 'new'"
          @change="associationOpen = false; $emit('chooseNew')"
        />
        <span>Este un ingredient nou</span>
      </label>
      <p v-if="error?.choice" class="ingredient-card__error" role="alert">
        {{ error.choice }}
      </p>
    </fieldset>

    <div
      v-if="item.resolution === 'existing' && (item.sourceStatus !== 'candidates' || !item.candidates.some(candidate => candidate.id === item.ingredientId))"
      class="ingredient-card__name"
    >
      {{ item.name }}
    </div>

    <AppInput
      v-if="item.resolution === 'new'"
      :model-value="item.name"
      label="Nume ingredient"
      placeholder="Ex. Gochujang"
      :error="error?.name"
      @update:model-value="updateField('name', $event)"
    />

    <button
      v-if="(item.sourceStatus === 'unresolved' || item.sourceStatus === 'manual') && item.resolution === 'new' && !associationOpen"
      class="ingredient-card__associate"
      type="button"
      @click="associationOpen = true"
    >
      Asociază cu existent
    </button>

    <IngredientAssociationSelector
      v-if="associationOpen && (item.sourceStatus === 'candidates' || ((item.sourceStatus === 'unresolved' || item.sourceStatus === 'manual') && item.resolution === 'new'))"
      :initial-query="item.name"
      :saving="associationSaving"
      :error="associationError"
      @cancel="associationOpen = false"
      @select="selectIngredient"
    />

    <div
      class="ingredient-card__measurements"
      :class="{ 'ingredient-card__measurements--to-taste': item.unit === 'to_taste' }"
    >
      <AppInput
        v-if="item.unit !== 'to_taste'"
        :model-value="item.value"
        label="Cantitate"
        inputmode="decimal"
        placeholder="Opțional"
        :error="error?.value"
        @update:model-value="updateField('value', $event)"
      />
      <AppSelect
        :model-value="item.unit"
        label="Unitate"
        :options="units"
        :error="error?.unit"
        @update:model-value="updateField('unit', $event)"
      />
    </div>
    <div v-if="conversion" class="ingredient-card__conversion">
      <span>Convert to {{ conversion.label }}: {{ conversion.value }} {{ conversion.label }}</span>
      <button class="ingredient-card__associate" type="button" @click="applyConversion">
        Aplică
      </button>
    </div>
    <div v-if="conversionOptions.length">
      <button
        class="ingredient-card__associate"
        type="button"
        :aria-expanded="conversionOpen"
        @click="conversionOpen = !conversionOpen"
      >
        Convert to...
      </button>
      <div v-if="conversionOpen" class="ingredient-card__conversion ingredient-card__conversion-picker">
        <AppSelect v-model="conversionTarget" label="Convert to..." :options="conversionOptions" />
        <span v-if="manualConversion">→ {{ manualConversion.value }} {{ manualConversion.label }}</span>
        <button
          class="ingredient-card__associate"
          type="button"
          :disabled="!manualConversion"
          @click="applyManualConversion"
        >
          Aplică
        </button>
      </div>
    </div>
  </article>
</template>

<style scoped>
.ingredient-card {
  display: grid;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-left: 5px solid var(--color-primary);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}

.ingredient-card--candidate {
  border-left-color: var(--color-yellow);
}

.ingredient-card--new {
  border-left-color: var(--color-pink);
}

.ingredient-card--invalid {
  border-color: var(--color-error);
  border-left-color: var(--color-error);
  background: var(--color-error-soft);
  box-shadow: 0 0 0 2px rgb(196 61 85 / 12%);
}

.ingredient-card__header {
  display: flex;
  min-height: 36px;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}

.ingredient-card__state {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 800;
  text-transform: uppercase;
}

.ingredient-card__symbol {
  display: grid;
  width: 28px;
  height: 28px;
  place-items: center;
  border-radius: 50%;
  color: var(--color-primary-strong);
  font-size: 1rem;
  background: var(--color-primary-soft);
}

.ingredient-card--candidate .ingredient-card__symbol {
  color: #825d00;
  background: var(--color-yellow-soft);
}

.ingredient-card--new .ingredient-card__symbol {
  color: #a53468;
  background: var(--color-pink-soft);
}

.ingredient-card__remove {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  color: var(--color-text-muted);
  font-size: 1.35rem;
  background: #f3f2ef;
  cursor: pointer;
}

.ingredient-card__raw {
  margin: calc(var(--space-2) * -1) 0 0;
  color: var(--color-text-muted);
  font-size: 0.83rem;
  line-height: 1.4;
}

.ingredient-card__name {
  font-size: 1.12rem;
  font-weight: 800;
}

.ingredient-card__choices {
  display: grid;
  gap: var(--space-2);
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}

.ingredient-card__choices legend {
  margin-bottom: var(--space-2);
  font-weight: 800;
}

.candidate-choice {
  display: flex;
  min-height: 46px;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-weight: 680;
  cursor: pointer;
}

.candidate-choice--selected {
  border-color: var(--color-yellow);
  background: var(--color-yellow-soft);
}

.candidate-choice input {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
  accent-color: var(--color-primary-strong);
}

.ingredient-card__measurements {
  display: grid;
  grid-template-columns: minmax(0, 0.82fr) minmax(0, 1.18fr);
  gap: var(--space-3);
}

.ingredient-card__measurements--to-taste {
  grid-template-columns: minmax(0, 1fr);
}

.ingredient-card__conversion {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.83rem;
}

.ingredient-card__conversion-picker {
  margin-top: var(--space-2);
}

.ingredient-card__error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.8rem;
  font-weight: 620;
}

.ingredient-card__error--form {
  padding: var(--space-2) var(--space-3);
  border-radius: 8px;
  background: var(--color-surface);
}

.ingredient-card__associate {
  justify-self: start;
  min-height: 40px;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-primary);
  border-radius: 8px;
  color: var(--color-primary-strong);
  font-weight: 750;
  background: var(--color-primary-soft);
  cursor: pointer;
}

@media (max-width: 359px) {
  .ingredient-card__measurements {
    grid-template-columns: 1fr;
  }
}
</style>
