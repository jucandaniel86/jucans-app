<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import { shoppingApi } from '@/services/shoppingApi'
import type { ShoppingListSummary } from '@/types/shopping'
import type { PaginationMeta } from '@/types/food'
import { shoppingListName, shoppingListSummary } from '@/utils/shoppingPresentation'

const lists = ref<ShoppingListSummary[]>([])
const pagination = ref<PaginationMeta | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const sections = computed(() =>
  [
    { title: 'Listă activă', lists: lists.value.filter((list) => list.status === 'open') },
    { title: 'Istoric', lists: lists.value.filter((list) => list.status === 'closed') },
  ].filter((section) => section.lists.length),
)

async function load(page = 1): Promise<void> {
  if (loadingMore.value) return
  if (page === 1) loading.value = true
  else loadingMore.value = true
  error.value = ''
  try {
    const response = await shoppingApi.list(page)
    lists.value = page === 1 ? response.data : [...lists.value, ...response.data]
    pagination.value = response.meta
  } catch {
    error.value = 'Nu am putut încărca listele de cumpărături. Încearcă din nou.'
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}
onMounted(() => load())
</script>

<template>
  <section class="shopping-lists">
    <h1>Liste de cumpărături</h1>
    <p v-if="loading" role="status">Se încarcă listele…</p>
    <div v-else-if="!lists.length && !error" class="shopping-lists__empty">
      <span aria-hidden="true">🛒</span>
      <h2>Nu ai încă liste de cumpărături.</h2>
      <p>Adaugă o rețetă pentru a începe o listă nouă.</p>
      <RouterLink :to="{ name: 'recipes' }">Vezi rețetele</RouterLink>
    </div>
    <template v-else>
      <section v-for="section in sections" :key="section.title" class="shopping-lists__section">
        <h2>{{ section.title }}</h2>
        <RouterLink
          v-for="list in section.lists"
          :key="list.id"
          class="shopping-lists__row"
          :class="{ 'shopping-lists__row--active': list.status === 'open' }"
          :to="{ name: 'shopping-list-detail', params: { id: list.id } }"
        >
          <div>
            <strong>{{ shoppingListName(list) }}</strong
            ><small>{{ shoppingListSummary(list) }}</small
            ><small v-if="list.status === 'open'" class="shopping-lists__remaining"
              >{{ list.unchecked_items_count }} de cumpărat</small
            >
          </div>
          <span aria-hidden="true">›</span>
        </RouterLink>
      </section>
      <AppButton
        v-if="pagination && pagination.current_page < pagination.last_page"
        variant="secondary"
        :loading="loadingMore"
        @click="load(pagination!.current_page + 1)"
        >Mai multe liste</AppButton
      >
    </template>
    <div v-if="error" role="alert">
      <p>{{ error }}</p>
      <AppButton
        variant="secondary"
        @click="load(lists.length ? (pagination?.current_page ?? 0) + 1 : 1)"
        >Încearcă din nou</AppButton
      >
    </div>
  </section>
</template>

<style scoped>
.shopping-lists {
  display: grid;
  gap: var(--space-6);
}
.shopping-lists h1 {
  margin: 0;
  font-size: 1.65rem;
  line-height: 1.2;
  overflow-wrap: anywhere;
}
.shopping-lists__section h2 {
  margin: 0 0 var(--space-2);
  color: var(--color-text-muted);
  text-transform: uppercase;
  font-size: 0.75rem;
}
.shopping-lists__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  min-height: 76px;
  padding: var(--space-3) 0;
  border-bottom: 1px solid var(--color-border);
  text-decoration: none;
}
.shopping-lists__row--active {
  border-left: 3px solid var(--color-primary);
  padding-left: var(--space-3);
}
.shopping-lists__row div {
  min-width: 0;
  display: grid;
  gap: var(--space-1);
}
.shopping-lists__row strong {
  font-size: 1rem;
  overflow-wrap: anywhere;
}
.shopping-lists__row small {
  font-size: 0.82rem;
  color: var(--color-text-muted);
}
.shopping-lists__row .shopping-lists__remaining {
  color: var(--color-primary-strong);
  font-weight: 650;
}
.shopping-lists__row > span {
  color: var(--color-primary-strong);
  font-size: 1.5rem;
}
.shopping-lists__empty {
  display: grid;
  justify-items: center;
  gap: var(--space-3);
  padding: var(--space-10) 0;
  text-align: center;
}
.shopping-lists__empty h2 {
  margin: 0;
  font-size: 1.1rem;
}
.shopping-lists__empty p {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
.shopping-lists__empty > span {
  font-size: 2rem;
}
.shopping-lists__empty a {
  padding: var(--space-3);
  color: var(--color-primary-strong);
  font-weight: 700;
}
</style>
