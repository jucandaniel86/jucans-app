<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import RecipeCard from '@/components/recipe/RecipeCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { foodApi } from '@/services/foodApi'
import type { RecipeSummary } from '@/types/food'

const route = useRoute()
const router = useRouter()
const recipe = ref<RecipeSummary | null>(null)
const loading = ref(true)
const choosing = ref(false)
const error = ref('')

const selectedTags = computed(() => {
  const value = typeof route.query.tags === 'string' ? route.query.tags : ''
  return value
    .split(',')
    .map(Number)
    .filter((id) => Number.isInteger(id) && id > 0)
})

async function loadRecipe(): Promise<void> {
  const recipeId = Number(route.query.recipe)
  if (!Number.isInteger(recipeId) || recipeId < 1) {
    await chooseAnother()
    return
  }

  loading.value = true
  error.value = ''
  try {
    const response = await foodApi.getRecipe(recipeId)
    recipe.value = response.data
  } catch {
    error.value = 'Nu am putut încărca rețeta aleasă.'
  } finally {
    loading.value = false
  }
}

async function chooseAnother(): Promise<void> {
  choosing.value = true
  error.value = ''

  try {
    const response = await foodApi.randomRecipe(selectedTags.value, recipe.value?.id)
    recipe.value = response.data

    if (response.data) {
      await router.replace({
        name: 'recipe-random',
        query: {
          recipe: String(response.data.id),
          ...(selectedTags.value.length ? { tags: selectedTags.value.join(',') } : {}),
        },
      })
    }
  } catch {
    error.value = 'Nu am putut alege altă rețetă. Încearcă din nou.'
  } finally {
    loading.value = false
    choosing.value = false
  }
}

onMounted(loadRecipe)
</script>

<template>
  <section class="random-page">
    <header class="random-page__header">
      <p>Alegerea Jucans</p>
      <h1>Azi gătim... <span aria-hidden="true">🍽️</span></h1>
    </header>

    <div v-if="loading" class="random-page__loading" aria-label="Se încarcă rețeta">
      <span />
      <div><span /><span /><span /></div>
    </div>

    <div v-else-if="recipe" class="random-page__result">
      <RecipeCard :recipe="recipe" />
      <div class="random-page__actions">
        <RouterLink :to="{ name: 'recipe-detail', params: { id: recipe.id } }">
          Vezi rețeta
        </RouterLink>
        <AppButton variant="secondary" :loading="choosing" @click="chooseAnother">
          🎲 Alege alta
        </AppButton>
      </div>
    </div>

    <div v-else class="random-page__empty">
      <h2>Nicio rețetă disponibilă 😅</h2>
      <RouterLink :to="{ name: 'home' }">Schimbă filtrele</RouterLink>
    </div>

    <p v-if="error" class="random-page__error" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.random-page {
  display: grid;
  gap: var(--space-6);
}

.random-page__header p,
.random-page__header h1 {
  margin: 0;
}

.random-page__header p {
  margin-bottom: var(--space-1);
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.random-page__header h1 {
  font-size: 1.9rem;
  line-height: 1.15;
  letter-spacing: 0;
}

.random-page__result {
  display: grid;
  gap: var(--space-5);
}

.random-page__actions {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: var(--space-3);
}

.random-page__actions > a {
  display: inline-flex;
  min-height: 52px;
  align-items: center;
  justify-content: center;
  padding: 0 var(--space-4);
  border-radius: var(--radius-md);
  color: #fff;
  font-weight: 750;
  text-decoration: none;
  background: var(--color-primary-strong);
  box-shadow: 0 8px 18px rgb(8 127 138 / 22%);
}

.random-page__loading {
  display: grid;
  min-height: 138px;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  grid-template-columns: 112px minmax(0, 1fr);
  gap: var(--space-4);
  background: var(--color-surface);
}

.random-page__loading > span {
  width: 112px;
  height: 112px;
  border-radius: 7px;
  background: var(--color-background-strong);
}

.random-page__loading div {
  display: grid;
  align-content: start;
  gap: var(--space-3);
}

.random-page__loading div span {
  height: 16px;
  border-radius: 4px;
  background: var(--color-background-strong);
}

.random-page__loading div span:nth-child(2) {
  width: 72%;
}

.random-page__loading div span:nth-child(3) {
  width: 48%;
}

.random-page__empty {
  padding: var(--space-8) var(--space-5);
  text-align: center;
}

.random-page__empty h2 {
  margin: 0 0 var(--space-4);
  font-size: 1.15rem;
  letter-spacing: 0;
}

.random-page__empty a {
  color: var(--color-primary-strong);
  font-weight: 750;
}

.random-page__error {
  margin: 0;
  color: var(--color-error);
  text-align: center;
}

@media (max-width: 380px) {
  .random-page__actions {
    grid-template-columns: 1fr;
  }
}
</style>
