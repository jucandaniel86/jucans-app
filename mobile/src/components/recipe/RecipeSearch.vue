<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { foodApi } from '@/services/foodApi'
import type { RecipeSummary } from '@/types/food'

const router = useRouter()
const route = useRoute()
const root = ref<HTMLElement | null>(null)
const query = ref('')
const results = ref<RecipeSummary[]>([])
const open = ref(false)
const loading = ref(false)
const error = ref(false)
const active = ref(-1)
const listId = useId()
const term = computed(() => query.value.trim())
const visible = computed(() => open.value && term.value.length >= 2)
let timer: ReturnType<typeof setTimeout> | undefined
let sequence = 0

function close(): void {
  open.value = false
  active.value = -1
}

function reset(): void {
  query.value = ''
  close()
}

function viewAll(): void {
  const q = term.value
  reset()
  if (q) void router.push({ name: 'recipes', query: { q } })
}

function select(recipe: RecipeSummary): void {
  reset()
  void router.push({ name: 'recipe-detail', params: { id: recipe.id } })
}

function keydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') close()
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    open.value = term.value.length >= 2
    const count = results.value.length
    if (count) {
      active.value =
        active.value < 0
          ? event.key === 'ArrowDown'
            ? 0
            : count - 1
          : (active.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count
    }
  }
  if (event.key === 'Enter') {
    event.preventDefault()
    const match = visible.value ? results.value[active.value] : undefined
    if (match) select(match)
    else viewAll()
  }
}

watch(
  query,
  () => {
    clearTimeout(timer)
    const current = ++sequence
    results.value = []
    active.value = -1
    error.value = false
    loading.value = term.value.length >= 2
    open.value = loading.value
    if (!loading.value) return
    const search = term.value
    timer = setTimeout(async () => {
      try {
        const response = await foodApi.listRecipes({ search, perPage: 5 })
        if (current === sequence) results.value = response.data
      } catch {
        if (current === sequence) error.value = true
      } finally {
        if (current === sequence) loading.value = false
      }
    }, 300)
  },
  { flush: 'sync' },
)

function outside(event: PointerEvent): void {
  if (!root.value?.contains(event.target as Node)) close()
}
function focusout(event: FocusEvent): void {
  if (!root.value?.contains(event.relatedTarget as Node | null)) close()
}
watch(() => route.fullPath, reset)
onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => {
  clearTimeout(timer)
  sequence++
  document.removeEventListener('pointerdown', outside)
})
</script>

<template>
  <div ref="root" class="recipe-search" @focusout="focusout">
    <div class="recipe-search__field">
      <span aria-hidden="true">⌕</span>
      <input
        v-model="query"
        type="search"
        role="combobox"
        aria-label="Caută rețete"
        aria-autocomplete="list"
        :aria-expanded="visible"
        :aria-controls="listId"
        :aria-activedescendant="visible && active >= 0 ? `${listId}-${active}` : undefined"
        autocomplete="off"
        placeholder="Caută o rețetă sau un ingredient..."
        @focus="open = term.length >= 2"
        @keydown="keydown"
      />
      <button v-if="query" type="button" aria-label="Șterge căutarea" @click="reset">×</button>
    </div>
    <div v-if="visible" class="recipe-search__dropdown">
      <p v-if="loading" role="status">Se caută…</p>
      <p v-else-if="error" role="status">Nu am putut căuta rețetele.</p>
      <p v-else-if="!results.length" role="status">Nicio rețetă găsită.</p>
      <div :id="listId" role="listbox" aria-label="Rețete găsite">
        <button
          v-for="(recipe, index) in results"
          :id="`${listId}-${index}`"
          :key="recipe.id"
          type="button"
          role="option"
          :aria-selected="active === index"
          @click="select(recipe)"
        >
          <img v-if="recipe.image_url" :src="recipe.image_url" alt="" />
          <span>{{ recipe.name }}</span>
        </button>
      </div>
      <button type="button" class="recipe-search__all" @click="viewAll">
        Vezi toate rezultatele
      </button>
    </div>
  </div>
</template>

<style scoped>
.recipe-search {
  position: relative;
  min-width: 0;
  z-index: 36;
}
.recipe-search__field {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 12px;
  border: 1.5px solid var(--color-border-strong);
  border-radius: 8px;
  background: var(--color-surface);
}
.recipe-search__field > span {
  color: var(--color-primary-strong);
  font-size: 1.4rem;
}
.recipe-search input {
  width: 100%;
  min-width: 0;
  height: 44px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--color-text);
  font-size: 0.86rem;
  outline: 0;
}
.recipe-search__field:focus-within {
  border-color: var(--color-primary-strong);
}
.recipe-search input::-webkit-search-cancel-button {
  display: none;
}
.recipe-search button {
  cursor: pointer;
}
.recipe-search__field button {
  flex: 0 0 32px;
  height: 36px;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.4rem;
}
.recipe-search__dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  max-height: min(360px, 55dvh);
  overflow-y: auto;
  border: 1px solid var(--color-border-strong);
  border-radius: 8px;
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}
.recipe-search__dropdown p {
  margin: 0;
  padding: 12px;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}
.recipe-search__dropdown button {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 46px;
  padding: 8px 12px;
  border: 0;
  text-align: left;
  color: var(--color-text);
  background: transparent;
  font-size: 0.86rem;
}
.recipe-search__dropdown button:hover,
.recipe-search__dropdown button[aria-selected='true'] {
  background: #e3f4ee;
}
.recipe-search__dropdown img {
  width: 36px;
  height: 36px;
  flex: 0 0 auto;
  object-fit: cover;
  border-radius: 4px;
}
.recipe-search__dropdown span {
  overflow-wrap: anywhere;
}
.recipe-search__dropdown .recipe-search__all {
  border-top: 1px solid var(--color-border);
  color: var(--color-primary-strong);
  font-weight: 700;
}
</style>
