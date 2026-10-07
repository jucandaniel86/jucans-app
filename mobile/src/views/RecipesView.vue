<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import RecipeCard from '@/components/recipe/RecipeCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { foodApi } from '@/services/foodApi'
import type { FoodTag, PaginationMeta, RecipeSummary } from '@/types/food'

const route = useRoute()
const router = useRouter()
const tags = ref<FoodTag[]>([])
const tagsError = ref(false)
const recipes = ref<RecipeSummary[]>([])
const pagination = ref<PaginationMeta | null>(null)
const search = ref(typeof route.query.q === 'string' ? route.query.q : '')
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0
let syncingRoute = false

const searchTerm = computed(() => search.value.trim())
const selectedTags = computed(() => [
  ...new Set(
    (typeof route.query.tags === 'string' ? route.query.tags : '')
      .split(',')
      .map(Number)
      .filter((id) => Number.isInteger(id) && id > 0),
  ),
])
const hasFilters = computed(() => Boolean(searchTerm.value || selectedTags.value.length))
const total = computed(() => pagination.value?.total ?? 0)
const canLoadMore = computed(() =>
  Boolean(pagination.value && pagination.value.current_page < pagination.value.last_page),
)
const resultLabel = computed(() => {
  const noun = total.value === 1 ? 'rețetă' : 'rețete'
  return searchTerm.value
    ? `${total.value} ${noun} pentru „${searchTerm.value}”`
    : `${total.value} ${noun}`
})

async function loadRecipes(page = 1, append = false): Promise<void> {
  const sequence = ++requestSequence

  if (append) loadingMore.value = true
  else loading.value = true
  error.value = ''

  try {
    const response = await foodApi.listRecipes({
      search: searchTerm.value || undefined,
      tags: selectedTags.value,
      page,
    })

    if (sequence !== requestSequence) return

    recipes.value = append ? [...recipes.value, ...response.data] : response.data
    pagination.value = response.meta
  } catch {
    if (sequence === requestSequence) {
      error.value = 'Nu am putut încărca rețetele.'
      if (!append) {
        recipes.value = []
        pagination.value = null
      }
    }
  } finally {
    if (sequence === requestSequence) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function updateFilters(query = searchTerm.value, ids = selectedTags.value): void {
  if (query === (route.query.q ?? '') && ids.join(',') === (route.query.tags ?? '')) {
    void loadRecipes()
    return
  }
  void router.replace({
    query: { ...route.query, q: query || undefined, tags: ids.length ? ids.join(',') : undefined },
  })
}

function toggleTag(id: number): void {
  window.clearTimeout(debounceTimer)
  updateFilters(
    searchTerm.value,
    selectedTags.value.includes(id)
      ? selectedTags.value.filter((selected) => selected !== id)
      : [...selectedTags.value, id],
  )
}

function resetFilters(): void {
  window.clearTimeout(debounceTimer)
  search.value = ''
  updateFilters('', [])
}

watch(
  search,
  () => {
    if (syncingRoute) return
    requestSequence++
    loading.value = true
    window.clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(() => updateFilters(), 350)
  },
  { flush: 'sync' },
)

watch(
  () => [route.query.q, route.query.tags],
  () => {
    window.clearTimeout(debounceTimer)
    syncingRoute = true
    search.value = typeof route.query.q === 'string' ? route.query.q : ''
    syncingRoute = false
    void loadRecipes()
  },
  { immediate: true },
)

async function loadTags(): Promise<void> {
  tagsError.value = false
  try {
    tags.value = (await foodApi.listTags()).data
  } catch {
    tagsError.value = true
  }
}
onMounted(loadTags)
onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer)
  requestSequence++
})
</script>

<template>
  <section class="recipes-page">
    <header class="recipes-page__header">
      <div>
        <p>Food</p>
        <h1>Rețetele noastre <span aria-hidden="true">🍳</span></h1>
      </div>
      <RouterLink
        class="recipes-page__add"
        :to="{ name: 'recipe-create' }"
        aria-label="Adaugă rețetă"
      >
        <span aria-hidden="true">+</span>
      </RouterLink>
    </header>

    <label class="recipes-page__search">
      <span aria-hidden="true">⌕</span>
      <span class="visually-hidden">Caută rețete</span>
      <input
        v-model="search"
        type="search"
        autocomplete="off"
        placeholder="Caută o rețetă sau un ingredient..."
      />
    </label>

    <div class="recipes-page__filters" aria-label="Filtrează după taguri">
      <button
        type="button"
        :aria-pressed="selectedTags.length === 0"
        @click="updateFilters(searchTerm, [])"
      >
        Toate
      </button>
      <button
        v-for="tag in tags"
        :key="tag.id"
        type="button"
        :aria-pressed="selectedTags.includes(tag.id)"
        @click="toggleTag(tag.id)"
      >
        {{ tag.emoji }} {{ tag.name }}
      </button>
    </div>
    <button v-if="tagsError" type="button" class="recipes-page__reset" @click="loadTags">
      Reîncarcă tagurile
    </button>

    <p v-if="pagination && !loading" class="recipes-page__count" aria-live="polite">
      {{ resultLabel }}
    </p>

    <div v-if="loading" class="recipes-page__skeletons" aria-label="Se încarcă rețetele">
      <div v-for="index in 3" :key="index" class="recipe-skeleton">
        <span />
        <div><span /><span /><span /></div>
      </div>
    </div>

    <div v-else-if="error && recipes.length === 0" class="recipes-state" role="alert">
      <h2>Rețetele au rămas în bucătărie.</h2>
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="loadRecipes()">Încearcă din nou</AppButton>
    </div>

    <div v-else-if="recipes.length === 0 && hasFilters" class="recipes-state">
      <h2>Nicio rețetă nu corespunde filtrelor.</h2>
      <button type="button" class="recipes-page__reset" @click="resetFilters">
        Resetează filtrele
      </button>
    </div>

    <div v-else-if="recipes.length === 0" class="recipes-state">
      <h2>Încă nu avem rețete <span aria-hidden="true">🍳</span></h2>
      <p>Adaugă prima rețetă a familiei.</p>
      <RouterLink class="recipes-state__add" :to="{ name: 'recipe-create' }">
        + Adaugă rețetă
      </RouterLink>
    </div>

    <template v-else>
      <div class="recipes-page__list">
        <RecipeCard v-for="recipe in recipes" :key="recipe.id" :recipe="recipe" />
      </div>

      <p v-if="error" class="recipes-page__more-error" role="alert">{{ error }}</p>
      <AppButton
        v-if="canLoadMore"
        block
        variant="secondary"
        :loading="loadingMore"
        @click="loadRecipes((pagination?.current_page ?? 0) + 1, true)"
      >
        Încarcă mai multe
      </AppButton>
    </template>
  </section>
</template>

<style scoped>
.recipes-page__filters {
  display: flex;
  gap: var(--space-2);
  overflow-x: auto;
  margin-top: calc(var(--space-3) * -1);
  padding: 2px 0 4px;
}
.recipes-page__filters button {
  flex: 0 0 auto;
  min-height: 36px;
  padding: 0 12px;
  border: 1px solid var(--color-border-strong);
  border-radius: 18px;
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
}
.recipes-page__filters button:nth-child(3n) {
  background: #fce5ee;
}
.recipes-page__filters button:nth-child(3n + 1) {
  background: #e3f4ee;
}
.recipes-page__filters button:nth-child(3n + 2) {
  background: #fff0ce;
}
.recipes-page__filters button[aria-pressed='true'] {
  background: var(--color-primary-strong);
  border-color: var(--color-primary-strong);
  color: #fff;
}
.recipes-page__reset {
  border: 0;
  padding: 8px 0;
  color: var(--color-primary-strong);
  background: transparent;
  font-weight: 700;
  cursor: pointer;
}
.recipes-page {
  display: grid;
  gap: var(--space-5);
}

.recipes-page__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}

.recipes-page__header p,
.recipes-page__header h1 {
  margin: 0;
}

.recipes-page__header p {
  margin-bottom: var(--space-1);
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.recipes-page__header h1 {
  font-size: 1.75rem;
  line-height: 1.15;
  letter-spacing: 0;
}

.recipes-page__add {
  display: grid;
  width: 48px;
  height: 48px;
  flex: 0 0 auto;
  place-items: center;
  border-radius: 50%;
  color: #fff;
  font-size: 1.8rem;
  line-height: 1;
  text-decoration: none;
  background: var(--color-pink);
  box-shadow: var(--shadow-sm);
}

.recipes-page__search {
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

.recipes-page__search > span:first-child {
  color: var(--color-primary-strong);
  font-size: 1.4rem;
  font-weight: 800;
}

.recipes-page__search input {
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

.recipes-page__count {
  margin: calc(var(--space-2) * -1) 0 0;
  color: var(--color-text-muted);
  font-size: 0.86rem;
  font-weight: 720;
}

.recipes-page__list,
.recipes-page__skeletons {
  display: grid;
  gap: var(--space-3);
}

.recipe-skeleton {
  display: grid;
  padding: var(--space-3);
  border-radius: 8px;
  grid-template-columns: 112px minmax(0, 1fr);
  gap: var(--space-4);
  background: var(--color-surface);
}

.recipe-skeleton > span {
  aspect-ratio: 1;
  border-radius: 7px;
  background: var(--color-border);
}

.recipe-skeleton div {
  display: grid;
  align-content: center;
  gap: var(--space-3);
}

.recipe-skeleton div span {
  height: 12px;
  border-radius: var(--radius-pill);
  background: var(--color-border);
}

.recipe-skeleton div span:nth-child(2) {
  width: 70%;
}

.recipe-skeleton div span:nth-child(3) {
  width: 45%;
}

.recipes-state {
  display: grid;
  min-height: 260px;
  place-content: center;
  justify-items: center;
  gap: var(--space-3);
  text-align: center;
}

.recipes-state h2,
.recipes-state p {
  margin: 0;
}

.recipes-state h2 {
  max-width: 340px;
  font-size: 1.2rem;
}

.recipes-state p {
  color: var(--color-text-muted);
}

.recipes-state__add {
  min-height: 46px;
  padding: 0 var(--space-4);
  border-radius: 8px;
  color: #fff;
  font-weight: 780;
  line-height: 46px;
  text-decoration: none;
  background: var(--color-primary);
}

.recipes-page__more-error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.82rem;
  text-align: center;
}
</style>
