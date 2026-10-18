<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import placeholder from '@/assets/jucans_logo.png'
import RecipeCard from '@/components/recipe/RecipeCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppCard from '@/components/ui/AppCard.vue'
import { dietIngredientStatus } from '@/config/dietIngredientStatus'
import { dietsApi } from '@/services/dietsApi'
import { DietStatus, type Diet, type DietRecipePriority } from '@/types/diets'
import SourceCard from './components/SourceCard.vue'

const route = useRoute()
const router = useRouter()
const diet = ref<Diet | null>(null)
const loading = ref(true)
const error = ref('')
const priority = ref<DietRecipePriority | 'all'>('all')
let requestSequence = 0
const statusLabels: Record<DietStatus, string> = {
  [DietStatus.DRAFT]: 'Draft',
  [DietStatus.COMPLETED]: 'Finalizată',
  [DietStatus.ACTIVE]: 'Activă',
}
const priorityFilters = [
  { value: 'all', label: 'Toate' },
  { value: 'normal', label: 'normal' },
  { value: 'high', label: 'high' },
  { value: 'required', label: 'required' },
] as const
const sections = [
  { id: 'diet-recipes', label: 'Rețete' },
  { id: 'diet-daily-structure', label: 'Structură zilnică' },
  { id: 'diet-ingredients', label: 'Ingrediente' },
  { id: 'diet-sources', label: 'Surse' },
  { id: 'diet-notes', label: 'Observații' },
]
const image = computed(() => {
  const path = diet.value?.thumbnail_url || diet.value?.thumbnail
  if (!path) return null
  return new URL(path, new URL('/', import.meta.env.VITE_API_BASE_URL || window.location.origin))
    .href
})
const recipes = computed(() =>
  (diet.value?.recipes ?? []).filter(
    (recipe) => priority.value === 'all' || recipe.priority === priority.value,
  ),
)
const dailyStructure = computed(() =>
  [...(diet.value?.daily_structure ?? [])].sort((a, b) => a.position - b.position),
)
const ingredientGroups = computed(() =>
  Object.entries(dietIngredientStatus)
    .map(([status, config]) => ({
      status,
      label: config.label,
      items: (diet.value?.ingredients ?? []).filter((item) => item.status === status),
    }))
    .filter((group) => group.items.length > 0),
)

async function loadDiet(): Promise<void> {
  const sequence = ++requestSequence
  const id = Number(route.params.id)
  diet.value = null
  error.value = ''
  priority.value = 'all'
  if (!Number.isSafeInteger(id) || id < 1) {
    error.value = 'Dieta nu este validă.'
    loading.value = false
    return
  }
  loading.value = true
  try {
    const response = await dietsApi.getDiet(id)
    if (sequence === requestSequence) diet.value = response.data
  } catch {
    if (sequence === requestSequence) error.value = 'Nu am putut încărca dieta.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

watch(() => route.params.id, loadDiet, { immediate: true })
onBeforeUnmount(() => {
  requestSequence++
})
</script>

<template>
  <article class="diet-detail">
    <RouterLink :to="{ name: 'diets-lists' }">‹ Înapoi la diete</RouterLink>
    <p v-if="loading" class="diet-detail__state" role="status">Se încarcă dieta…</p>
    <div v-else-if="error || !diet" class="diet-detail__state" role="alert">
      <p>{{ error || 'Dieta nu este disponibilă.' }}</p>
      <AppButton variant="secondary" @click="loadDiet">Încearcă din nou</AppButton>
    </div>
    <template v-else>
      <header class="diet-detail__header">
        <img
          :src="image || placeholder"
          :alt="image ? `Imagine ${diet.name}` : ''"
          class="diet-detail__image"
          :class="{ 'diet-detail__image--placeholder': !image }"
        />
        <div class="diet-detail__intro">
          <h1>{{ diet.name }}</h1>
          <span class="diet-card__status" :class="`diet-card__status--${diet.status}`">
            {{ statusLabels[diet.status] }}
          </span>
          <AppButton
            variant="secondary"
            @click="router.push({ name: 'diet-edit', params: { id: diet.id } })"
          >
            Edit
          </AppButton>
        </div>
        <p v-if="diet.description" class="diet-detail__description diet-detail__text">
          {{ diet.description }}
        </p>
      </header>
      <nav class="diet-detail__anchors" aria-label="Secțiunile dietei">
        <a v-for="section in sections" :key="section.id" :href="`#${section.id}`">{{
          section.label
        }}</a>
      </nav>

      <section id="diet-recipes" class="diet-detail__section" aria-labelledby="diet-recipes-title">
        <h2 id="diet-recipes-title">Rețete</h2>
        <div
          class="diet-detail__filters"
          role="group"
          aria-label="Filtrează rețetele după prioritate"
        >
          <button
            v-for="filter in priorityFilters"
            :key="filter.value"
            type="button"
            :aria-pressed="priority === filter.value"
            @click="priority = filter.value"
          >
            {{ filter.label }}
          </button>
        </div>
        <p v-if="!diet.recipes?.length">Nu există rețete asociate dietei.</p>
        <p v-else-if="!recipes.length">Nu există rețete pentru prioritatea selectată.</p>
        <div v-else class="diet-detail__list">
          <div v-for="recipe in recipes" :key="recipe.id" class="diet-detail__recipe">
            <small>Prioritate: {{ recipe.priority }}</small>
            <RecipeCard :recipe="recipe" readonly />
          </div>
        </div>
      </section>

      <section
        id="diet-daily-structure"
        class="diet-detail__section"
        aria-labelledby="diet-structure-title"
      >
        <h2 id="diet-structure-title">Structură zilnică</h2>
        <ol v-if="dailyStructure.length" class="diet-detail__structure">
          <li v-for="item in dailyStructure" :key="item.id">{{ item.structure.name }}</li>
        </ol>
        <p v-else>Nu există o structură zilnică configurată.</p>
      </section>

      <section
        id="diet-ingredients"
        class="diet-detail__section"
        aria-labelledby="diet-ingredients-title"
      >
        <h2 id="diet-ingredients-title">Ingrediente</h2>
        <p v-if="!ingredientGroups.length">Nu există ingrediente asociate dietei.</p>
        <AppCard v-for="group in ingredientGroups" :key="group.status" class="diet-detail__group">
          <h3>
            <span class="ingredient-card__status" :class="`diet-status--${group.status}`">{{
              group.label
            }}</span>
          </h3>
          <ul class="diet-detail__ingredients">
            <li v-for="ingredient in group.items" :key="ingredient.id">
              <strong>{{ ingredient.name }}</strong>
              <p v-if="ingredient.notes" class="diet-detail__text">{{ ingredient.notes }}</p>
            </li>
          </ul>
        </AppCard>
      </section>

      <section id="diet-sources" class="diet-detail__section" aria-labelledby="diet-sources-title">
        <h2 id="diet-sources-title">Surse</h2>
        <div v-if="diet.sources.length" class="diet-detail__list">
          <SourceCard v-for="source in diet.sources" :key="source.id" :item="source" readonly />
        </div>
        <p v-else>Nu există surse asociate dietei.</p>
      </section>

      <section id="diet-notes" class="diet-detail__section" aria-labelledby="diet-notes-title">
        <h2 id="diet-notes-title">Observații</h2>
        <p class="diet-detail__text">{{ diet.notes || 'Nu există observații.' }}</p>
      </section>
    </template>
  </article>
</template>

<style scoped>
@import './styles/diets.style.css';
@import './styles/ingredient-card.style.css';

.diet-detail,
.diet-detail__section,
.diet-detail__list,
.diet-detail__intro {
  display: grid;
  min-width: 0;
  gap: var(--space-4);
}

.diet-detail {
  gap: var(--space-6);
  overflow-wrap: anywhere;
}

.diet-detail__header {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
}

.diet-detail__image {
  width: 96px;
  aspect-ratio: 1;
  object-fit: cover;
  border-radius: var(--radius-sm);
  background: var(--color-surface-soft);
}

.diet-detail__image--placeholder {
  padding: var(--space-2);
  object-fit: contain;
}

.diet-detail__intro {
  justify-items: start;
  gap: var(--space-2);
}

.diet-detail h1,
.diet-detail h2,
.diet-detail h3,
.diet-detail p {
  margin: 0;
}

.diet-detail h1 {
  font-size: 1.7rem;
}
.diet-detail h2 {
  font-size: 1.2rem;
}
.diet-detail h3 {
  font-size: 0.9rem;
}
.diet-detail__description {
  grid-column: 1 / -1;
}
.diet-detail__text {
  white-space: pre-wrap;
}

.diet-detail__anchors,
.diet-detail__filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.diet-detail__anchors a,
.diet-detail__filters button {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  color: var(--color-primary-strong);
  background: var(--color-surface);
  font-size: 0.8rem;
  text-decoration: none;
}

.diet-detail__filters button {
  cursor: pointer;
}
.diet-detail__filters button[aria-pressed='true'] {
  background: var(--color-primary-soft);
}
.diet-detail__section {
  scroll-margin-top: calc(80px + env(safe-area-inset-top, 0px));
}
.diet-detail__recipe {
  display: grid;
  gap: var(--space-1);
}
.diet-detail__recipe > small {
  color: var(--color-text-muted);
}
.diet-detail__group {
  padding: var(--space-3);
}
.diet-detail__group h3 {
  display: flex;
}
.diet-detail__ingredients {
  margin: var(--space-3) 0 0;
  padding-left: var(--space-5);
}
.diet-detail__ingredients li + li {
  margin-top: var(--space-3);
}
.diet-detail__structure {
  margin: 0;
  padding-left: var(--space-5);
}
.diet-detail__structure li {
  padding: var(--space-2) 0;
}
.diet-detail__state {
  padding: var(--space-6) 0;
}
</style>
