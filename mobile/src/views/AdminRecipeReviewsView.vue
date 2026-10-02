<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppButton from '@/components/ui/AppButton.vue'
import { foodApi } from '@/services/foodApi'
import type { PaginationMeta, RecipeIngredientReviewItem } from '@/types/food'

const route = useRoute()
const router = useRouter()
const reviews = ref<RecipeIngredientReviewItem[]>([])
const pagination = ref<PaginationMeta | null>(null)
const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const loadMoreSentinel = ref<HTMLElement | null>(null)
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0
let loadMoreObserver: IntersectionObserver | null = null

const searchTerm = computed(() => search.value.trim())
const total = computed(() => pagination.value?.total ?? 0)
const canLoadMore = computed(() =>
  Boolean(pagination.value && pagination.value.current_page < pagination.value.last_page),
)
const totalLabel = computed(() => {
  const noun = total.value === 1 ? 'ingredient' : 'ingrediente'
  return `${total.value} ${noun} de verificat`
})

function displayQuantity(value: string | null): string {
  if (value === null) return 'Fără cantitate'

  const numericValue = Number(value)
  return Number.isFinite(numericValue)
    ? new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 3 }).format(numericValue)
    : value
}

function displayUnit(unit: string | null): string {
  return unit ?? 'Fără unitate'
}

function storedMeasurement(review: RecipeIngredientReviewItem): string {
  return `${displayQuantity(review.value)} · ${displayUnit(review.unit)}`
}

function hasUnitMismatch(review: RecipeIngredientReviewItem): boolean {
  return review.unit !== review.ingredient.default_unit
}

async function loadReviews(page = 1, append = false): Promise<void> {
  const sequence = ++requestSequence

  if (append) loadingMore.value = true
  else loading.value = true
  error.value = ''

  try {
    const response = await foodApi.listRecipeIngredientReviews({
      search: searchTerm.value || undefined,
      page,
      perPage: 20,
    })

    if (sequence !== requestSequence) return

    reviews.value = append ? [...reviews.value, ...response.data] : response.data
    pagination.value = response.meta
  } catch {
    if (sequence === requestSequence) {
      error.value = 'Nu am putut încărca ingredientele de verificat.'

      if (!append) {
        reviews.value = []
        pagination.value = null
      }
    }
  } finally {
    if (sequence === requestSequence) {
      loading.value = false
      loadingMore.value = false

      if (append) {
        await nextTick()
        observeLoadMoreSentinel()
      }
    }
  }
}

function resetAndLoadReviews(): void {
  pagination.value = null
  loadMoreObserver?.disconnect()
  void loadReviews()
}

function loadNextPage(): void {
  if (!canLoadMore.value || loading.value || loadingMore.value) return
  void loadReviews((pagination.value?.current_page ?? 0) + 1, true)
}

function observeLoadMoreSentinel(): void {
  if (!loadMoreObserver || !loadMoreSentinel.value) return
  loadMoreObserver.disconnect()
  loadMoreObserver.observe(loadMoreSentinel.value)
}

function correctReview(review: RecipeIngredientReviewItem): void {
  void router.push({
    name: 'recipe-edit',
    params: { id: review.recipe.id },
    query: {
      returnTo: 'admin-recipe-reviews',
      reviewIngredient: review.ingredient.id,
      ...(searchTerm.value ? { reviewSearch: searchTerm.value } : {}),
    },
  })
}

watch(search, () => {
  window.clearTimeout(debounceTimer)
  debounceTimer = window.setTimeout(() => {
    void router.replace({
      name: 'admin-recipe-reviews',
      query: searchTerm.value ? { search: searchTerm.value } : {},
    })
    resetAndLoadReviews()
  }, 350)
})

watch(loadMoreSentinel, observeLoadMoreSentinel)

onMounted(() => {
  if (typeof IntersectionObserver !== 'undefined') {
    loadMoreObserver = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) loadNextPage()
      },
      { rootMargin: '240px 0px' },
    )
  }

  void loadReviews()
})

onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer)
  loadMoreObserver?.disconnect()
  requestSequence++
})
</script>

<template>
  <section class="recipe-reviews">
    <header class="recipe-reviews__header">
      <p>Administration</p>
      <h1>Verificare rețete</h1>
      <span v-if="pagination && !loading" aria-live="polite">{{ totalLabel }}</span>
    </header>

    <label class="recipe-reviews__search">
      <span aria-hidden="true">⌕</span>
      <span class="visually-hidden">Caută în verificările rețetelor</span>
      <input
        v-model="search"
        type="search"
        autocomplete="off"
        placeholder="Caută ingredient, rețetă sau text..."
      />
    </label>

    <div v-if="loading" class="recipe-reviews__skeletons" aria-label="Se încarcă verificările">
      <div v-for="index in 4" :key="index" />
    </div>

    <div v-else-if="error && reviews.length === 0" class="recipe-reviews__state" role="alert">
      <strong>Lista nu s-a încărcat.</strong>
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="loadReviews()">Încearcă din nou</AppButton>
    </div>

    <div v-else-if="reviews.length === 0" class="recipe-reviews__state">
      <strong>{{ searchTerm ? 'Nicio verificare găsită.' : 'Totul este verificat.' }}</strong>
      <p v-if="!searchTerm">Nu există ingrediente de rețetă care necesită corectare.</p>
    </div>

    <template v-else>
      <div class="recipe-reviews__list">
        <article
          v-for="review in reviews"
          :key="review.id"
          class="review-row"
          :class="{ 'review-row--mismatch': hasUnitMismatch(review) }"
        >
          <div class="review-row__main">
            <strong>{{ review.ingredient.name }}</strong>
            <span>{{ review.recipe.name }}</span>
            <p>{{ review.raw_text || 'Fără text original' }}</p>
          </div>

          <dl class="review-row__units">
            <div>
              <dt>Salvat</dt>
              <dd>{{ storedMeasurement(review) }}</dd>
            </div>
            <div>
              <dt>Canonic</dt>
              <dd>{{ displayUnit(review.ingredient.default_unit) }}</dd>
            </div>
          </dl>

          <button type="button" class="review-row__correct" @click="correctReview(review)">
            Corectează
          </button>
        </article>
      </div>

      <p v-if="error" class="recipe-reviews__more-error" role="alert">{{ error }}</p>
      <div
        v-if="canLoadMore"
        ref="loadMoreSentinel"
        class="recipe-reviews__sentinel"
        aria-hidden="true"
      />
      <p v-if="loadingMore" class="recipe-reviews__loading-more" aria-live="polite">
        Se încarcă mai multe verificări…
      </p>
    </template>
  </section>
</template>

<style scoped>
.recipe-reviews {
  display: grid;
  gap: var(--space-4);
}

.recipe-reviews__header p,
.recipe-reviews__header h1 {
  margin: 0;
}

.recipe-reviews__header p {
  margin-bottom: var(--space-1);
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.recipe-reviews__header h1 {
  font-size: 1.75rem;
  line-height: 1.15;
}

.recipe-reviews__header span {
  display: block;
  margin-top: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.86rem;
  font-weight: 720;
}

.recipe-reviews__search {
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

.recipe-reviews__search > span:first-child {
  color: var(--color-primary-strong);
  font-size: 1.4rem;
  font-weight: 800;
}

.recipe-reviews__search input {
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

.recipe-reviews__list {
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
}

.review-row {
  display: grid;
  min-height: 112px;
  padding: var(--space-3);
  border-bottom: 1px solid var(--color-border);
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--space-3);
}

.review-row:last-child {
  border-bottom: 0;
}

.review-row--mismatch {
  box-shadow: inset 3px 0 var(--color-yellow);
}

.review-row__main {
  display: grid;
  min-width: 0;
  gap: 2px;
}

.review-row__main strong,
.review-row__main span,
.review-row__main p {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.review-row__main strong {
  font-size: 0.94rem;
}

.review-row__main span {
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 700;
}

.review-row__main p {
  margin: var(--space-1) 0 0;
  color: var(--color-text-muted);
  font-size: 0.76rem;
}

.review-row__units {
  display: grid;
  min-width: 132px;
  margin: 0;
  gap: var(--space-1);
}

.review-row__units div {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr);
  gap: var(--space-1);
}

.review-row__units dt {
  color: var(--color-text-muted);
  font-size: 0.68rem;
  font-weight: 700;
}

.review-row__units dd {
  margin: 0;
  font-size: 0.72rem;
  font-weight: 780;
}

.review-row--mismatch .review-row__units dd {
  color: #72510c;
}

.review-row__correct {
  min-height: 36px;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-primary-strong);
  border-radius: 7px;
  grid-column: 2;
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 800;
  background: var(--color-surface);
  cursor: pointer;
}

.recipe-reviews__state {
  display: grid;
  min-height: 260px;
  place-content: center;
  justify-items: center;
  gap: var(--space-3);
  text-align: center;
}

.recipe-reviews__state p,
.recipe-reviews__more-error,
.recipe-reviews__loading-more {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.recipe-reviews__skeletons {
  display: grid;
  gap: 1px;
  overflow: hidden;
  border-radius: 8px;
}

.recipe-reviews__skeletons div {
  height: 112px;
  background: var(--color-surface);
}

.recipe-reviews__more-error {
  color: var(--color-error);
  font-weight: 680;
  text-align: center;
}

.recipe-reviews__sentinel {
  height: 1px;
}

.recipe-reviews__loading-more {
  font-weight: 680;
  text-align: center;
}

@media (max-width: 480px) {
  .review-row {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .review-row__units {
    min-width: 0;
    grid-column: 1;
  }

  .review-row__correct {
    grid-row: 1 / span 2;
  }
}
</style>
