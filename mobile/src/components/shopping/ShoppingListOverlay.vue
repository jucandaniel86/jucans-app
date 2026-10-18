<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import ShoppingItemGroups from '@/components/shopping/ShoppingItemGroups.vue'
import ShoppingListQuickAdd from '@/components/shopping/ShoppingListQuickAdd.vue'
import ShoppingListTabs from '@/components/shopping/ShoppingListTabs.vue'
import ShoppingListRecipes from '@/components/shopping/ShoppingListRecipes.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { shoppingListName } from '@/utils/shoppingPresentation'
import type { ShoppingListTab } from '@/types/shopping'

const emit = defineEmits<{ close: [] }>()
const shopping = useActiveShoppingListStore()
const dialog = ref<HTMLDialogElement | null>(null)
const content = ref<HTMLElement | null>(null)
const tab = ref<ShoppingListTab>('shopping')
const previousFocus = document.activeElement as HTMLElement | null
const scrollY = window.scrollY
const previousBody = {
  position: document.body.style.position,
  top: document.body.style.top,
  width: document.body.style.width,
}

onMounted(() => {
  // A fixed body also prevents background scrolling on iOS Safari.
  document.body.style.position = 'fixed'
  document.body.style.top = `-${scrollY}px`
  document.body.style.width = '100%'
  dialog.value?.showModal()
})

onBeforeUnmount(() => {
  dialog.value?.close()
  Object.assign(document.body.style, previousBody)
  window.scrollTo(0, scrollY)
  // The layout removes inert in the same update that unmounts this dialog.
  void nextTick(() => previousFocus?.focus({ preventScroll: true }))
})
</script>

<template>
  <Teleport to="body">
    <dialog
      ref="dialog"
      class="shopping-overlay"
      aria-label="Lista de cumpărături activă"
      @cancel.prevent="emit('close')"
      @click.self="emit('close')"
    >
      <section v-if="shopping.list" class="shopping-overlay__panel">
        <header class="shopping-overlay__header">
          <div>
            <strong>{{ shoppingListName(shopping.list) }}</strong>
            <small>{{ shopping.remainingSummary }}</small>
          </div>
          <button
            type="button"
            autofocus
            aria-label="Închide lista de cumpărături"
            @click="emit('close')"
          >
            ×
          </button>
        </header>
        <ShoppingListTabs
          v-model="tab"
          :items-count="shopping.list.items_count"
          :recipes-count="shopping.list.recipes_count"
          panel-id="shopping-overlay-content"
          @update:model-value="content?.scrollTo(0, 0)"
        />
        <div
          id="shopping-overlay-content"
          ref="content"
          class="shopping-overlay__content"
          tabindex="0"
          role="tabpanel"
          :aria-labelledby="`shopping-overlay-content-${tab}`"
        >
          <div v-if="shopping.error" role="alert">
            <p>{{ shopping.error }}</p>
            <button type="button" :disabled="shopping.loading" @click="shopping.load(true)">
              Încearcă din nou
            </button>
          </div>
          <ShoppingListRecipes
            v-if="tab === 'recipes'"
            :list="shopping.list"
            @open-recipe="emit('close')"
          />
          <ShoppingItemGroups v-else :items="shopping.list.items" :units="shopping.units" />
        </div>
        <ShoppingListQuickAdd v-if="tab === 'shopping'" />
      </section>
    </dialog>
  </Teleport>
</template>

<style scoped>
.shopping-overlay {
  position: fixed;
  inset: 0;
  box-sizing: border-box;
  width: 100%;
  height: 100%;
  height: 100dvh;
  max-width: none;
  max-height: none;
  margin: 0;
  padding: calc(10px + env(safe-area-inset-top, 0px)) calc(10px + env(safe-area-inset-right, 0px))
    calc(10px + env(safe-area-inset-bottom, 0px)) calc(10px + env(safe-area-inset-left, 0px));
  border: 0;
  background: transparent;
  overflow: hidden;
}

.shopping-overlay::backdrop {
  background: var(--color-overlay);
}

.shopping-overlay__panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  overflow: hidden;
  border-radius: 12px;
  background: #fff;
}

.shopping-overlay__header {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}

.shopping-overlay__header div {
  display: grid;
  min-width: 0;
  gap: var(--space-1);
  overflow-wrap: anywhere;
}

.shopping-overlay__header small {
  color: var(--color-text-muted);
}

.shopping-overlay__header button {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  font-size: 1.8rem;
  cursor: pointer;
}

.shopping-overlay__panel > :deep(.shopping-tabs) {
  flex-shrink: 0;
}

.shopping-overlay__content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: var(--space-3) var(--space-4);
}

.shopping-overlay__panel > :deep(.shopping-quick-add) {
  flex-shrink: 0;
  max-height: 50%;
  overflow-y: auto;
}
</style>
