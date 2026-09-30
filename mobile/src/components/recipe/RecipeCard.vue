<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'

import jucansPlaceholder from '@/assets/jucans_logo.png'
import UserAvatar from '@/components/user/UserAvatar.vue'
import type { RecipeSummary } from '@/types/food'
import { recipeSourceLabel } from '@/utils/recipePresentation'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  recipe: RecipeSummary
}>()

const router = useRouter()
const visibleTags = computed(() => props.recipe.tags.slice(0, 3))
const remainingTags = computed(() => Math.max(0, props.recipe.tags.length - 3))
const authStore = useAuthStore()

function openRecipe(): void {
  router.push({ name: 'recipe-detail', params: { id: props.recipe.id } })
}

function editRecipe(): void {
  router.push({ name: 'recipe-edit', params: { id: props.recipe.id } })
}
</script>

<template>
  <article
    class="recipe-card"
    role="link"
    tabindex="0"
    :aria-label="`Deschide rețeta ${recipe.name}`"
    @click="openRecipe"
    @keydown.enter="openRecipe"
  >
    <img
      class="recipe-card__image"
      :class="{ 'recipe-card__image--placeholder': !recipe.image_url }"
      :src="recipe.image_url ?? jucansPlaceholder"
      :alt="recipe.image_url ? `Imagine ${recipe.name}` : ''"
    />

    <div class="recipe-card__body">
      <div class="recipe-card__heading">
        <div class="recipe-card__title-row">
          <h2>{{ recipe.name }}</h2>
          <button
            v-if="authStore.user && authStore.user.is_admin"
            type="button"
            :aria-label="`Editează rețeta ${recipe.name}`"
            @click.stop="editRecipe"
          >
            Editează
          </button>
        </div>
        <a
          v-if="recipe.url"
          :href="recipe.url"
          target="_blank"
          rel="noopener noreferrer"
          @click.stop
        >
          {{ recipeSourceLabel(recipe.url) }}
        </a>
      </div>

      <div v-if="recipe.tags.length" class="recipe-card__tags" aria-label="Tag-uri">
        <span v-for="tag in visibleTags" :key="tag.id">
          {{ tag.emoji ? `${tag.emoji} ${tag.name}` : tag.name }}
        </span>
        <span v-if="remainingTags">+{{ remainingTags }}</span>
      </div>

      <div class="recipe-card__creator">
        <UserAvatar
          :avatar="recipe.creator.avatar"
          :username="recipe.creator.username"
          size="small"
        />
        <span>{{ recipe.creator.username }}</span>
      </div>
    </div>
  </article>
</template>

<style scoped>
.recipe-card {
  display: grid;
  min-width: 0;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  grid-template-columns: 112px minmax(0, 1fr);
  gap: var(--space-4);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition:
    transform var(--transition-fast),
    border-color var(--transition-fast);
}

.recipe-card:focus-visible {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: var(--shadow-focus);
}

.recipe-card:active {
  transform: scale(0.99);
}

.recipe-card__image {
  width: 112px;
  aspect-ratio: 1;
  align-self: start;
  border-radius: 7px;
  object-fit: cover;
  background: var(--color-surface-soft);
}

.recipe-card__image--placeholder {
  padding: var(--space-2);
  object-fit: contain;
}

.recipe-card__body {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: var(--space-3);
}

.recipe-card__heading {
  min-width: 0;
}

.recipe-card__title-row {
  display: flex;
  min-width: 0;
  align-items: start;
  justify-content: space-between;
  gap: var(--space-2);
}

.recipe-card__heading h2 {
  min-width: 0;
  margin: 0;
  overflow-wrap: anywhere;
  font-size: 1.05rem;
  line-height: 1.25;
  letter-spacing: 0;
}

.recipe-card__title-row button {
  min-height: 44px;
  flex: 0 0 auto;
  margin: calc(var(--space-2) * -1) calc(var(--space-2) * -1) 0 0;
  padding: 0 var(--space-2);
  border: 0;
  color: var(--color-primary-strong);
  font-size: 0.74rem;
  font-weight: 780;
  background: transparent;
  cursor: pointer;
}

.recipe-card__heading a {
  display: inline-block;
  min-height: 28px;
  margin-top: var(--space-1);
  color: var(--color-primary-strong);
  font-size: 0.78rem;
  font-weight: 750;
  line-height: 28px;
  text-decoration: none;
}

.recipe-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}

.recipe-card__tags span {
  max-width: 100%;
  overflow: hidden;
  padding: 4px 8px;
  border-radius: var(--radius-pill);
  color: var(--color-text-muted);
  font-size: 0.7rem;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
  background: var(--color-yellow-soft);
}

.recipe-card__creator {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 700;
}

@media (max-width: 390px) {
  .recipe-card {
    grid-template-columns: 92px minmax(0, 1fr);
  }

  .recipe-card__image {
    width: 92px;
  }
}
</style>
