<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

import { foodApi } from '@/services/foodApi'
import type { ExistingIngredient } from '@/types/food'

const props = defineProps<{
  initialQuery: string
  saving: boolean
  error: string
}>()

defineEmits<{
  cancel: []
  select: [ingredient: ExistingIngredient]
}>()

const query = ref(props.initialQuery)
const results = ref<ExistingIngredient[]>([])
const searching = ref(false)
const searchError = ref('')
const hasSearched = ref(false)
const input = ref<HTMLInputElement | null>(null)
const inputId = useId()
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0

async function search(): Promise<void> {
  const searchTerm = query.value.trim()
  const sequence = ++requestSequence

  if (!searchTerm) {
    results.value = []
    hasSearched.value = false
    searchError.value = ''
    return
  }

  searching.value = true
  searchError.value = ''

  try {
    const response = await foodApi.searchIngredients(searchTerm)

    if (sequence === requestSequence) {
      results.value = response.data
      hasSearched.value = true
    }
  } catch {
    if (sequence === requestSequence) {
      results.value = []
      hasSearched.value = true
      searchError.value = 'Nu am putut căuta ingredientele.'
    }
  } finally {
    if (sequence === requestSequence) searching.value = false
  }
}

watch(query, () => {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(search, 250)
})

onMounted(async () => {
  await nextTick()
  input.value?.focus()
  await search()
})

onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer)
  requestSequence++
})
</script>

<template>
  <div class="association-selector">
    <div class="association-selector__header">
      <label :for="inputId">Caută ingredientul existent</label>
      <button type="button" :disabled="saving" @click="$emit('cancel')">Închide</button>
    </div>
    <input
      :id="inputId"
      ref="input"
      v-model="query"
      type="search"
      autocomplete="off"
      placeholder="Ex. Sare"
      :disabled="saving"
    />

    <p v-if="searching" class="association-selector__status">Se caută…</p>
    <p v-else-if="searchError" class="association-selector__error" role="alert">
      {{ searchError }}
    </p>
    <p v-else-if="hasSearched && results.length === 0" class="association-selector__status">
      Niciun ingredient găsit.
    </p>

    <div v-else-if="results.length" class="association-selector__results">
      <button
        v-for="ingredient in results"
        :key="ingredient.id"
        type="button"
        :disabled="saving"
        @click="$emit('select', ingredient)"
      >
        <span>{{ ingredient.name }}</span>
        <span aria-hidden="true">›</span>
      </button>
    </div>

    <p v-if="saving" class="association-selector__status" aria-live="polite">Se asociază…</p>
    <p v-else-if="error" class="association-selector__error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.association-selector {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface-soft);
}

.association-selector__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}

.association-selector__header label {
  font-size: 0.82rem;
  font-weight: 800;
}

.association-selector__header button {
  min-height: 36px;
  padding: 0 var(--space-2);
  border: 0;
  color: var(--color-text-muted);
  font-weight: 700;
  background: transparent;
  cursor: pointer;
}

.association-selector > input {
  min-width: 0;
  min-height: 44px;
  padding: 0 var(--space-3);
  border: 1.5px solid var(--color-border-strong);
  border-radius: 8px;
  color: var(--color-text);
  background: var(--color-surface);
}

.association-selector__results {
  display: grid;
  max-height: 210px;
  overflow-y: auto;
  border-top: 1px solid var(--color-border);
}

.association-selector__results button {
  display: flex;
  min-height: 44px;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--space-2);
  border: 0;
  border-bottom: 1px solid var(--color-border);
  color: var(--color-text);
  font-weight: 720;
  text-align: left;
  background: transparent;
  cursor: pointer;
}

.association-selector__status,
.association-selector__error {
  margin: 0;
  font-size: 0.8rem;
}

.association-selector__status {
  color: var(--color-text-muted);
}

.association-selector__error {
  color: var(--color-error);
  font-weight: 650;
}
</style>
