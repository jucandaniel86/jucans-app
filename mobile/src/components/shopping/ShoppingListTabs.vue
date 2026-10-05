<script setup lang="ts">
import { nextTick } from 'vue'
import type { ShoppingListTab } from '@/types/shopping'

const props = defineProps<{
  modelValue: ShoppingListTab
  itemsCount: number
  recipesCount: number
  panelId: string
}>()
const emit = defineEmits<{ 'update:modelValue': [tab: ShoppingListTab] }>()

async function keydown(event: KeyboardEvent): Promise<void> {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const tab =
    event.key === 'Home'
      ? 'shopping'
      : event.key === 'End'
        ? 'recipes'
        : props.modelValue === 'shopping'
          ? 'recipes'
          : 'shopping'
  emit('update:modelValue', tab)
  await nextTick()
  document.getElementById(`${props.panelId}-${tab}`)?.focus()
}
</script>

<template>
  <div class="shopping-tabs" role="tablist" aria-label="Conținutul listei" @keydown="keydown">
    <button
      :id="`${panelId}-shopping`"
      type="button"
      role="tab"
      :aria-selected="modelValue === 'shopping'"
      :aria-controls="panelId"
      :tabindex="modelValue === 'shopping' ? 0 : -1"
      @click="emit('update:modelValue', 'shopping')"
    >
      <span>🛒 Cumpărături</span> <span>({{ itemsCount }})</span>
    </button>
    <button
      :id="`${panelId}-recipes`"
      type="button"
      role="tab"
      :aria-selected="modelValue === 'recipes'"
      :aria-controls="panelId"
      :tabindex="modelValue === 'recipes' ? 0 : -1"
      @click="emit('update:modelValue', 'recipes')"
    >
      <span>🍳 Rețete</span> <span>({{ recipesCount }})</span>
    </button>
  </div>
</template>

<style scoped>
.shopping-tabs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  flex-shrink: 0;
  border-bottom: 1px solid var(--color-border);
}
.shopping-tabs button {
  display: flex;
  flex-wrap: wrap;
  align-content: center;
  align-items: center;
  justify-content: center;
  gap: var(--space-1);
  height: 48px;
  min-width: 0;
  padding: var(--space-1) var(--space-2);
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 750;
  line-height: 1.2;
  cursor: pointer;
}
.shopping-tabs button[aria-selected='true'] {
  border-bottom-color: var(--color-primary-strong);
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
}
</style>
