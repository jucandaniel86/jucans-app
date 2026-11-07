<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import jucansPlaceholder from '@/assets/jucans_logo.png'
import AppButton from '@/components/ui/AppButton.vue'
import PencilIcon from '@/components/icons/pencilIcon.vue'
import { foodApi } from '@/services/foodApi'
import { useAddRecipeToShoppingList } from '@/composables/useAddRecipeToShoppingList'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import ShoppingListAccess from '@/components/shopping/ShoppingListAccess.vue'
import { shoppingListName } from '@/utils/shoppingPresentation'
import type { FoodUnit, RecipeDetail } from '@/types/food'
import { recipeIngredientLabel } from '@/utils/recipePresentation'

const route = useRoute()
const shopping = useActiveShoppingListStore()
const router = useRouter()
const recipe = ref<RecipeDetail | null>(null)
const units = ref<Record<string, FoodUnit>>({})
const loading = ref(true)
const error = ref('')
let requestSequence = 0
const source = computed(() => {
  if (!recipe.value?.url) return null
  try {
    const url = new URL(recipe.value.url)
    if (!['https:', 'http:'].includes(url.protocol)) return null
    const host = url.hostname.toLowerCase()
    const youtube = host === 'youtu.be' || host === 'youtube.com' || host.endsWith('.youtube.com')
    return { url: url.href, label: youtube ? '▶ Vezi pe YouTube' : '↗ Vezi sursa' }
  } catch {
    return null
  }
})
const { adding, addToList } = useAddRecipeToShoppingList(() => recipe.value?.id ?? null)

function back(): void {
  const previous = router.options.history.state.back
  if (typeof previous === 'string' && previous.startsWith('/') && !previous.startsWith('//')) {
    router.back()
  } else {
    void router.replace({ name: 'recipes', query: { ...route.query } })
  }
}

async function loadRecipe(): Promise<void> {
  const sequence = ++requestSequence
  const recipeId = Number(route.params.id)
  recipe.value = null

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
    if (sequence !== requestSequence) return
    recipe.value = recipeResponse.data
    units.value = config.units
  } catch {
    if (sequence === requestSequence) error.value = 'Nu am putut încărca rețeta.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

watch(() => route.params.id, loadRecipe, { immediate: true })
onBeforeUnmount(() => {
  requestSequence++
})
</script>

<template>
  <section class="recipe-detail">
    <div class="recipe-detail__navigation">
      <div class="recipe-detail__toolbar">
        <button type="button" aria-label="Înapoi" @click="back">‹</button>
        <RouterLink :to="{ name: 'recipes' }">Toate rețetele</RouterLink>
      </div>
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
      <header class="recipe-detail__header">
        <h1>{{ recipe.name }}</h1>
        <div class="recipe-detail__actions">
          <AppButton
            class="recipe-detail__icon-action"
            variant="secondary"
            :loading="adding"
            :aria-label="`Adaugă ingredientele rețetei ${recipe.name} la lista de cumpărături`"
            title="Adaugă la lista de cumpărături"
            @click="addToList"
          >
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M3 3h2l3 12h11l2-8H6" />
              <circle cx="9" cy="20" r="1" />
              <circle cx="18" cy="20" r="1" />
            </svg>
          </AppButton>
          <RouterLink
            class="recipe-detail__icon-action"
            :to="{ name: 'recipe-edit', params: { id: recipe.id } }"
            :aria-label="`Editează rețeta ${recipe.name}`"
            title="Editează rețeta"
          >
            <PencilIcon />
          </RouterLink>
        </div>
      </header>

      <div v-if="recipe.tags.length || recipe.author" class="recipe-detail__tags">
        <span v-for="tag in recipe.tags" :key="tag.id">
          {{ tag.emoji ? `${tag.emoji} ${tag.name}` : tag.name }}
        </span>
        <RouterLink
          v-if="recipe.author"
          class="recipe-detail__author"
          :to="{ name: 'recipes', query: { author_id: String(recipe.author.id) } }"
          :aria-label="`Vezi rețetele autorului ${recipe.author.name}`"
          :title="recipe.author.name"
        >
          <img :src="recipe.author.icon_url ?? jucansPlaceholder" alt="" width="18" height="18" />
          <span>{{ recipe.author.name }}</span>
        </RouterLink>
      </div>

      <a
        v-if="source"
        class="recipe-detail__source"
        :href="source.url"
        target="_blank"
        rel="noopener noreferrer"
        >{{ source.label }}</a
      >

      <img
        class="recipe-detail__image"
        :class="{ 'recipe-detail__image--placeholder': !recipe.image_url }"
        :src="recipe.image_url ?? jucansPlaceholder"
        :alt="recipe.image_url ? `Imagine ${recipe.name}` : ''"
      />

      <section class="recipe-detail__section">
        <div class="recipe-detail__ingredients-heading">
          <h2>Ingrediente</h2>
          <RouterLink
            class="recipe-detail__list-link"
            :to="{ name: shopping.list ? 'shopping-list' : 'shopping-lists' }"
            :title="shopping.list ? shoppingListName(shopping.list) : 'Alege lista de cumpărături'"
            >{{ shopping.list ? shoppingListName(shopping.list) : 'Alege lista' }}</RouterLink
          >
        </div>
        <ShoppingListAccess v-if="shopping.list" :list="shopping.list" />
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

      <section v-if="recipe.description" class="recipe-detail__section">
        <h2>Despre rețetă</h2>
        <p class="recipe-detail__description">{{ recipe.description }}</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.recipe-detail__navigation {
  display: grid;
  gap: var(--space-2);
  min-width: 0;
}
.recipe-detail {
  display: grid;
  gap: var(--space-2);
}

.recipe-detail__toolbar {
  display: flex;
  min-height: 44px;
  align-items: center;
  gap: var(--space-2);
  justify-content: space-between;
  flex-direction: row-reverse;
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
  aspect-ratio: 16 / 9;
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
  gap: var(--space-2);
  min-width: 0;
}

.recipe-detail__header h1 {
  flex: 1;
  min-width: 0;
  margin: 0;
  overflow-wrap: anywhere;
  font-size: 1.35rem;
  line-height: 1.2;
  letter-spacing: 0;
}

.recipe-detail__actions {
  display: flex;
  flex: none;
  gap: var(--space-1);
}

.recipe-detail__actions .recipe-detail__icon-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 44px;
  min-height: 44px;
  padding: 0;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-primary-strong);
  background: var(--color-surface);
  text-decoration: none;
  box-shadow: none;
}

.recipe-detail__icon-action :deep(.app-button__spinner) {
  border-color: var(--color-border);
  border-top-color: var(--color-primary-strong);
}

.recipe-detail__tags {
  display: flex;
  align-items: center;
  min-width: 0;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.recipe-detail__tags > span {
  max-width: 100%;
  overflow-wrap: anywhere;
  padding: 6px 10px;
  border-radius: var(--radius-pill);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 720;
  background: var(--color-yellow-soft);
}

.recipe-detail__author {
  display: inline-flex;
  min-width: 0;
  min-height: 36px;
  max-width: 100%;
  align-items: center;
  gap: 4px;
  padding: 0 6px;
  border: 1px solid #e1e1e1;
  border-radius: 999px;
  color: #666;
  background: #fff;
  font-size: 11px;
  font-weight: 500;
  text-decoration: none;
}
.recipe-detail__author img {
  width: 18px;
  height: 18px;
  flex: none;
  border-radius: 50%;
  object-fit: cover;
}
.recipe-detail__author span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recipe-detail__author:hover {
  color: #111;
  background: #f0f0f0;
}

.recipe-detail__ingredients-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0 var(--space-2);
  min-width: 0;
}
.recipe-detail__list-link {
  display: inline-flex;
  align-items: center;
  min-width: 0;
  min-height: 44px;
  max-width: 100%;
  color: var(--color-primary-strong);
  font-size: 0.8rem;
  font-weight: 700;
  overflow-wrap: anywhere;
  text-decoration: none;
}

.recipe-detail__section {
  display: grid;
  min-width: 0;
  gap: var(--space-2);
  margin-top: var(--space-2);
  padding-top: var(--space-2);
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
  overflow-wrap: anywhere;
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
  overflow-wrap: anywhere;
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
  min-height: 32px;
  align-items: center;
  justify-content: space-between;
  padding: 0;
  color: var(--color-primary-strong);
  font-weight: 750;
  text-decoration: none;
  font-size: 0.86rem;
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
