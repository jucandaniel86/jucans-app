<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import RecipeCard from '@/components/recipe/RecipeCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { foodApi } from '@/services/foodApi'
import type { PaginationMeta, RecipeSummary } from '@/types/food'

const recipes = ref<RecipeSummary[]>([])
const pagination = ref<PaginationMeta | null>(null)
const search = ref('')
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0

const searchTerm = computed(() => search.value.trim())
const total = computed(() => pagination.value?.total ?? 0)
const canLoadMore = computed(
  () => Boolean(pagination.value && pagination.value.current_page < pagination.value.last_page),
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

watch(search, () => {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(() => loadRecipes(), 350)
})

onMounted(() => loadRecipes())
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
      <RouterLink class="recipes-page__add" :to="{ name: 'recipe-create' }" aria-label="Adaugă rețetă">
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

    <div v-else-if="recipes.length === 0 && searchTerm" class="recipes-state">
      <h2>N-am găsit nicio rețetă pentru „{{ searchTerm }}”.</h2>
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
