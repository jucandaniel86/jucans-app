<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

import IngredientMergeSheet from '@/components/admin/IngredientMergeSheet.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect, { type SelectOption } from '@/components/ui/AppSelect.vue'
import { ApiError } from '@/services/api'
import { foodApi } from '@/services/foodApi'
import type {
  AdminIngredient,
  AdminIngredientFilters,
  AdminIngredientPayload,
  FoodUnit,
  IngredientMergeResponse,
  PaginationMeta,
  ShoppingCategory,
} from '@/types/food'

const NO_UNIT = 'none'
const NO_CATEGORY = 'none'
const ingredientFilterOptions = [
  { value: 'all', label: 'Toate' },
  { value: 'missing-unit', label: 'Fără unitate' },
  { value: 'missing-category', label: 'Fără categorie' },
  { value: 'not-shoppable', label: 'Nu se cumpără automat' },
] as const

type IngredientFilter = (typeof ingredientFilterOptions)[number]['value']

const ingredients = ref<AdminIngredient[]>([])
const categories = ref<ShoppingCategory[]>([])
const units = ref<Record<string, FoodUnit>>({})
const pagination = ref<PaginationMeta | null>(null)
const search = ref('')
const activeFilter = ref<IngredientFilter>('all')
const loading = ref(true)
const loadingMore = ref(false)
const optionsLoading = ref(true)
const saving = ref(false)
const error = ref('')
const optionsError = ref('')
const saveError = ref('')
const selectedIngredient = ref<AdminIngredient | null>(null)
const mergeSource = ref<AdminIngredient | null>(null)
const successMessage = ref('')
const openActionsId = ref<number | null>(null)
const loadMoreSentinel = ref<HTMLElement | null>(null)
const form = reactive({
  name: '',
  default_unit: NO_UNIT,
  shopping_category_id: NO_CATEGORY,
  is_shoppable: true,
})
const fieldErrors = reactive<Record<string, string>>({})
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0
let loadMoreObserver: IntersectionObserver | null = null
let successTimer: ReturnType<typeof setTimeout> | undefined

const searchTerm = computed(() => search.value.trim())
const activeFilterParams = computed<AdminIngredientFilters>(() => {
  switch (activeFilter.value) {
    case 'missing-unit':
      return { missingUnit: true }
    case 'missing-category':
      return { missingCategory: true }
    case 'not-shoppable':
      return { isShoppable: false }
    default:
      return {}
  }
})
const total = computed(() => pagination.value?.total ?? 0)
const canLoadMore = computed(() =>
  Boolean(pagination.value && pagination.value.current_page < pagination.value.last_page),
)
const resultLabel = computed(() => {
  const noun = total.value === 1 ? 'ingredient' : 'ingrediente'
  return searchTerm.value
    ? `${total.value} ${noun} pentru „${searchTerm.value}”`
    : `${total.value} ${noun}`
})
const unitOptions = computed<SelectOption[]>(() => [
  { value: NO_UNIT, label: 'Fără unitate implicită' },
  ...Object.entries(units.value).map(([value, unit]) => ({
    value,
    label: unit.label ? `${unit.name} (${unit.label})` : unit.name,
  })),
])
const categoryOptions = computed<SelectOption[]>(() => [
  { value: NO_CATEGORY, label: 'Fără categorie' },
  ...categories.value.map((category) => ({
    value: String(category.id),
    label: `${category.emoji ? `${category.emoji} ` : ''}${category.name}`,
  })),
])
const canSave = computed(
  () =>
    Boolean(selectedIngredient.value && form.name.trim()) && !saving.value && !optionsLoading.value,
)

function displayUnit(unit: string | null): string {
  if (!unit) return 'Fără unitate'
  return units.value[unit]?.label ?? units.value[unit]?.name ?? unit
}

function categoryLabel(category: ShoppingCategory | null): string {
  if (!category) return 'Fără categorie'
  return `${category.emoji ? `${category.emoji} ` : ''}${category.name}`
}

function shoppableLabel(ingredient: AdminIngredient): string {
  return ingredient.is_shoppable ? 'Se adaugă automat în shopping list' : 'Nu se adaugă automat'
}

function clearFieldErrors(): void {
  Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key])
}

function selectIngredient(ingredient: AdminIngredient): void {
  openActionsId.value = null
  selectedIngredient.value = ingredient
  form.name = ingredient.name
  form.default_unit = ingredient.default_unit ?? NO_UNIT
  form.shopping_category_id = ingredient.shopping_category?.id
    ? String(ingredient.shopping_category.id)
    : NO_CATEGORY
  form.is_shoppable = ingredient.is_shoppable
  saveError.value = ''
  clearFieldErrors()
}

function toggleActions(ingredientId: number): void {
  openActionsId.value = openActionsId.value === ingredientId ? null : ingredientId
}

function closeActions(): void {
  openActionsId.value = null
}

function closeEditor(): void {
  selectedIngredient.value = null
  saveError.value = ''
  clearFieldErrors()
}

function openMerge(ingredient: AdminIngredient): void {
  openActionsId.value = null
  selectedIngredient.value = null
  mergeSource.value = ingredient
}

function closeMerge(): void {
  mergeSource.value = null
}

function handleMerged(response: IngredientMergeResponse): void {
  const sourceIndex = ingredients.value.findIndex(
    (ingredient) => ingredient.id === response.merged_source.id,
  )
  const targetIndex = ingredients.value.findIndex(
    (ingredient) => ingredient.id === response.target.id,
  )

  if (sourceIndex !== -1) ingredients.value.splice(sourceIndex, 1)

  const refreshedTargetIndex = ingredients.value.findIndex(
    (ingredient) => ingredient.id === response.target.id,
  )
  if (targetIndex !== -1 && refreshedTargetIndex !== -1) {
    ingredients.value.splice(refreshedTargetIndex, 1, response.target)
  }

  if (pagination.value) {
    pagination.value = {
      ...pagination.value,
      total: Math.max(0, pagination.value.total - 1),
      to: pagination.value.to === null ? null : Math.max(0, pagination.value.to - 1),
    }
  }

  mergeSource.value = null
  window.clearTimeout(successTimer)
  successMessage.value = 'Ingredientele au fost combinate.'
  successTimer = window.setTimeout(() => {
    successMessage.value = ''
  }, 3500)
}

async function loadOptions(): Promise<void> {
  optionsLoading.value = true
  optionsError.value = ''

  try {
    const [configResponse, categoryResponse] = await Promise.all([
      foodApi.getConfig(),
      foodApi.listShoppingCategories(),
    ])
    units.value = configResponse.units
    categories.value = categoryResponse.data
  } catch {
    optionsError.value = 'Nu am putut încărca unitățile și categoriile.'
  } finally {
    optionsLoading.value = false
  }
}

async function loadIngredients(page = 1, append = false): Promise<void> {
  const sequence = ++requestSequence

  if (append) loadingMore.value = true
  else loading.value = true
  error.value = ''

  try {
    const response = await foodApi.listAdminIngredients({
      search: searchTerm.value || undefined,
      page,
      perPage: 20,
      ...activeFilterParams.value,
    })

    if (sequence !== requestSequence) return

    ingredients.value = append ? [...ingredients.value, ...response.data] : response.data
    pagination.value = response.meta
  } catch {
    if (sequence === requestSequence) {
      error.value = 'Nu am putut încărca ingredientele.'
      if (!append) {
        ingredients.value = []
        pagination.value = null
      }
    }
  } finally {
    if (sequence === requestSequence) {
      loading.value = false
      loadingMore.value = false

      if (append) {
        await nextTick()
        observeLoadMoreSentinel()
      }
    }
  }
}

function resetAndLoadIngredients(): void {
  pagination.value = null
  loadMoreObserver?.disconnect()
  void loadIngredients()
}

function selectFilter(filter: IngredientFilter): void {
  if (activeFilter.value === filter) return

  window.clearTimeout(debounceTimer)
  activeFilter.value = filter
  openActionsId.value = null
  resetAndLoadIngredients()
}

function loadNextPage(): void {
  if (!canLoadMore.value || loading.value || loadingMore.value) return
  void loadIngredients((pagination.value?.current_page ?? 0) + 1, true)
}

function observeLoadMoreSentinel(): void {
  if (!loadMoreObserver || !loadMoreSentinel.value) return
  loadMoreObserver.disconnect()
  loadMoreObserver.observe(loadMoreSentinel.value)
}

async function saveIngredient(): Promise<void> {
  if (!selectedIngredient.value || saving.value) return

  saving.value = true
  saveError.value = ''
  clearFieldErrors()

  const payload: AdminIngredientPayload = {
    name: form.name.trim(),
    default_unit: form.default_unit === NO_UNIT ? null : form.default_unit,
    shopping_category_id:
      form.shopping_category_id === NO_CATEGORY ? null : Number(form.shopping_category_id),
    is_shoppable: form.is_shoppable,
  }

  try {
    const response = await foodApi.updateAdminIngredient(selectedIngredient.value.id, payload)
    const nextIngredient = response.data
    const index = ingredients.value.findIndex((ingredient) => ingredient.id === nextIngredient.id)

    if (index !== -1) {
      ingredients.value.splice(index, 1, nextIngredient)
    }

    selectedIngredient.value = nextIngredient
    selectIngredient(nextIngredient)
  } catch (caughtError) {
    if (caughtError instanceof ApiError) {
      Object.entries(caughtError.errors).forEach(([field, messages]) => {
        fieldErrors[field] = messages[0] ?? ''
      })
      saveError.value = caughtError.message
    } else {
      saveError.value = 'Nu am putut salva ingredientul.'
    }
  } finally {
    saving.value = false
  }
}

watch(search, () => {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(resetAndLoadIngredients, 350)
})

watch(loadMoreSentinel, observeLoadMoreSentinel)

onMounted(() => {
  document.addEventListener('click', closeActions)

  if (typeof IntersectionObserver !== 'undefined') {
    loadMoreObserver = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) loadNextPage()
      },
      { rootMargin: '240px 0px' },
    )
  }

  void loadOptions()
  void loadIngredients()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closeActions)
  loadMoreObserver?.disconnect()
  window.clearTimeout(debounceTimer)
  window.clearTimeout(successTimer)
  requestSequence++
})
</script>

<template>
  <section class="admin-ingredients">
    <header class="admin-ingredients__header">
      <div>
        <p>Administration</p>
        <h1>Ingrediente</h1>
      </div>
    </header>

    <div v-if="optionsError" class="page-message page-message--error" role="alert">
      <span>{{ optionsError }}</span>
      <button type="button" @click="loadOptions">Încearcă din nou</button>
    </div>

    <label class="admin-ingredients__search">
      <span aria-hidden="true">⌕</span>
      <span class="visually-hidden">Caută ingrediente</span>
      <input
        v-model="search"
        type="search"
        autocomplete="off"
        placeholder="Caută un ingredient..."
      />
    </label>

    <p v-if="pagination && !loading" class="admin-ingredients__count" aria-live="polite">
      {{ resultLabel }}
    </p>

    <div class="admin-ingredients__filters" role="group" aria-label="Filtre ingrediente">
      <button
        v-for="filter in ingredientFilterOptions"
        :key="filter.value"
        type="button"
        class="filter-chip"
        :class="{ 'filter-chip--active': activeFilter === filter.value }"
        :aria-pressed="activeFilter === filter.value"
        @click="selectFilter(filter.value)"
      >
        {{ filter.label }}
      </button>
    </div>

    <div v-if="loading" class="admin-ingredients__skeletons" aria-label="Se încarcă ingredientele">
      <div v-for="index in 4" :key="index" class="ingredient-skeleton">
        <span />
        <span />
        <span />
      </div>
    </div>

    <div v-else-if="error && ingredients.length === 0" class="ingredients-state" role="alert">
      <h2>Ingredientele nu s-au încărcat.</h2>
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="loadIngredients()">Încearcă din nou</AppButton>
    </div>

    <div v-else-if="ingredients.length === 0 && searchTerm" class="ingredients-state">
      <h2>N-am găsit niciun ingredient pentru „{{ searchTerm }}”.</h2>
    </div>

    <div v-else-if="ingredients.length === 0" class="ingredients-state">
      <h2>Nu există ingrediente de administrat.</h2>
    </div>

    <template v-else>
      <div class="admin-ingredients__list">
        <article
          v-for="ingredient in ingredients"
          :key="ingredient.id"
          class="ingredient-row"
          :class="{ 'ingredient-row--selected': selectedIngredient?.id === ingredient.id }"
        >
          <button
            type="button"
            class="ingredient-row__content"
            :aria-label="`Editează ${ingredient.name}`"
            @click="selectIngredient(ingredient)"
          >
            <strong>{{ ingredient.name }}</strong>
            <span class="ingredient-row__details">
              <span>{{ categoryLabel(ingredient.shopping_category) }}</span>
              <span aria-hidden="true">·</span>
              <span>{{ displayUnit(ingredient.default_unit) }}</span>
            </span>
          </button>

          <span
            class="ingredient-row__shopping"
            :class="{ 'ingredient-row__shopping--off': !ingredient.is_shoppable }"
            :title="shoppableLabel(ingredient)"
          >
            <span aria-hidden="true" />
            {{ ingredient.is_shoppable ? 'Automat' : 'Nu automat' }}
          </span>

          <div class="ingredient-row__actions" @click.stop @keydown.esc.stop="closeActions">
            <button
              type="button"
              class="ingredient-row__actions-button"
              :aria-label="`Acțiuni pentru ${ingredient.name}`"
              aria-haspopup="menu"
              :aria-expanded="openActionsId === ingredient.id"
              @click="toggleActions(ingredient.id)"
            >
              ⋮
            </button>
            <div v-if="openActionsId === ingredient.id" class="ingredient-row__menu" role="menu">
              <button type="button" role="menuitem" @click="selectIngredient(ingredient)">
                Editează
              </button>
              <button type="button" role="menuitem" @click="openMerge(ingredient)">Combină</button>
            </div>
          </div>
        </article>
      </div>

      <p v-if="error" class="admin-ingredients__more-error" role="alert">{{ error }}</p>
      <div
        v-if="canLoadMore"
        ref="loadMoreSentinel"
        class="admin-ingredients__sentinel"
        aria-hidden="true"
      />
      <p v-if="loadingMore" class="admin-ingredients__loading-more" aria-live="polite">
        Se încarcă mai multe ingrediente…
      </p>
    </template>

    <section
      v-if="selectedIngredient"
      class="ingredient-editor"
      aria-labelledby="ingredient-editor-title"
    >
      <header class="ingredient-editor__header">
        <div>
          <p>Editare ingredient</p>
          <h2 id="ingredient-editor-title">{{ selectedIngredient.name }}</h2>
        </div>
        <button type="button" aria-label="Închide editarea" @click="closeEditor">×</button>
      </header>

      <form class="ingredient-editor__form" novalidate @submit.prevent="saveIngredient">
        <AppInput
          v-model="form.name"
          label="Nume"
          :maxlength="255"
          :error="fieldErrors.name"
          :disabled="saving"
          @update:model-value="fieldErrors.name = ''"
        />

        <AppSelect
          v-model="form.default_unit"
          label="Unitate implicită"
          :options="unitOptions"
          :error="fieldErrors.default_unit"
          :disabled="saving || optionsLoading"
          @update:model-value="fieldErrors.default_unit = ''"
        />

        <AppSelect
          v-model="form.shopping_category_id"
          label="Categorie shopping"
          :options="categoryOptions"
          :error="fieldErrors.shopping_category_id"
          :disabled="saving || optionsLoading"
          @update:model-value="fieldErrors.shopping_category_id = ''"
        />

        <label class="ingredient-editor__toggle">
          <input v-model="form.is_shoppable" type="checkbox" :disabled="saving" />
          <span>
            <strong>Adaugă automat în shopping list</strong>
            <small>Ingredientul va fi inclus când rețeta ajunge la cumpărături.</small>
          </span>
        </label>

        <section class="ingredient-editor__aliases" aria-label="Aliasuri">
          <h3>Aliasuri</h3>
          <div v-if="selectedIngredient.aliases.length" class="ingredient-editor__alias-list">
            <span v-for="alias in selectedIngredient.aliases" :key="alias.id">{{
              alias.alias
            }}</span>
          </div>
          <p v-else>Nu există aliasuri pentru acest ingredient.</p>
        </section>

        <p v-if="saveError" class="ingredient-editor__error" role="alert">{{ saveError }}</p>

        <div class="ingredient-editor__actions">
          <AppButton type="submit" :loading="saving" :disabled="!canSave">Salvează</AppButton>
          <AppButton type="button" variant="secondary" :disabled="saving" @click="closeEditor">
            Renunță
          </AppButton>
        </div>
      </form>
    </section>

    <IngredientMergeSheet
      v-if="mergeSource"
      :source="mergeSource"
      :units="units"
      @close="closeMerge"
      @merged="handleMerged"
    />

    <p v-if="successMessage" class="admin-ingredients__toast" role="status">
      {{ successMessage }}
    </p>
  </section>
</template>

<style scoped>
.admin-ingredients {
  display: grid;
  gap: var(--space-5);
}

.admin-ingredients__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.admin-ingredients__header p,
.admin-ingredients__header h1 {
  margin: 0;
}

.admin-ingredients__header p,
.ingredient-editor__header p {
  margin-bottom: var(--space-1);
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.admin-ingredients__header h1 {
  font-size: 1.75rem;
  line-height: 1.15;
  letter-spacing: 0;
}

.page-message {
  display: flex;
  padding: var(--space-3);
  border-radius: 8px;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  font-size: 0.86rem;
  font-weight: 720;
}

.page-message--error {
  color: var(--color-error);
  background: var(--color-error-soft);
}

.page-message button {
  border: 0;
  color: inherit;
  font-weight: 800;
  text-decoration: underline;
  background: transparent;
  cursor: pointer;
}

.admin-ingredients__search {
  display: grid;
  min-height: 50px;
  padding: 0 var(--space-4);
  border: 1.5px solid var(--color-border-strong);
  border-radius: 8px;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: var(--space-2);
  background: var(--color-surface);
}

.admin-ingredients__search > span:first-child {
  color: var(--color-primary-strong);
  font-size: 1.4rem;
  font-weight: 800;
}

.admin-ingredients__search input {
  min-width: 0;
  height: 48px;
  padding: 0;
  border: 0;
  color: var(--color-text);
  background: transparent;
  outline: 0;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  clip-path: inset(50%);
  white-space: nowrap;
}

.admin-ingredients__count {
  margin: calc(var(--space-2) * -1) 0 0;
  color: var(--color-text-muted);
  font-size: 0.86rem;
  font-weight: 720;
}

.admin-ingredients__filters {
  display: flex;
  margin-top: calc(var(--space-2) * -1);
  padding-bottom: 2px;
  overflow-x: auto;
  gap: var(--space-2);
  scrollbar-width: none;
}

.admin-ingredients__filters::-webkit-scrollbar {
  display: none;
}

.filter-chip {
  min-height: 34px;
  padding: 0 var(--space-3);
  flex: 0 0 auto;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 760;
  background: var(--color-surface);
  cursor: pointer;
}

.filter-chip--active {
  border-color: var(--color-primary-strong);
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
}

.admin-ingredients__list,
.admin-ingredients__skeletons {
  display: grid;
  gap: var(--space-2);
}

.ingredient-row {
  position: relative;
  display: grid;
  min-height: 68px;
  width: 100%;
  padding: var(--space-2) var(--space-2) var(--space-2) var(--space-3);
  border: 1px solid rgb(230 225 216 / 78%);
  border-radius: 8px;
  grid-template-columns: minmax(0, 1fr) auto 40px;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text);
  text-align: left;
  background: var(--color-surface);
}

.ingredient-row--selected {
  border-color: var(--color-primary);
  box-shadow: var(--shadow-focus);
}

.ingredient-row__content {
  display: grid;
  min-width: 0;
  min-height: 50px;
  padding: var(--space-1) 0;
  border: 0;
  align-content: center;
  gap: var(--space-1);
  color: inherit;
  text-align: left;
  background: transparent;
  cursor: pointer;
}

.ingredient-row__content strong {
  overflow: hidden;
  font-size: 0.96rem;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ingredient-row__details {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 650;
}

.ingredient-row__details span:not([aria-hidden]) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ingredient-row__shopping {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--color-success, #397a50);
  font-size: 0.72rem;
  font-weight: 760;
  white-space: nowrap;
}

.ingredient-row__shopping > span {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}

.ingredient-row__shopping--off {
  color: var(--color-text-muted);
}

.ingredient-row__actions {
  position: relative;
}

.ingredient-row__actions-button {
  display: grid;
  width: 40px;
  height: 40px;
  padding: 0;
  place-items: center;
  border: 0;
  border-radius: 50%;
  color: var(--color-text-muted);
  font-size: 1.4rem;
  line-height: 1;
  background: transparent;
  cursor: pointer;
}

.ingredient-row__actions-button:hover,
.ingredient-row__actions-button[aria-expanded='true'] {
  color: var(--color-text);
  background: var(--color-surface-soft);
}

.ingredient-row__menu {
  position: absolute;
  z-index: 5;
  top: calc(100% + 2px);
  right: 0;
  min-width: 128px;
  padding: var(--space-1);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
}

.ingredient-row__menu button {
  width: 100%;
  min-height: 38px;
  padding: 0 var(--space-3);
  border: 0;
  border-radius: 6px;
  color: var(--color-text);
  font-weight: 720;
  text-align: left;
  background: transparent;
  cursor: pointer;
}

.ingredient-row__menu button:hover {
  background: var(--color-surface-soft);
}

.admin-ingredients__toast {
  position: fixed;
  z-index: 95;
  right: var(--space-4);
  bottom: max(var(--space-5), env(safe-area-inset-bottom));
  left: var(--space-4);
  width: fit-content;
  max-width: calc(100% - (var(--space-4) * 2));
  margin: 0 auto;
  padding: var(--space-3) var(--space-4);
  border-radius: 8px;
  color: #fff;
  font-size: 0.84rem;
  font-weight: 780;
  background: var(--color-success);
  box-shadow: var(--shadow-md);
}

.ingredient-skeleton {
  display: grid;
  min-height: 68px;
  padding: var(--space-3);
  border-radius: 8px;
  align-content: center;
  gap: var(--space-2);
  background: var(--color-surface);
}

.ingredient-skeleton span {
  height: 12px;
  border-radius: var(--radius-pill);
  background: var(--color-border);
}

.ingredient-skeleton span:nth-child(1) {
  width: 62%;
}

.ingredient-skeleton span:nth-child(2) {
  width: 46%;
}

.ingredient-skeleton span:nth-child(3) {
  width: 76%;
}

.ingredients-state {
  display: grid;
  min-height: 260px;
  place-content: center;
  justify-items: center;
  gap: var(--space-3);
  text-align: center;
}

.ingredients-state h2,
.ingredients-state p {
  margin: 0;
}

.ingredients-state h2 {
  max-width: 340px;
  font-size: 1.2rem;
}

.ingredients-state p {
  color: var(--color-text-muted);
}

.admin-ingredients__more-error,
.ingredient-editor__error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.82rem;
  font-weight: 680;
}

.admin-ingredients__more-error {
  text-align: center;
}

.admin-ingredients__sentinel {
  height: 1px;
}

.admin-ingredients__loading-more {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
  font-weight: 680;
  text-align: center;
}

.ingredient-editor {
  position: fixed;
  z-index: 30;
  inset: auto 0 0;
  max-height: min(86vh, 720px);
  padding: var(--space-5) var(--space-5) max(var(--space-5), env(safe-area-inset-bottom));
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
}

.ingredient-editor__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  margin-bottom: var(--space-5);
}

.ingredient-editor__header h2 {
  margin: 0;
  font-size: 1.35rem;
  line-height: 1.15;
}

.ingredient-editor__header button {
  display: grid;
  width: 42px;
  height: 42px;
  flex: 0 0 auto;
  place-items: center;
  border: 0;
  border-radius: 50%;
  color: var(--color-text-muted);
  font-size: 1.7rem;
  line-height: 1;
  background: var(--color-surface-soft);
  cursor: pointer;
}

.ingredient-editor__form {
  display: grid;
  gap: var(--space-4);
}

.ingredient-editor__toggle {
  display: grid;
  padding: var(--space-3);
  border: 1.5px solid var(--color-border-strong);
  border-radius: 8px;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--space-3);
  background: var(--color-surface);
}

.ingredient-editor__toggle input {
  width: 22px;
  height: 22px;
  margin: var(--space-1) 0 0;
  accent-color: var(--color-primary-strong);
}

.ingredient-editor__toggle span {
  display: grid;
  gap: var(--space-1);
}

.ingredient-editor__toggle strong {
  font-size: 0.94rem;
}

.ingredient-editor__toggle small {
  color: var(--color-text-muted);
  font-size: 0.8rem;
  line-height: 1.35;
}

.ingredient-editor__aliases {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-4);
  border-radius: 8px;
  background: var(--color-surface-soft);
}

.ingredient-editor__aliases h3,
.ingredient-editor__aliases p {
  margin: 0;
}

.ingredient-editor__aliases h3 {
  font-size: 0.94rem;
}

.ingredient-editor__aliases p {
  color: var(--color-text-muted);
  font-size: 0.86rem;
}

.ingredient-editor__alias-list {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.ingredient-editor__alias-list span {
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-pill);
  color: var(--color-text);
  font-size: 0.82rem;
  font-weight: 720;
  background: var(--color-primary-soft);
}

.ingredient-editor__actions {
  display: grid;
  gap: var(--space-3);
}

@media (min-width: 720px) {
  .ingredient-editor {
    right: max(var(--space-6), calc((100vw - 640px) / 2));
    bottom: var(--space-6);
    left: auto;
    width: min(460px, calc(100vw - var(--space-8)));
    border-radius: var(--radius-lg);
  }
}
</style>
