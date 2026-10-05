<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import ShoppingItemGroups from '@/components/shopping/ShoppingItemGroups.vue'
import ShoppingListQuickAdd from '@/components/shopping/ShoppingListQuickAdd.vue'
import ShoppingListTabs from '@/components/shopping/ShoppingListTabs.vue'
import ShoppingListRecipes from '@/components/shopping/ShoppingListRecipes.vue'
import type { ShoppingListTab } from '@/types/shopping'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'

const props = defineProps<{ expanded: boolean }>()
const emit = defineEmits<{
  'update:expanded': [value: boolean]
  'occupied-height': [value: number]
}>()
const shopping = useActiveShoppingListStore()
const tab = ref<ShoppingListTab>('shopping')
const content = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const toggle = ref<HTMLButtonElement | null>(null)
let observer: ResizeObserver | undefined

onMounted(() => {
  if (!panel.value || typeof ResizeObserver === 'undefined') return
  observer = new ResizeObserver(() => {
    if (!props.expanded && panel.value)
      emit('occupied-height', panel.value.getBoundingClientRect().height)
  })
  observer.observe(panel.value)
})

function collapse(): void {
  emit('update:expanded', false)
}
function keydown(event: KeyboardEvent): void {
  if (!props.expanded) return
  if (event.key === 'Escape') {
    event.preventDefault()
    collapse()
  }
  if (event.key === 'Tab') {
    const controls = panel.value?.querySelectorAll<HTMLElement>(
      'button:not(:disabled), a[href], input:not(:disabled), [tabindex="0"]',
    )
    const first = controls?.[0]
    const last = controls?.[controls.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last?.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first?.focus()
    }
  }
}

watch(
  () => props.expanded,
  async () => {
    tab.value = 'shopping'
    await nextTick()
    toggle.value?.focus()
  },
)
watch(tab, () => {
  if (content.value) content.value.scrollTop = 0
})
onBeforeUnmount(() => {
  observer?.disconnect()
  if (props.expanded) collapse()
})
</script>

<template>
  <template v-if="shopping.hasItems || (expanded && shopping.list)">
    <button
      v-if="expanded"
      class="floating-shopping__backdrop"
      type="button"
      tabindex="-1"
      aria-label="Restrânge lista"
      @click="collapse"
    />
    <section
      ref="panel"
      class="floating-shopping"
      :class="{ 'floating-shopping--expanded': expanded }"
      :role="expanded ? 'dialog' : undefined"
      :aria-modal="expanded ? true : undefined"
      aria-label="Lista de cumpărături activă"
      @keydown="keydown"
    >
      <ShoppingListQuickAdd v-if="!expanded" class="floating-shopping__quick-add--external" />
      <button
        ref="toggle"
        class="floating-shopping__toggle"
        type="button"
        :aria-expanded="expanded"
        :aria-controls="expanded ? 'shopping-quick-preview' : undefined"
        @click="emit('update:expanded', !expanded)"
      >
        <span class="floating-shopping__icon" aria-hidden="true">🛒</span>
        <span class="floating-shopping__heading"
          ><strong>Lista de cumpărături</strong><small>{{ shopping.remainingSummary }}</small></span
        >
        <span class="floating-shopping__chevron" aria-hidden="true">{{
          expanded ? '⌄' : '⌃'
        }}</span>
      </button>
      <template v-if="expanded">
        <ShoppingListTabs
          v-model="tab"
          :items-count="shopping.list!.items_count"
          :recipes-count="shopping.list!.recipes_count"
          panel-id="shopping-quick-preview"
        />
        <div
          ref="content"
          id="shopping-quick-preview"
          class="floating-shopping__items"
          tabindex="0"
          role="tabpanel"
          :aria-labelledby="`shopping-quick-preview-${tab}`"
        >
          <div v-if="shopping.error" class="floating-shopping__error" role="alert">
            <p>{{ shopping.error }}</p>
            <button type="button" :disabled="shopping.loading" @click="shopping.load(true)">
              Încearcă din nou
            </button>
          </div>
          <ShoppingListRecipes
            v-if="tab === 'recipes'"
            :list="shopping.list!"
            @open-recipe="collapse"
          />
          <ShoppingItemGroups v-else :items="shopping.list!.items" :units="shopping.units" />
        </div>
        <ShoppingListQuickAdd v-if="tab === 'shopping'" />
        <RouterLink
          class="floating-shopping__open"
          :to="{ name: 'shopping-list' }"
          @click="collapse"
          >Deschide lista completă <span aria-hidden="true">→</span></RouterLink
        >
      </template>
    </section>
  </template>
</template>

<style scoped>
.floating-shopping__backdrop {
  position: fixed;
  inset: 0;
  z-index: 30;
  border: 0;
  background: var(--color-overlay);
}
.floating-shopping {
  position: fixed;
  z-index: 35;
  bottom: calc(var(--space-3) + env(safe-area-inset-bottom, 0px));
  left: 50%;
  width: min(calc(100% - 24px), 600px);
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
}
.floating-shopping--expanded {
  max-height: 65vh;
  max-height: 65dvh;
}
.floating-shopping__quick-add--external {
  border-top: 0;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-primary-soft);
}
.floating-shopping:has(.shopping-quick-add form) {
  max-height: calc(
    100dvh - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px) - 24px
  );
}
.floating-shopping :deep(.shopping-quick-add) {
  max-height: calc(
    100dvh - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px) - 180px
  );
  overflow-y: auto;
}
.floating-shopping__toggle {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: var(--space-3);
  width: 100%;
  min-height: 72px;
  padding: var(--space-3) var(--space-4);
  border: 0;
  text-align: left;
  background: var(--color-surface);
  cursor: pointer;
}
.floating-shopping__icon {
  flex-shrink: 0;
  font-size: 1.4rem;
}
.floating-shopping__heading {
  min-width: 0;
  display: grid;
  gap: var(--space-1);
}
.floating-shopping__heading strong {
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.floating-shopping__heading small {
  font-size: 0.78rem;
  color: var(--color-text-muted);
}
.floating-shopping__chevron {
  margin-left: auto;
  flex-shrink: 0;
  color: var(--color-primary-strong);
  font-size: 1.4rem;
}
.floating-shopping__items {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
}
.floating-shopping__open {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  min-height: 48px;
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
  color: var(--color-primary-strong);
  font-size: 0.87rem;
  font-weight: 750;
  text-decoration: none;
  background: var(--color-primary-soft);
}
.floating-shopping__error {
  color: var(--color-error);
  font-size: 0.8rem;
  margin-bottom: var(--space-3);
}
.floating-shopping__error p {
  margin: 0;
}
.floating-shopping__error button {
  min-height: 44px;
  border: 0;
  padding: 0;
  color: var(--color-primary-strong);
  background: transparent;
  font-weight: 700;
  cursor: pointer;
}
</style>
