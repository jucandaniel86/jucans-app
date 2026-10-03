<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import jucansPlaceholder from '@/assets/jucans_logo.png'
import AppButton from '@/components/ui/AppButton.vue'
import UserAvatar from '@/components/user/UserAvatar.vue'
import { foodApi } from '@/services/foodApi'
import { useAddRecipeToShoppingList } from '@/composables/useAddRecipeToShoppingList'
import type { FoodUnit, RecipeDetail } from '@/types/food'
import { recipeIngredientLabel, recipeSourceLabel } from '@/utils/recipePresentation'

const route = useRoute()
const router = useRouter()
const recipe = ref<RecipeDetail | null>(null)
const units = ref<Record<string, FoodUnit>>({})
const loading = ref(true)
const error = ref('')
const { adding, shoppingMessage, shoppingError, reviewNames, addToList } =
  useAddRecipeToShoppingList(() => recipe.value?.id ?? null)

async function loadRecipe(): Promise<void> {
  const recipeId = Number(route.params.id)

  if (!Number.isInteger(recipeId) || recipeId < 1) {
    error.value = 'Rețeta nu este validă.'
    loading.value = false
    return
  }

  loading.value = true
  error.value = ''

  try {
    const [recipeResponse, config] = await Promise.all([
      foodApi.getRecipe(recipeId),
      foodApi.getConfig(),
    ])
    recipe.value = recipeResponse.data
    units.value = config.units
  } catch {
    error.value = 'Nu am putut încărca rețeta.'
  } finally {
    loading.value = false
  }
}

onMounted(loadRecipe)
</script>

<template>
  <section class="recipe-detail">
    <div class="recipe-detail__toolbar">
      <button type="button" aria-label="Înapoi" @click="router.back()">‹</button>
      <RouterLink :to="{ name: 'recipes' }">Toate rețetele</RouterLink>
    </div>

    <div v-if="loading" class="recipe-detail__loading" aria-label="Se încarcă rețeta">
      <span />
      <span />
      <span />
    </div>

    <div v-else-if="error || !recipe" class="recipe-detail__state" role="alert">
      <h1>Nu am putut deschide rețeta.</h1>
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="loadRecipe">Încearcă din nou</AppButton>
    </div>

    <template v-else>
      <img
        class="recipe-detail__image"
        :class="{ 'recipe-detail__image--placeholder': !recipe.image_url }"
        :src="recipe.image_url ?? jucansPlaceholder"
        :alt="recipe.image_url ? `Imagine ${recipe.name}` : ''"
      />

      <header class="recipe-detail__header">
        <div>
          <p>Rețetă</p>
          <h1>{{ recipe.name }}</h1>
        </div>
        <RouterLink
          class="recipe-detail__edit"
          :to="{ name: 'recipe-edit', params: { id: recipe.id } }"
        >
          Editează
        </RouterLink>
      </header>

      <div v-if="recipe.tags.length" class="recipe-detail__tags">
        <span v-for="tag in recipe.tags" :key="tag.id">
          {{ tag.emoji ? `${tag.emoji} ${tag.name}` : tag.name }}
        </span>
      </div>

      <div class="recipe-detail__creator">
        <UserAvatar
          :avatar="recipe.creator.avatar"
          :username="recipe.creator.username"
          size="small"
        />
        <span>Adăugată de {{ recipe.creator.username }}</span>
      </div>

      <div class="recipe-detail__shopping">
        <AppButton variant="secondary" :loading="adding" @click="addToList">
          <span aria-hidden="true">🛒</span> Adaugă la listă
        </AppButton>
        <RouterLink :to="{ name: 'shopping-list' }">Deschide lista</RouterLink>
        <p v-if="shoppingMessage" class="recipe-detail__shopping-success" role="status">
          {{ shoppingMessage }}
        </p>
        <div v-if="shoppingError" class="recipe-detail__shopping-error" role="alert">
          <p>{{ shoppingError }}</p>
          <small v-if="reviewNames.length">{{ reviewNames.join(' · ') }}</small>
        </div>
      </div>

      <section v-if="recipe.description" class="recipe-detail__section">
        <h2>Despre rețetă</h2>
        <p class="recipe-detail__description">{{ recipe.description }}</p>
      </section>

      <section class="recipe-detail__section">
        <h2>Ingrediente</h2>
        <ul v-if="recipe.ingredients.length" class="recipe-detail__ingredients">
          <li v-for="ingredient in recipe.ingredients" :key="ingredient.id">
            <span aria-hidden="true" />
            <div>
              <strong>{{ recipeIngredientLabel(ingredient, units) }}</strong>
              <small v-if="ingredient.value !== null && ingredient.raw_text">{{
                ingredient.raw_text
              }}</small>
            </div>
          </li>
        </ul>
        <p v-else class="recipe-detail__muted">Nu sunt ingrediente salvate.</p>
      </section>

      <a
        v-if="recipe.url"
        class="recipe-detail__source"
        :href="recipe.url"
        target="_blank"
        rel="noopener noreferrer"
      >
        {{ recipeSourceLabel(recipe.url) }}
        <span aria-hidden="true">↗</span>
      </a>
    </template>
  </section>
</template>

<style scoped>
.recipe-detail__shopping {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
}
.recipe-detail__shopping :deep(.app-button) {
  min-height: 44px;
  padding: 0 var(--space-3);
  border-radius: 8px;
}
.recipe-detail__shopping a {
  padding: var(--space-2) 0;
  color: var(--color-primary-strong);
  font-size: 0.85rem;
  font-weight: 700;
}
.recipe-detail__shopping-success,
.recipe-detail__shopping-error {
  flex-basis: 100%;
  margin: 0;
  font-size: 0.86rem;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.recipe-detail__shopping-success {
  color: var(--color-success);
}
.recipe-detail__shopping-error {
  color: var(--color-error);
}
.recipe-detail__shopping-error p {
  margin: 0;
}
.recipe-detail {
  display: grid;
  gap: var(--space-5);
}

.recipe-detail__toolbar {
  display: flex;
  min-height: 44px;
  align-items: center;
  gap: var(--space-2);
}

.recipe-detail__toolbar button {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  color: var(--color-text);
  font-size: 2rem;
  background: var(--color-surface);
  cursor: pointer;
}

.recipe-detail__toolbar a {
  color: var(--color-text-muted);
  font-size: 0.86rem;
  font-weight: 700;
  text-decoration: none;
}

.recipe-detail__image {
  width: 100%;
  aspect-ratio: 1;
  max-height: 560px;
  border-radius: 8px;
  object-fit: cover;
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}

.recipe-detail__image--placeholder {
  padding: var(--space-8);
  object-fit: contain;
}

.recipe-detail__header {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: var(--space-3);
}

.recipe-detail__header p,
.recipe-detail__header h1 {
  margin: 0;
}

.recipe-detail__header p {
  color: var(--color-primary-strong);
  font-size: 0.75rem;
  font-weight: 850;
  text-transform: uppercase;
}

.recipe-detail__header h1 {
  margin-top: var(--space-1);
  overflow-wrap: anywhere;
  font-size: 1.8rem;
  line-height: 1.15;
  letter-spacing: 0;
}

.recipe-detail__edit {
  min-height: 44px;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-primary);
  border-radius: 8px;
  color: var(--color-primary-strong);
  font-weight: 750;
  line-height: 42px;
  text-decoration: none;
  background: var(--color-primary-soft);
}

.recipe-detail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.recipe-detail__tags span {
  padding: 6px 10px;
  border-radius: var(--radius-pill);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 720;
  background: var(--color-yellow-soft);
}

.recipe-detail__creator {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.86rem;
  font-weight: 700;
}

.recipe-detail__section {
  display: grid;
  gap: var(--space-3);
  padding-top: var(--space-4);
  border-top: 1px solid var(--color-border);
}

.recipe-detail__section h2,
.recipe-detail__section p {
  margin: 0;
}

.recipe-detail__section h2 {
  font-size: 1.1rem;
}

.recipe-detail__description {
  line-height: 1.65;
  white-space: pre-wrap;
}

.recipe-detail__ingredients {
  display: grid;
  margin: 0;
  padding: 0;
  gap: var(--space-2);
  list-style: none;
}

.recipe-detail__ingredients li {
  display: grid;
  min-height: 44px;
  grid-template-columns: 8px minmax(0, 1fr);
  align-items: start;
  gap: var(--space-3);
}

.recipe-detail__ingredients li > span {
  width: 8px;
  height: 8px;
  margin-top: 7px;
  border-radius: 50%;
  background: var(--color-pink);
}

.recipe-detail__ingredients strong,
.recipe-detail__ingredients small {
  display: block;
}

.recipe-detail__ingredients strong {
  font-size: 0.92rem;
}

.recipe-detail__ingredients small,
.recipe-detail__muted {
  margin-top: 3px;
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.recipe-detail__source {
  display: flex;
  min-height: 48px;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-primary-strong);
  font-weight: 750;
  text-decoration: none;
  background: var(--color-surface);
}

.recipe-detail__loading {
  display: grid;
  gap: var(--space-4);
}

.recipe-detail__loading span {
  height: 58px;
  border-radius: 8px;
  background: var(--color-border);
}

.recipe-detail__loading span:first-child {
  height: auto;
  aspect-ratio: 1;
}

.recipe-detail__state {
  display: grid;
  min-height: 360px;
  place-content: center;
  justify-items: center;
  gap: var(--space-3);
  text-align: center;
}

.recipe-detail__state h1,
.recipe-detail__state p {
  margin: 0;
}
</style>
