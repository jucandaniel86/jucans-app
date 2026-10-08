<script
  setup
  lang="ts"
  generic="
    T extends {
      id: string | number
    }
  "
>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

//props
const props = withDefaults(
  defineProps<{
    search: (term: string) => Promise<T[]>
    getLabel: (item: T) => string
    errorMessage?: string
    noResults?: string
    searchText?: string
    showAllResults?: boolean
    placeholder?: string
    minLength?: number
    ariaLabel?: string
  }>(),
  {
    errorMessage: 'Nu am putut căuta rețetele.',
    noResults: 'Nicio rețetă găsită.',
    searchText: 'Se caută…',
    showAllResults: false,
    placeholder: 'Caută o rețetă sau un ingredient...',
    minLength: 2,
    ariaLabel: 'Cauta',
  },
)

//emitters
const emits = defineEmits<{
  select: [item: T]
  viewAll: [term: string]
}>()

const root = ref<HTMLElement | null>(null)
const query = ref('')
const results = ref<T[]>([])
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
  if (q) emits('viewAll', q)
}

function select(selectedItem: T): void {
  reset()
  emits('select', selectedItem)
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
    if (match) select(match as T)
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
    const searchTerm = term.value
    timer = setTimeout(async () => {
      try {
        const response = await props.search(searchTerm)

        if (current === sequence) {
          results.value = response
        }
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

onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => {
  clearTimeout(timer)
  sequence++
  document.removeEventListener('pointerdown', outside)
})
</script>

<template>
  <div ref="root" class="app-autocomplete-search" @focusout="focusout">
    <div class="app-autocomplete-search__field">
      <span aria-hidden="true">⌕</span>
      <input
        v-model="query"
        type="search"
        role="combobox"
        :ariaLabel="props.ariaLabel"
        aria-autocomplete="list"
        :aria-expanded="visible"
        :aria-controls="listId"
        :aria-activedescendant="visible && active >= 0 ? `${listId}-${active}` : undefined"
        autocomplete="off"
        :placeholder="props.placeholder"
        @focus="open = term.length >= 2"
        @keydown="keydown"
      />
      <button v-if="query" type="button" aria-label="Șterge căutarea" @click="reset">×</button>
    </div>
    <div v-if="visible" class="app-autocomplete-search__dropdown">
      <p v-if="loading" role="status">{{ props.searchText }}</p>
      <p v-else-if="error" role="status">{{ props.errorMessage }}</p>
      <p v-else-if="Array.isArray(results) && !results.length" role="status">
        {{ props.noResults }}
      </p>
      <div :id="listId" role="listbox" aria-label="Rezultate găsite">
        <button
          v-for="(item, index) in results"
          :id="`${listId}-${index}`"
          :key="item.id"
          type="button"
          role="option"
          :aria-selected="active === index"
          @click="select(item as T)"
        >
          <slot name="item" :item="item" :index="index">
            {{ props.getLabel(item as T) }}
          </slot>
        </button>
      </div>
      <button v-if="props.showAllResults" type="button" class="recipe-search__all" @click="viewAll">
        Vezi toate rezultatele
      </button>
    </div>
  </div>
</template>

<style scoped>
@import url(./styles/AppAutocomplete.style.css);
</style>
