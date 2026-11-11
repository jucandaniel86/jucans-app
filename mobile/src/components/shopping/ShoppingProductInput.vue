<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { shoppingApi } from '@/services/shoppingApi'
import type { ShoppingItemSuggestion } from '@/types/shopping'

const props = defineProps<{ modelValue: string; disabled?: boolean; required?: boolean }>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
  select: [value: ShoppingItemSuggestion]
}>()
const id = useId()
const input = ref<HTMLInputElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const portal = ref<HTMLElement | null>(null)
const results = ref<ShoppingItemSuggestion[]>([])
const open = ref(false)
const loading = ref(false)
const error = ref(false)
const active = ref(-1)
const style = ref<Record<string, string>>({})
const visible = computed(() => open.value && !props.disabled && !!props.modelValue.trim())
let timer: ReturnType<typeof setTimeout> | undefined
let sequence = 0
const selecting = ref(false)
let disposed = false

function close(): void {
  open.value = false
  active.value = -1
  selecting.value = false
  clearTimeout(timer)
  sequence++
  loading.value = false
}
async function position(): Promise<void> {
  await nextTick()
  if (disposed || !visible.value || !input.value || !menu.value) return
  const rect = input.value.getBoundingClientRect()
  const viewport = window.visualViewport
  const top = viewport?.offsetTop ?? 0
  const bottom = top + (viewport?.height ?? window.innerHeight)
  const below = Math.max(0, bottom - rect.bottom - 8)
  const above = Math.max(0, rect.top - top - 8)
  const upwards = below < 96 && above > below
  const height = Math.min(192, Math.max(0, bottom - top - 16), upwards ? above : below)
  const actualHeight = Math.min(height, menu.value.scrollHeight)
  const desiredTop = upwards ? rect.top - actualHeight - 4 : rect.bottom + 4
  const menuTop = Math.max(top + 4, Math.min(desiredTop, bottom - actualHeight - 4))
  const parent = menu.value.offsetParent as HTMLElement | null
  const origin = parent?.getBoundingClientRect()
  style.value = {
    left: `${rect.left - (origin?.left ?? 0) + (parent?.scrollLeft ?? window.scrollX) - (parent?.clientLeft ?? 0)}px`,
    top: `${menuTop - (origin?.top ?? 0) + (parent?.scrollTop ?? window.scrollY) - (parent?.clientTop ?? 0)}px`,
    width: `${rect.width}px`,
    maxHeight: `${height}px`,
  }
}
function search(): void {
  clearTimeout(timer)
  const current = ++sequence
  results.value = []
  active.value = -1
  error.value = false
  const term = props.modelValue.trim()
  loading.value = !!term && !props.disabled
  open.value = loading.value
  if (!loading.value) return
  timer = setTimeout(async () => {
    try {
      const response = await shoppingApi.suggestions(term)
      if (current === sequence) results.value = response.data
    } catch {
      if (current === sequence) error.value = true
    } finally {
      if (current === sequence) {
        loading.value = false
        void position()
      }
    }
  }, 280)
  void position()
}
function select(value: ShoppingItemSuggestion): void {
  // Responses only update results. Metadata changes exclusively on explicit selection.
  emit('update:modelValue', value.name)
  emit('select', value)
  void nextTick(() => {
    input.value?.focus({ preventScroll: true })
    close()
  })
}
function outside(event: PointerEvent): void {
  const target = event.target as Node
  if (!input.value?.contains(target) && !menu.value?.contains(target)) close()
}
function blur(event: FocusEvent): void {
  if (!selecting.value && !menu.value?.contains(event.relatedTarget as Node | null)) close()
}
async function keydown(event: KeyboardEvent): Promise<void> {
  if (event.key === 'Escape') {
    if (visible.value) event.preventDefault()
    close()
    return
  }
  if (event.key === 'Enter' && visible.value && active.value >= 0) {
    const value = results.value[active.value]
    if (value) {
      event.preventDefault()
      select(value)
    }
  }
  if (['ArrowDown', 'ArrowUp'].includes(event.key) && results.value.length) {
    event.preventDefault()
    open.value = true
    active.value =
      active.value < 0
        ? event.key === 'ArrowDown'
          ? 0
          : results.value.length - 1
        : (active.value + (event.key === 'ArrowDown' ? 1 : results.value.length - 1)) %
          results.value.length
    await nextTick()
    const option = menu.value?.querySelector<HTMLElement>(`[id="${id}-option-${active.value}"]`)
    if (menu.value && option) {
      const offset = option.offsetTop
      if (offset < menu.value.scrollTop) menu.value.scrollTop = offset
      else if (offset + option.offsetHeight > menu.value.scrollTop + menu.value.clientHeight)
        menu.value.scrollTop = offset + option.offsetHeight - menu.value.clientHeight
    }
  }
}
watch(() => props.modelValue, search)
watch(
  () => props.disabled,
  (value) => {
    if (value) close()
  },
)
onMounted(() => {
  // Keep suggestions in the active modal when present, outside scrolling/clipping
  // ancestors of the form. Absolute coordinates stay anchored to the product input.
  portal.value = input.value?.closest('dialog') ?? document.body
  document.addEventListener('pointerdown', outside)
  window.addEventListener('resize', position)
  document.addEventListener('scroll', position, true)
  window.visualViewport?.addEventListener('resize', position)
  window.visualViewport?.addEventListener('scroll', position)
})
onBeforeUnmount(() => {
  disposed = true
  close()
  document.removeEventListener('pointerdown', outside)
  window.removeEventListener('resize', position)
  document.removeEventListener('scroll', position, true)
  window.visualViewport?.removeEventListener('resize', position)
  window.visualViewport?.removeEventListener('scroll', position)
})
defineExpose({ focus: () => input.value?.focus({ preventScroll: true }) })
</script>
<template>
  <label class="shopping-product" :for="id">
    Produs
    <input
      :id="id"
      ref="input"
      :value="modelValue"
      :disabled="disabled"
      :required="required"
      name="name"
      autocomplete="off"
      role="combobox"
      aria-autocomplete="list"
      :aria-expanded="visible"
      :aria-controls="`${id}-results`"
      :aria-activedescendant="visible && active >= 0 ? `${id}-option-${active}` : undefined"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      @focus="search"
      @blur="blur"
      @keydown="keydown"
    />
  </label>
  <Teleport v-if="portal" :to="portal">
    <div
      v-if="visible"
      ref="menu"
      class="shopping-product-results"
      :style="style"
      @pointerdown="selecting = true"
      @pointercancel="selecting = false"
      @pointerup="selecting = false"
    >
      <p v-if="loading" role="status">Se caută…</p>
      <p v-else-if="error" role="status">
        Sugestiile nu sunt disponibile. Poți introduce produsul manual.
      </p>
      <p v-else-if="!results.length" role="status">Nicio sugestie. Poți salva un produs nou.</p>
      <div :id="`${id}-results`" role="listbox" aria-label="Sugestii produse">
        <button
          v-for="(result, index) in results"
          :id="`${id}-option-${index}`"
          :key="`${result.source}-${result.name}`"
          type="button"
          role="option"
          tabindex="-1"
          :aria-selected="active === index"
          @pointerdown="$event.pointerType === 'mouse' && $event.preventDefault()"
          @click="select(result)"
        >
          <strong>{{ result.name }}</strong>
          <small v-if="result.unit || result.category">{{
            [result.unit, result.category?.name].filter(Boolean).join(' · ')
          }}</small>
        </button>
      </div>
    </div>
  </Teleport>
</template>
<style scoped>
.shopping-product {
  position: relative;
  display: grid;
  gap: var(--space-1);
  min-width: 0;
  font-size: 0.8rem;
  font-weight: 650;
}
input {
  width: 100%;
  min-width: 0;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: 4px;
  color: var(--color-text);
  background: var(--color-surface);
  font-size: 16px;
}
.shopping-product-results {
  position: absolute;
  z-index: 10000;
  overflow-y: auto;
  overscroll-behavior: contain;
  box-sizing: border-box;
  margin: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: 4px;
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-sm);
}
.shopping-product-results button {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 2px;
  width: 100%;
  height: 48px;
  padding: 4px 10px;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
  touch-action: manipulation;
}
.shopping-product-results strong {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.85rem;
}
.shopping-product-results small {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
.shopping-product-results p {
  padding: 8px;
  margin: 0;
  font-size: 0.8rem;
}
.shopping-product-results button[aria-selected='true'] {
  background: var(--color-primary-soft);
}

@media (hover: hover) and (pointer: fine) {
  .shopping-product-results button:hover {
    background: var(--color-primary-soft);
  }
}

</style>
