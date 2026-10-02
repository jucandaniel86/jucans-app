<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/services/api'
import { foodApi } from '@/services/foodApi'
import type {
  AdminIngredient,
  FoodUnit,
  IngredientMergePreview,
  IngredientMergeResponse,
} from '@/types/food'

const props = defineProps<{
  source: AdminIngredient
  units: Record<string, FoodUnit>
}>()

const emit = defineEmits<{
  close: []
  merged: [response: IngredientMergeResponse]
}>()

const query = ref('')
const results = ref<AdminIngredient[]>([])
const selectedTarget = ref<AdminIngredient | null>(null)
const preview = ref<IngredientMergePreview | null>(null)
const searching = ref(false)
const previewing = ref(false)
const merging = ref(false)
const hasSearched = ref(false)
const searchError = ref('')
const previewError = ref('')
const mergeError = ref('')
const previewNeedsRefresh = ref(false)
const searchInput = ref<HTMLInputElement | null>(null)
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let searchSequence = 0
let previewSequence = 0

const canMerge = computed(
  () => Boolean(preview.value?.can_merge) && !previewNeedsRefresh.value && !merging.value,
)

function displayUnit(unit: string | null): string {
  if (!unit) return 'Fără unitate'
  return props.units[unit]?.label || props.units[unit]?.name || unit
}

function ingredientDetails(ingredient: AdminIngredient): string {
  const category = ingredient.shopping_category
    ? `${ingredient.shopping_category.emoji ? `${ingredient.shopping_category.emoji} ` : ''}${ingredient.shopping_category.name}`
    : 'Fără categorie'

  return `${displayUnit(ingredient.default_unit)} · ${category}`
}

function close(): void {
  if (!merging.value) emit('close')
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') close()
}

async function searchTargets(): Promise<void> {
  const searchTerm = query.value.trim()
  const sequence = ++searchSequence

  if (!searchTerm) {
    results.value = []
    hasSearched.value = false
    searchError.value = ''
    return
  }

  searching.value = true
  searchError.value = ''

  try {
    const response = await foodApi.listAdminIngredients({
      search: searchTerm,
      page: 1,
      perPage: 20,
    })

    if (sequence === searchSequence) {
      results.value = response.data.filter((ingredient) => ingredient.id !== props.source.id)
      hasSearched.value = true
    }
  } catch {
    if (sequence === searchSequence) {
      results.value = []
      hasSearched.value = true
      searchError.value = 'Nu am putut căuta ingredientele.'
    }
  } finally {
    if (sequence === searchSequence) searching.value = false
  }
}

async function selectTarget(target: AdminIngredient): Promise<void> {
  selectedTarget.value = target
  results.value = []
  mergeError.value = ''
  previewNeedsRefresh.value = false
  await loadPreview()
}

async function loadPreview(): Promise<void> {
  if (!selectedTarget.value || previewing.value || merging.value) return

  const sequence = ++previewSequence
  previewing.value = true
  previewError.value = ''
  mergeError.value = ''
  previewNeedsRefresh.value = false
  preview.value = null

  try {
    const response = await foodApi.previewIngredientMerge(props.source.id, selectedTarget.value.id)

    if (sequence === previewSequence) preview.value = response
  } catch (error) {
    if (sequence === previewSequence) {
      previewError.value =
        error instanceof ApiError ? error.message : 'Nu am putut pregăti combinarea.'
    }
  } finally {
    if (sequence === previewSequence) previewing.value = false
  }
}

async function executeMerge(): Promise<void> {
  if (!selectedTarget.value || !canMerge.value || merging.value) return

  merging.value = true
  mergeError.value = ''

  try {
    const response = await foodApi.mergeIngredients(props.source.id, selectedTarget.value.id)
    emit('merged', response)
  } catch (error) {
    mergeError.value =
      error instanceof ApiError ? error.message : 'Nu am putut combina ingredientele.'
    previewNeedsRefresh.value = true
  } finally {
    merging.value = false
  }
}

watch(query, () => {
  window.clearTimeout(debounceTimer)
  searchSequence++
  previewSequence++
  selectedTarget.value = null
  preview.value = null
  previewError.value = ''
  mergeError.value = ''
  previewNeedsRefresh.value = false
  debounceTimer = window.setTimeout(searchTargets, 250)
})

onMounted(async () => {
  document.addEventListener('keydown', handleKeydown)
  await nextTick()
  searchInput.value?.focus()
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleKeydown)
  window.clearTimeout(debounceTimer)
  searchSequence++
  previewSequence++
})
</script>

<template>
  <Teleport to="body">
    <div class="merge-sheet" role="presentation" @click.self="close">
      <section
        class="merge-sheet__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="merge-sheet-title"
      >
        <header class="merge-sheet__header">
          <div>
            <p>Administrare</p>
            <h2 id="merge-sheet-title">Combină ingredient</h2>
          </div>
          <button type="button" aria-label="Închide combinarea" :disabled="merging" @click="close">
            ×
          </button>
        </header>

        <div class="merge-sheet__source">
          <span>Sursă</span>
          <strong>{{ source.name }}</strong>
          <small>{{ ingredientDetails(source) }}</small>
        </div>

        <div class="merge-sheet__direction" aria-hidden="true">↓</div>

        <section class="merge-sheet__target-search">
          <label for="merge-target-search">Combină cu</label>
          <input
            id="merge-target-search"
            ref="searchInput"
            v-model="query"
            type="search"
            autocomplete="off"
            placeholder="Caută ingredientul țintă..."
            :disabled="merging"
          />

          <p v-if="searching" class="merge-sheet__status">Se caută…</p>
          <p v-else-if="searchError" class="merge-sheet__error" role="alert">
            {{ searchError }}
          </p>
          <p v-else-if="hasSearched && results.length === 0" class="merge-sheet__status">
            Niciun alt ingredient găsit.
          </p>

          <div v-else-if="results.length" class="merge-sheet__results" role="listbox">
            <button
              v-for="ingredient in results"
              :key="ingredient.id"
              type="button"
              role="option"
              :disabled="merging"
              @click="selectTarget(ingredient)"
            >
              <strong>{{ ingredient.name }}</strong>
              <small>{{ ingredientDetails(ingredient) }}</small>
            </button>
          </div>
        </section>

        <div v-if="selectedTarget" class="merge-sheet__target">
          <span>Țintă</span>
          <strong>{{ selectedTarget.name }}</strong>
          <small>{{ ingredientDetails(selectedTarget) }}</small>
        </div>

        <p v-if="previewing" class="merge-sheet__status" aria-live="polite">
          Se verifică impactul…
        </p>
        <div v-else-if="previewError" class="merge-sheet__retry" role="alert">
          <p>{{ previewError }}</p>
          <button type="button" @click="loadPreview">Încearcă din nou</button>
        </div>

        <template v-if="preview">
          <section class="merge-preview" aria-label="Previzualizare combinare">
            <div class="merge-preview__direction">
              <strong>{{ preview.source.name }}</strong>
              <span aria-hidden="true">↓</span>
              <strong>{{ preview.target.name }}</strong>
            </div>

            <h3>Impact</h3>
            <dl>
              <div>
                <dt>Rețete afectate</dt>
                <dd>{{ preview.impact.recipe_count }}</dd>
              </div>
              <div>
                <dt>Referințe în rețete</dt>
                <dd>{{ preview.impact.recipe_ingredient_count }}</dd>
              </div>
              <div>
                <dt>Aliasuri</dt>
                <dd>{{ preview.impact.alias_count }}</dd>
              </div>
              <div>
                <dt>Necesită verificare</dt>
                <dd>{{ preview.impact.needs_review_count }}</dd>
              </div>
            </dl>

            <div v-if="preview.unit_mismatch" class="merge-preview__warning">
              <strong>
                Unitățile diferă: {{ displayUnit(preview.source.default_unit) }} →
                {{ displayUnit(preview.target.default_unit) }}
              </strong>
              <p>
                Cantitățile nu vor fi convertite automat. Rețetele afectate vor fi marcate pentru
                verificare.
              </p>
            </div>

            <div v-if="!preview.can_merge" class="merge-preview__blocked" role="alert">
              <strong>Combinarea nu poate fi făcută încă.</strong>
              <template v-if="preview.conflicts.length">
                <p>Următoarele rețete conțin deja ambele ingrediente:</p>
                <ul>
                  <li v-for="conflict in preview.conflicts" :key="conflict.recipe_id">
                    {{ conflict.recipe_name }}
                  </li>
                </ul>
              </template>
              <p v-if="preview.alias_name_collision.canonical_ingredient">
                Numele sursei coincide cu ingredientul canonic
                {{ preview.alias_name_collision.canonical_ingredient.name }}.
              </p>
              <p v-if="preview.alias_name_collision.existing_alias">
                Numele sursei există deja ca alias pentru
                {{ preview.alias_name_collision.existing_alias.ingredient_name }}.
              </p>
            </div>

            <ul v-else class="merge-preview__confirmation">
              <li>{{ preview.source.name }} nu va mai fi ingredient canonic.</li>
              <li>Referințele din rețete vor fi mutate la {{ preview.target.name }}.</li>
              <li>Numele sursei va fi păstrat ca alias.</li>
              <li>Cantitățile și unitățile salvate nu vor fi convertite.</li>
            </ul>
          </section>

          <div v-if="mergeError" class="merge-sheet__retry" role="alert">
            <p>{{ mergeError }}</p>
            <p v-if="previewNeedsRefresh">
              Datele s-ar putea să se fi schimbat după previzualizare.
            </p>
            <button type="button" :disabled="merging" @click="loadPreview">
              Reîncarcă previzualizarea
            </button>
          </div>

          <AppButton
            class="merge-sheet__confirm"
            block
            :loading="merging"
            :disabled="!canMerge"
            @click="executeMerge"
          >
            Combină ingredientele
          </AppButton>
        </template>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.merge-sheet {
  position: fixed;
  z-index: 90;
  inset: 0;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: var(--color-overlay);
}

.merge-sheet__panel {
  display: grid;
  width: min(100%, 520px);
  max-height: 92dvh;
  padding: var(--space-4) var(--space-4) max(var(--space-4), env(safe-area-inset-bottom));
  overflow-y: auto;
  border-radius: 8px 8px 0 0;
  gap: var(--space-3);
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
}

.merge-sheet__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
}

.merge-sheet__header p,
.merge-sheet__header h2,
.merge-sheet__retry p {
  margin: 0;
}

.merge-sheet__header p,
.merge-sheet__source > span,
.merge-sheet__target > span {
  color: var(--color-primary-strong);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.merge-sheet__header h2 {
  margin-top: 2px;
  font-size: 1.2rem;
}

.merge-sheet__header button {
  display: grid;
  width: 40px;
  height: 40px;
  flex: 0 0 auto;
  padding: 0;
  place-items: center;
  border: 0;
  border-radius: 50%;
  color: var(--color-text-muted);
  font-size: 1.6rem;
  background: var(--color-surface-soft);
  cursor: pointer;
}

.merge-sheet__source,
.merge-sheet__target {
  display: grid;
  padding: var(--space-3);
  border-left: 3px solid var(--color-primary);
  gap: 2px;
  background: var(--color-surface-soft);
}

.merge-sheet__source strong,
.merge-sheet__target strong {
  font-size: 0.96rem;
}

.merge-sheet__source small,
.merge-sheet__target small,
.merge-sheet__results small {
  color: var(--color-text-muted);
  font-size: 0.76rem;
}

.merge-sheet__direction {
  height: 16px;
  color: var(--color-primary-strong);
  font-size: 1.25rem;
  font-weight: 850;
  line-height: 16px;
  text-align: center;
}

.merge-sheet__target-search {
  position: relative;
  display: grid;
  gap: var(--space-2);
}

.merge-sheet__target-search label {
  font-size: 0.83rem;
  font-weight: 800;
}

.merge-sheet__target-search input {
  min-width: 0;
  min-height: 46px;
  padding: 0 var(--space-3);
  border: 1.5px solid var(--color-border-strong);
  border-radius: 8px;
  color: var(--color-text);
  background: var(--color-surface);
}

.merge-sheet__results {
  display: grid;
  max-height: 190px;
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.merge-sheet__results button {
  display: grid;
  min-height: 52px;
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-bottom: 1px solid var(--color-border);
  gap: 2px;
  color: var(--color-text);
  text-align: left;
  background: var(--color-surface);
  cursor: pointer;
}

.merge-sheet__results button:last-child {
  border-bottom: 0;
}

.merge-sheet__status,
.merge-sheet__error {
  margin: 0;
  font-size: 0.8rem;
}

.merge-sheet__status {
  color: var(--color-text-muted);
}

.merge-sheet__error,
.merge-sheet__retry {
  color: var(--color-error);
}

.merge-sheet__retry {
  display: grid;
  padding: var(--space-3);
  border-radius: 8px;
  gap: var(--space-2);
  font-size: 0.8rem;
  font-weight: 680;
  background: var(--color-error-soft);
}

.merge-sheet__retry button {
  width: fit-content;
  padding: 0;
  border: 0;
  color: inherit;
  font-weight: 800;
  text-decoration: underline;
  background: transparent;
  cursor: pointer;
}

.merge-preview {
  display: grid;
  gap: var(--space-3);
}

.merge-preview__direction {
  display: grid;
  justify-items: center;
  gap: 1px;
  font-size: 0.9rem;
}

.merge-preview__direction span {
  color: var(--color-primary-strong);
  font-size: 1.15rem;
  line-height: 1;
}

.merge-preview h3 {
  margin: 0;
  font-size: 0.88rem;
}

.merge-preview dl {
  display: grid;
  margin: 0;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.merge-preview dl div {
  display: flex;
  min-width: 0;
  padding: var(--space-2) var(--space-3);
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}

.merge-preview dt {
  color: var(--color-text-muted);
  font-size: 0.72rem;
}

.merge-preview dd {
  margin: 0;
  font-size: 0.88rem;
  font-weight: 850;
}

.merge-preview__warning,
.merge-preview__blocked {
  padding: var(--space-3);
  border-radius: 8px;
  font-size: 0.8rem;
}

.merge-preview__warning {
  color: #72510c;
  background: #fff2c7;
}

.merge-preview__blocked {
  color: var(--color-error);
  background: var(--color-error-soft);
}

.merge-preview__warning p,
.merge-preview__blocked p,
.merge-preview__blocked ul {
  margin: var(--space-1) 0 0;
}

.merge-preview__blocked ul,
.merge-preview__confirmation {
  padding-left: 1.15rem;
}

.merge-preview__confirmation {
  display: grid;
  margin: 0;
  gap: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.merge-sheet__confirm {
  min-height: 48px;
  background: var(--color-error);
  box-shadow: none;
}

@media (min-width: 640px) {
  .merge-sheet {
    padding: var(--space-5);
    align-items: center;
  }

  .merge-sheet__panel {
    border-radius: 8px;
  }
}
</style>
