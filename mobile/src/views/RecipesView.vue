<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import RecipeCard from '@/components/recipe/RecipeCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { authorsApi } from '@/services/authorsApi'
import type { Author } from '@/types/authors'
import { dietsApi } from '@/services/dietsApi'
import type { DailyStructureConfig } from '@/types/diets'
import { foodApi } from '@/services/foodApi'
import type { FoodTag, PaginationMeta, RecipeSummary } from '@/types/food'

const route = useRoute()
const router = useRouter()
const tags = ref<FoodTag[]>([])
const authors = ref<Author[]>([])
const tagsError = ref(false)
const authorsError = ref(false)
const advancedOpen = ref(false)
const dailyStructures = ref<DailyStructureConfig[]>([])
const structuresError = ref(false)
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
const selectedStructure = computed(() => {
  const id = Number(route.query.daily_structure)
  return Number.isInteger(id) && id > 0 ? id : undefined
})
const selectedAuthor = computed(() => {
  const id = Number(route.query.author_id)
  return Number.isInteger(id) && id > 0 ? id : undefined
})
const authorName = computed(() =>
  authors.value.find((author) => author.id === selectedAuthor.value)?.name ??
  recipes.value.find((recipe) => recipe.author?.id === selectedAuthor.value)?.author?.name ??
  `#${selectedAuthor.value}`,
)
const hasFilters = computed(() =>
  Boolean(searchTerm.value || selectedTags.value.length || selectedStructure.value || selectedAuthor.value),
)
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
      dailyStructure: selectedStructure.value,
      authorId: selectedAuthor.value,
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

function updateFilters(
  query = searchTerm.value,
  ids = selectedTags.value,
  structure: number | null | undefined = selectedStructure.value,
  author: number | null | undefined = selectedAuthor.value,
): void {
  if (
    query === (route.query.q ?? '') &&
    ids.join(',') === (route.query.tags ?? '') &&
    String(structure ?? '') === (route.query.daily_structure ?? '') &&
    String(author ?? '') === (route.query.author_id ?? '')
  ) {
    void loadRecipes()
    return
  }
  void router.replace({
    query: {
      ...route.query,
      q: query || undefined,
      tags: ids.length ? ids.join(',') : undefined,
      daily_structure: structure ? String(structure) : undefined,
      author_id: author ? String(author) : undefined,
    },
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
  updateFilters('', [], null, null)
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
  () => [route.query.q, route.query.tags, route.query.daily_structure, route.query.author_id],
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
async function loadStructures(): Promise<void> {
  structuresError.value = false
  try {
    dailyStructures.value = (await dietsApi.getDailyStructureConfig()).data
  } catch {
    structuresError.value = true
  }
}

function filterStructure(id: number | null): void {
  window.clearTimeout(debounceTimer)
  updateFilters(searchTerm.value, selectedTags.value, id)
}

function filterAuthor(id: number | null): void {
  window.clearTimeout(debounceTimer)
  updateFilters(searchTerm.value, selectedTags.value, selectedStructure.value, id)
}

async function loadAuthors(): Promise<void> {
  authorsError.value = false
  try {
    authors.value = (await authorsApi.list()).data
  } catch {
    authorsError.value = true
  }
}

onMounted(() => {
  void loadAuthors()
  void loadTags()
  void loadStructures()
})
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
        <h1>Rețetele noastre</h1>
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

    <div
      v-if="!advancedOpen && (selectedTags.length || selectedStructure || selectedAuthor)"
      class="recipes-page__filters recipes-page__active"
      aria-label="Filtre active"
    >
      <button
        v-for="id in selectedTags"
        :key="`tag-${id}`"
        type="button"
        :aria-label="`Elimină filtrul ${tags.find((tag) => tag.id === id)?.name ?? `Tag #${id}`}`"
        @click="toggleTag(id)"
      >
        {{ tags.find((tag) => tag.id === id)?.name ?? `Tag #${id}` }}
        <span aria-hidden="true">×</span>
      </button>
      <button
        v-if="selectedStructure"
        type="button"
        aria-label="Elimină filtrul de tip masă"
        @click="filterStructure(null)"
      >
        {{
          dailyStructures.find((item) => item.id === selectedStructure)?.name ??
          `Tip masă #${selectedStructure}`
        }}
        <span aria-hidden="true">×</span>
      </button>
      <button
        v-if="selectedAuthor"
        type="button"
        :aria-label="`Elimină filtrul ${authorName}`"
        @click="filterAuthor(null)"
      >
        {{ authorName }} <span aria-hidden="true">×</span>
      </button>
    </div>

    <div class="recipes-page__toolbar">
      <p v-if="pagination && !loading" class="recipes-page__count" aria-live="polite">
        {{ resultLabel }}
      </p>

      <button
        type="button"
        class="recipes-page__advanced-toggle"
        :aria-expanded="advancedOpen"
        aria-controls="recipe-advanced-filters"
        @click="advancedOpen = !advancedOpen"
      >
        Filtrare avansată <span aria-hidden="true">{{ advancedOpen ? '⌃' : '⌄' }}</span>
      </button>
    </div>
    <div v-show="advancedOpen" id="recipe-advanced-filters" class="recipes-page__advanced">
      <h2>Tags</h2>
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
          {{ tag.name }}
        </button>
      </div>
      <button v-if="tagsError" type="button" class="recipes-page__reset" @click="loadTags">
        Reîncarcă tagurile
      </button>

      <h2>Tip masă</h2>
      <div class="recipes-page__filters" aria-label="Filtrează după tipul de masă">
        <button type="button" :aria-pressed="!selectedStructure" @click="filterStructure(null)">
          Toate mesele
        </button>
        <button
          v-for="structure in dailyStructures"
          :key="structure.id"
          type="button"
          :aria-pressed="selectedStructure === structure.id"
          @click="filterStructure(selectedStructure === structure.id ? null : structure.id)"
        >
          {{ structure.name }}
        </button>
      </div>
      <button
        v-if="structuresError"
        type="button"
        class="recipes-page__reset"
        @click="loadStructures"
      >
        Reîncarcă tipurile de masă
      </button>

      <h2>Autor</h2>
      <div class="recipes-page__filters" aria-label="Filtrează după autor">
        <button type="button" :aria-pressed="!selectedAuthor" @click="filterAuthor(null)">
          Toți autorii
        </button>
        <button
          v-for="author in authors"
          :key="author.id"
          type="button"
          :aria-pressed="selectedAuthor === author.id"
          @click="filterAuthor(selectedAuthor === author.id ? null : author.id)"
        >
          {{ author.name }}
        </button>
      </div>
      <button v-if="authorsError" type="button" class="recipes-page__reset" @click="loadAuthors">
        Reîncarcă autorii
      </button>
    </div>

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
        <RecipeCard v-for="recipe in recipes" :key="recipe.id" :recipe="recipe" compact />
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
.recipes-page__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.recipes-page__advanced-toggle {
  flex-shrink: 0;
  margin-left: auto;
  min-height: 36px;
  padding: 0 var(--space-3);
  border: 1px solid #ddd;
  border-radius: 4px;
  color: #222;
  background: #fff;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}

.recipes-page__advanced {
  display: grid;
  min-width: 0;
  gap: 4px;
}

.recipes-page__advanced h2 {
  margin: 4px 0 0;
  font-size: 12px;
  color: #777;
}

.recipes-page__active {
  flex-wrap: wrap;
}

.recipes-page__active:empty {
  display: none;
}

.recipes-page__filters {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 2px 0;
}
.recipes-page__filters button {
  flex: 0 0 auto;
  min-height: 36px;
  padding: 0 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: #fff;
  color: #171717;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}
.recipes-page__filters button:nth-child(3n) {
  background: #f5f5f5;
}
.recipes-page__filters button:nth-child(3n + 1) {
  background: #f5f5f5;
}
.recipes-page__filters button:nth-child(3n + 2) {
  background: #f5f5f5;
}
.recipes-page__filters button[aria-pressed='true'] {
  background: #222;
  border-color: #222;
  color: #fff;
}
.recipes-page__reset {
  border: 0;
  padding: 8px 0;
  color: #222;
  background: transparent;
  font-weight: 700;
  cursor: pointer;
}
.recipes-page {
  isolation: isolate;
  display: grid;
  min-width: 0;
  min-height: calc(100dvh - 140px);
  align-content: start;
  gap: 4px;
  color: #171717;
  background: #fff;
}

.recipes-page::before {
  position: fixed;
  inset: 0;
  z-index: -1;
  background: #fff;
  pointer-events: none;
  content: '';
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
  color: #222;
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
}

.recipes-page__header h1 {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.15;
  letter-spacing: 0;
}

.recipes-page__add {
  display: grid;
  width: 44px;
  height: 44px;
  flex: 0 0 44px;
  place-items: center;
  border-radius: 4px;
  color: #fff;
  font-size: 1.8rem;
  line-height: 1;
  text-decoration: none;
  background: #222;
  box-shadow: none;
}

.recipes-page__search {
  display: grid;
  min-height: 44px;
  padding: 0 10px;
  border: 1px solid #ddd;
  border-radius: 8px;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: var(--space-2);
  background: #fff;
}

.recipes-page__search > span:first-child {
  color: #222;
  font-size: 1.4rem;
  font-weight: 800;
}

.recipes-page__search input {
  min-width: 0;
  height: 42px;
  padding: 0;
  border: 0;
  color: #171717;
  background: transparent;
  outline: 0;
  font-size: 14px;
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
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
  color: #777;
  font-size: 12px;
  font-weight: 400;
}

.recipes-page__list,
.recipes-page__skeletons {
  display: grid;
  gap: 0;
}

.recipe-skeleton {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr);
  align-items: center;
  height: 64px;
  padding: 7px 0;
  gap: 8px;
  border-bottom: 1px solid #e9e9e9;
  background: #fff;
}
.recipe-skeleton > span {
  width: 48px;
  height: 48px;
  border-radius: 3px;
  background: #eee;
}
.recipe-skeleton div {
  display: grid;
  gap: 7px;
}
.recipe-skeleton div span {
  height: 10px;
  background: #eee;
}
.recipe-skeleton div span:nth-child(2) {
  width: 65%;
}
.recipe-skeleton div span:nth-child(3) {
  display: none;
}

.recipes-state {
  display: grid;
  min-height: 180px;
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
  color: #777;
}

.recipes-state__add {
  min-height: 46px;
  padding: 0 var(--space-4);
  border-radius: 8px;
  color: #fff;
  font-weight: 780;
  line-height: 46px;
  text-decoration: none;
  background: #222;
}

.recipes-page__more-error {
  margin: 0;
  color: #555;
  font-size: 0.82rem;
  text-align: center;
}

.recipes-page__search:focus-within {
  border-color: #555;
}
.recipes-page__search {
  background: #fafafa;
  border-radius: 4px;
}
.recipes-page__advanced-toggle {
  min-height: 44px;
  border: 0;
  font-size: 12px;
}
.recipes-page__header p {
  margin-bottom: 2px;
}
.recipes-page__add {
  color: #222;
  background: #f3f3f3;
}
.recipes-state h2 span {
  filter: grayscale(1);
}
.recipes-state p {
  font-size: 12px;
}
.recipes-page > :deep(.app-button),
.recipes-state :deep(.app-button) {
  min-height: 44px;
  border-color: #ddd;
  border-radius: 4px;
  color: #333;
  background: #fff;
  box-shadow: none;
  font-size: 13px;
}
.recipes-page :deep(.app-button__spinner) {
  border-color: #ddd;
  border-top-color: #555;
}
.recipes-page button:focus-visible,
.recipes-page a:focus-visible {
  outline: 2px solid #555;
  outline-offset: 2px;
}
</style>
