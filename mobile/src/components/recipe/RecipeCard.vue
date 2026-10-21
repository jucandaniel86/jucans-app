<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import jucansPlaceholder from '@/assets/jucans_logo.png'
import UserAvatar from '@/components/user/UserAvatar.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PencilIcon from '@/components/icons/pencilIcon.vue'
import { useAddRecipeToShoppingList } from '@/composables/useAddRecipeToShoppingList'
import type { RecipeSummary } from '@/types/food'
import { recipeSourceLabel } from '@/utils/recipePresentation'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  recipe: RecipeSummary
  readonly?: boolean
  compact?: boolean
}>()

const router = useRouter()
const route = useRoute()
const visibleTags = computed(() => props.recipe.tags.slice(0, 3))
const remainingTags = computed(() => Math.max(0, props.recipe.tags.length - 3))

const authStore = useAuthStore()
const { adding, addToList } = useAddRecipeToShoppingList(() => props.recipe.id)

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
    :class="{ 'recipe-card--compact': compact }"
    role="link"
    tabindex="0"
    :aria-label="`Deschide rețeta ${recipe.name}`"
    @click="openRecipe"
    @keydown.enter="openRecipe"
  >
    <template v-if="compact">
      <img
        class="recipe-row__image"
        :class="{ 'recipe-row__image--placeholder': !recipe.image_url }"
        :src="recipe.image_url ?? jucansPlaceholder"
        :alt="recipe.image_url ? `Imagine ${recipe.name}` : ''"
      />
      <div class="recipe-row__content">
        <h2 class="recipe-row__title" :title="recipe.name">{{ recipe.name }}</h2>
        <div class="recipe-row__metadata" aria-label="Detaliile rețetei">
          <span v-for="structure in recipe.daily_structures" :key="`meal-${structure.id}`">{{
            structure.name
          }}</span>
          <span v-for="tag in visibleTags" :key="`tag-${tag.id}`">{{ tag.name }}</span>
          <span v-if="remainingTags">+{{ remainingTags }}</span>
        </div>
      </div>
      <div class="recipe-row__bar" @click.stop @keydown.stop>
        <RouterLink
          v-if="recipe.author"
          class="recipe-row__credit"
          :to="{
            name: 'recipes',
            query: {
              ...(route.name === 'recipes' ? route.query : {}),
              author_id: String(recipe.author.id),
            },
          }"
          :aria-label="`Vezi rețetele autorului ${recipe.author.name}`"
          :title="`${recipe.author.name} · Adăugată de ${recipe.creator.username}`"
          @click.stop
          @keydown.stop
        >
          <img :src="recipe.author.icon_url ?? jucansPlaceholder" alt="" width="20" height="20" />
          <span>{{ recipe.author.name }}</span>
        </RouterLink>
        <span v-else class="recipe-row__credit" :title="`Adăugată de ${recipe.creator.username}`">
          <UserAvatar
            :avatar="recipe.creator.avatar"
            :username="recipe.creator.username"
            size="small"
          />
          <span>{{ recipe.creator.username }}</span>
        </span>
        <div class="recipe-row__actions">
          <button
            v-if="!readonly && authStore.user && authStore.user.is_admin"
            type="button"
            :aria-label="`Editează rețeta ${recipe.name}`"
            title="Editează rețeta"
            @click.stop="editRecipe"
            @keydown.stop
          >
            <PencilIcon />
          </button>
          <a
            v-if="recipe.url"
            class="recipe-row__source"
            :href="recipe.url"
            target="_blank"
            rel="noopener noreferrer"
            :aria-label="`Deschide sursa: ${recipeSourceLabel(recipe.url)}`"
            :title="recipeSourceLabel(recipe.url)"
            @click.stop
            @keydown.stop
          >
            <svg
              v-if="recipeSourceLabel(recipe.url) === '▶ YouTube'"
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.5"
              aria-hidden="true"
            >
              <rect x="2" y="5" width="20" height="14" rx="4" />
              <path d="m10 9 5 3-5 3Z" fill="currentColor" stroke="none" />
            </svg>
            <svg
              v-else
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.5"
              aria-hidden="true"
            >
              <circle cx="12" cy="12" r="9" />
              <ellipse cx="12" cy="12" rx="4" ry="9" />
              <path d="M3 12h18" />
            </svg>
          </a>
          <AppButton
            v-if="!readonly"
            class="recipe-row__shopping"
            variant="secondary"
            :loading="adding"
            :aria-label="`Adaugă ${recipe.name} la lista de cumpărături`"
            title="Adaugă la lista de cumpărături"
            @click.stop="addToList"
            @keydown.stop
          >
            <svg
              width="17"
              height="17"
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
        </div>
      </div>
    </template>
    <template v-else>
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
              v-if="!readonly && authStore.user && authStore.user.is_admin"
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
          <div
            v-if="recipe.daily_structures?.length"
            class="recipe-card__meals"
            aria-label="Tipuri de masă"
          >
            <span v-for="structure in recipe.daily_structures" :key="structure.id">{{
              structure.name
            }}</span>
          </div>
        </div>
      </div>
      <div v-if="recipe.tags.length" class="recipe-card__classification">
        <div v-if="recipe.tags.length" class="recipe-card__tags" aria-label="Tag-uri">
          <span v-for="tag in visibleTags" :key="tag.id">
            {{ tag.emoji ? `${tag.emoji} ${tag.name}` : tag.name }}
          </span>
          <span v-if="remainingTags">+{{ remainingTags }}</span>
        </div>
      </div>
      <div class="recipe-card__footer">
        <div class="recipe-card__credits">
          <div class="recipe-card__creator">
            <UserAvatar
              :avatar="recipe.creator.avatar"
              :username="recipe.creator.username"
              size="small"
            />
            <span :title="recipe.creator.username">{{ recipe.creator.username }}</span>
          </div>
          <RouterLink
            v-if="recipe.author"
            class="recipe-card__author"
            :to="{
              name: 'recipes',
              query: {
                ...(route.name === 'recipes' ? route.query : {}),
                author_id: String(recipe.author.id),
              },
            }"
            :aria-label="`Vezi rețetele autorului ${recipe.author.name}`"
            @click.stop
            @keydown.stop
          >
            <img :src="recipe.author.icon_url ?? jucansPlaceholder" alt="" width="24" height="24" />
            <span>{{ recipe.author.name }}</span>
          </RouterLink>
        </div>
        <AppButton
          v-if="!readonly"
          class="recipe-card__shopping-button"
          variant="secondary"
          :loading="adding"
          :aria-label="`Adaugă ${recipe.name} la lista de cumpărături`"
          title="Adaugă la lista de cumpărături"
          @click.stop="addToList"
          @keydown.stop
        >
          <span aria-hidden="true">🛒</span>
        </AppButton>
      </div>
    </template>
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
  align-self: start;
  align-content: start;
  column-gap: var(--space-4);
  row-gap: var(--space-2);
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

.recipe-card__classification {
  display: grid;
  grid-column: 1 / -1;
  gap: var(--space-2);
}

.recipe-card__meals {
  margin-top: var(--space-1);
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.7rem;
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

.recipe-card__credits {
  display: flex;
  align-items: center;
  flex: 1;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-2);
  min-width: 0;
}

.recipe-card__author {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-width: 0;
  max-width: 100%;
  color: var(--color-primary-strong);
  font-size: 0.78rem;
  font-weight: 700;
  text-decoration: none;
  margin-left: auto;
}

.recipe-card__author img {
  width: 24px;
  height: 24px;
  flex: 0 0 24px;
  object-fit: cover;
  border-radius: 50%;
}

.recipe-card__author span {
  overflow-wrap: anywhere;
}

.recipe-card__author:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 3px;
}

.recipe-card__creator {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 700;
  min-width: 0;
}

.recipe-card__creator span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.recipe-card__creator :deep(.user-avatar) {
  flex-shrink: 0;
}

.recipe-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  grid-column: span 2;
}

.recipe-card__shopping-button {
  flex: 0 0 44px;
  width: 44px;
  min-height: 44px;
  padding: 0;
  border-radius: 8px;
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
  font-size: 1.1rem;
}

.recipe-card__shopping-button :deep(.app-button__spinner) {
  border-color: var(--color-primary);
  border-top-color: var(--color-primary-strong);
}

@media (max-width: 390px) {
  .recipe-card {
    grid-template-columns: 92px minmax(0, 1fr);
  }

  .recipe-card__image {
    width: 92px;
  }
}

.recipe-card--compact {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr);
  align-items: center;
  align-content: center;
  height: auto;
  padding: 6px 0;
  column-gap: 8px;
  row-gap: 4px;
  border: 0;
  border-bottom: 1px solid #e9e9e9;
  border-radius: 0;
  color: #171717;
  background: #fff;
  box-shadow: none;
  transition: background-color 120ms;
}

.recipe-card--compact:hover {
  background: #fafafa;
}
.recipe-card--compact:active {
  transform: none;
}
.recipe-card--compact:focus-visible {
  outline: 2px solid #555;
  outline-offset: -2px;
  box-shadow: none;
}

.recipe-row__image {
  width: 48px;
  height: 48px;
  border-radius: 3px;
  object-fit: cover;
  background: #f3f3f3;
}
.recipe-row__image--placeholder {
  padding: 4px;
  object-fit: contain;
}
.recipe-row__content {
  display: grid;
  min-width: 0;
  gap: 3px;
}
.recipe-row__title {
  margin: 0;
  overflow: hidden;
  color: #171717;
  font-size: 14px;
  font-weight: 600;
  line-height: 19px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recipe-row__metadata {
  display: block;
  min-width: 0;
  height: 18px;
  overflow: hidden;
  color: #737373;
  font-size: 11px;
  font-weight: 400;
  line-height: 18px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recipe-row__metadata > * {
  vertical-align: middle;
}
.recipe-row__metadata > * + *::before {
  margin: 0 5px;
  color: #999;
  content: '·';
}
.recipe-row__bar {
  display: flex;
  grid-column: 1 / -1;
  min-width: 0;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
}
.recipe-row__credit {
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
.recipe-row__credit > span:not(.user-avatar) {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recipe-row__credit img,
.recipe-row__credit :deep(.user-avatar) {
  width: 18px;
  height: 18px;
  min-width: 18px;
  flex: 0 0 18px;
  border-radius: 50%;
  object-fit: cover;
  font-size: 9px;
}
.recipe-row__actions {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 4px;
  margin-left: auto;
}
.recipe-row__actions > button,
.recipe-row__actions > a {
  display: inline-flex;
  width: 36px;
  min-height: 36px;
  flex: 0 0 36px;
  align-items: center;
  justify-content: center;
  margin: 0;
  padding: 0;
  border: 1px solid #e1e1e1;
  border-radius: 999px;
  color: #666;
  background: #fff;
  box-shadow: none;
  text-decoration: none;
}
.recipe-row__actions :deep(svg) {
  width: 14px;
  height: 14px;
  stroke-width: 1.5;
}
.recipe-row__actions > button:hover:not(:disabled),
.recipe-row__actions > a:hover,
a.recipe-row__credit:hover {
  color: #111;
  background: #f0f0f0;
}
.recipe-row__actions > button:focus-visible,
.recipe-row__actions > a:focus-visible,
.recipe-row__credit:focus-visible {
  outline: 2px solid #555;
  outline-offset: -2px;
}
.recipe-row__shopping :deep(.app-button__spinner) {
  width: 16px;
  height: 16px;
  border-color: #ddd;
  border-top-color: #555;
}
</style>
