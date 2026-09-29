<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import AppButton from '@/components/ui/AppButton.vue'
import AppCard from '@/components/ui/AppCard.vue'
import { foodApi } from '@/services/foodApi'
import { useAuthStore } from '@/stores/auth'
import type { FoodTag } from '@/types/food'

const VISIBLE_TAG_LIMIT = 6

const authStore = useAuthStore()
const router = useRouter()
const tags = ref<FoodTag[]>([])
const selectedTagIds = ref<number[]>([])
const matchingCount = ref<number | null>(null)
const showAllTags = ref(false)
const loading = ref(true)
const counting = ref(false)
const choosing = ref(false)
const error = ref('')
let countTimer: ReturnType<typeof setTimeout> | undefined
let countRequest = 0

const displayName = computed(() => {
  const username = authStore.user?.username ?? ''
  return username ? username.charAt(0).toLocaleUpperCase('ro-RO') + username.slice(1) : ''
})
const visibleTags = computed(() =>
  showAllTags.value ? tags.value : tags.value.slice(0, VISIBLE_TAG_LIMIT),
)
const hasMoreTags = computed(() => tags.value.length > VISIBLE_TAG_LIMIT)
const countLabel = computed(() => {
  if (counting.value || matchingCount.value === null) return 'Verificăm rețetele...'
  if (matchingCount.value === 0) return 'Nicio rețetă 😅'
  return matchingCount.value === 1
    ? '1 rețetă disponibilă'
    : `${matchingCount.value} rețete disponibile`
})
const randomLabel = computed(() =>
  matchingCount.value && matchingCount.value > 0
    ? `🎲 Alege dintre ${matchingCount.value} ${matchingCount.value === 1 ? 'rețetă' : 'rețete'}`
    : '🎲 Alege aleator',
)

function toggleTag(tagId: number): void {
  selectedTagIds.value = selectedTagIds.value.includes(tagId)
    ? selectedTagIds.value.filter((id) => id !== tagId)
    : [...selectedTagIds.value, tagId]
}

async function loadCount(): Promise<void> {
  const request = ++countRequest
  counting.value = true
  error.value = ''

  try {
    const response = await foodApi.listRecipes({
      tags: selectedTagIds.value,
      page: 1,
      perPage: 1,
    })
    if (request === countRequest) matchingCount.value = response.meta.total
  } catch {
    if (request === countRequest) {
      matchingCount.value = null
      error.value = 'Nu am putut verifica rețetele.'
    }
  } finally {
    if (request === countRequest) counting.value = false
  }
}

async function loadWidget(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const [tagResponse, recipeResponse] = await Promise.all([
      foodApi.listTags(),
      foodApi.listRecipes({ page: 1, perPage: 1 }),
    ])
    tags.value = tagResponse.data
    matchingCount.value = recipeResponse.meta.total
  } catch {
    tags.value = []
    matchingCount.value = null
    error.value = 'Nu am putut încărca opțiunile.'
  } finally {
    loading.value = false
  }
}

async function chooseRandom(): Promise<void> {
  if (!matchingCount.value || choosing.value) return
  choosing.value = true
  error.value = ''

  try {
    const response = await foodApi.randomRecipe(selectedTagIds.value)
    if (!response.data) {
      matchingCount.value = 0
      return
    }

    await router.push({
      name: 'recipe-random',
      query: {
        recipe: String(response.data.id),
        ...(selectedTagIds.value.length ? { tags: selectedTagIds.value.join(',') } : {}),
      },
    })
  } catch {
    error.value = 'Nu am putut alege o rețetă. Încearcă din nou.'
  } finally {
    choosing.value = false
  }
}

watch(selectedTagIds, () => {
  window.clearTimeout(countTimer)
  countTimer = window.setTimeout(loadCount, 300)
})

onMounted(loadWidget)
onBeforeUnmount(() => {
  window.clearTimeout(countTimer)
  countRequest++
})
</script>

<template>
  <section class="home-view">
    <header class="home-view__welcome">
      <p>Bună, {{ displayName }}! <span aria-hidden="true">👋</span></p>
      <h1>Ce gătim azi?</h1>
    </header>

    <AppCard class="food-card">
      <header class="food-card__header">
        <span class="food-card__eyebrow"><span aria-hidden="true">🍳</span> Ce gătim azi?</span>
        <h2>Alege ce ai chef să gătești</h2>
      </header>

      <div v-if="loading" class="food-card__loading" aria-label="Se încarcă opțiunile">
        <span v-for="index in 5" :key="index" />
      </div>

      <div v-else-if="tags.length" class="food-card__tags" aria-label="Filtrează după taguri">
        <button
          v-for="tag in visibleTags"
          :key="tag.id"
          type="button"
          :class="{ 'food-card__tag--selected': selectedTagIds.includes(tag.id) }"
          :aria-pressed="selectedTagIds.includes(tag.id)"
          @click="toggleTag(tag.id)"
        >
          <span v-if="tag.emoji" aria-hidden="true">{{ tag.emoji }}</span>
          {{ tag.name }} ({{ tag.recipe_count ?? 0 }})
        </button>
        <button
          v-if="hasMoreTags"
          type="button"
          class="food-card__more"
          :aria-expanded="showAllTags"
          @click="showAllTags = !showAllTags"
        >
          {{ showAllTags ? 'Mai puține' : '+ Mai multe' }}
        </button>
      </div>

      <p v-else-if="!error" class="food-card__empty">Nu există încă taguri pentru rețete.</p>

      <div class="food-card__action">
        <p aria-live="polite">{{ countLabel }}</p>
        <AppButton
          block
          :loading="choosing"
          :disabled="loading || counting || matchingCount === null || matchingCount === 0"
          @click="chooseRandom"
        >
          {{ randomLabel }}
        </AppButton>
        <p v-if="error" class="food-card__error" role="alert">{{ error }}</p>
      </div>
    </AppCard>
  </section>
</template>

<style scoped>
.home-view {
  display: grid;
  gap: var(--space-8);
}

.home-view__welcome p {
  margin: 0 0 var(--space-2);
  color: var(--color-text-muted);
  font-size: 1rem;
  font-weight: 680;
}

.home-view__welcome h1 {
  margin: 0;
  font-size: 2.35rem;
  line-height: 1.08;
  letter-spacing: 0;
}

.food-card {
  position: relative;
  display: grid;
  padding: var(--space-6);
  overflow: hidden;
  gap: var(--space-5);
  background: var(--color-surface);
}

.food-card::before {
  position: absolute;
  inset: 0 0 auto;
  height: 7px;
  background: linear-gradient(
    90deg,
    var(--color-primary) 0 52%,
    var(--color-yellow) 52% 74%,
    var(--color-pink) 74%
  );
  content: '';
}

.food-card__header h2 {
  margin: var(--space-3) 0 0;
  font-size: 1.35rem;
  line-height: 1.25;
  letter-spacing: 0;
}

.food-card__eyebrow {
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.food-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.food-card__tags button {
  min-height: 42px;
  padding: 0 var(--space-3);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-pill);
  color: var(--color-text);
  font-size: 0.84rem;
  font-weight: 720;
  letter-spacing: 0;
  background: var(--color-yellow-soft);
  cursor: pointer;
  transition:
    border-color var(--transition-fast),
    background-color var(--transition-fast),
    color var(--transition-fast);
}

.food-card__tags .food-card__tag--selected {
  border-color: var(--color-primary-strong);
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
  box-shadow: inset 0 0 0 1px var(--color-primary-strong);
}

.food-card__tags .food-card__more {
  color: var(--color-primary-strong);
  background: var(--color-surface);
}

.food-card__loading {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.food-card__loading span {
  width: 112px;
  height: 42px;
  border-radius: var(--radius-pill);
  background: var(--color-background-strong);
}

.food-card__action {
  display: grid;
  gap: var(--space-3);
  text-align: center;
}

.food-card__action p,
.food-card__empty {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  font-weight: 700;
}

.food-card__action .food-card__error {
  color: var(--color-error);
  font-weight: 650;
}

@media (max-width: 359px) {
  .home-view__welcome h1 {
    font-size: 1.9rem;
  }

  .food-card {
    padding: var(--space-5) var(--space-4);
  }
}
</style>
