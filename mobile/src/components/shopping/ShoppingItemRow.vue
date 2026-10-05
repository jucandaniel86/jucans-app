<script setup lang="ts">
import { nextTick } from 'vue'
import type { FoodUnit } from '@/types/food'
import type { ShoppingItem } from '@/types/shopping'
import { quantityLabel } from '@/utils/recipePresentation'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'

const props = defineProps<{
  item: ShoppingItem
  units: Record<string, FoodUnit>
  readonly?: boolean
}>()
const shopping = useActiveShoppingListStore()

async function changeChecked(event: Event): Promise<void> {
  if (props.readonly) return
  const input = event.target as HTMLInputElement
  const hadFocus = document.activeElement === input
  await shopping.setChecked(props.item.id, input.checked)
  await nextTick()
  if (hadFocus && document.activeElement === document.body) {
    document.getElementById(`shopping-item-${props.item.id}`)?.focus()
  }
}
</script>

<template>
  <li class="shopping-item" :class="{ 'shopping-item--checked': item.is_checked }">
    <label
      class="shopping-item__control"
      :aria-busy="shopping.pendingChecks[item.id] !== undefined"
    >
      <input
        :id="`shopping-item-${item.id}`"
        type="checkbox"
        :checked="item.is_checked"
        :disabled="
          readonly ||
          shopping.closing ||
          shopping.removingRecipeId !== null ||
          shopping.pendingChecks[item.id] !== undefined
        "
        :aria-label="`${item.is_checked ? 'Marchează ca necumpărat' : 'Marchează ca cumpărat'}: ${item.name}`"
        @change="changeChecked"
      />
      <strong>{{ item.name }}</strong>
      <span
        v-if="item.quantity !== null || item.unit === 'to_taste'"
        class="shopping-list__quantity"
        >{{ quantityLabel(item.quantity, item.unit, units) }}</span
      >
      <small v-if="item.sources?.length" class="shopping-list__sources"
        >{{ item.sources.length }} {{ item.sources.length === 1 ? 'rețetă' : 'rețete' }}</small
      >
    </label>
    <p v-if="!readonly && shopping.itemErrors[item.id]" class="shopping-item__error" role="alert">
      {{ shopping.itemErrors[item.id] }}
    </p>
  </li>
</template>

<style scoped>
.shopping-item + .shopping-item {
  border-top: 1px solid var(--color-border);
}
.shopping-item__control {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) minmax(0, auto);
  align-items: center;
  gap: var(--space-2);
  min-height: 52px;
  padding: var(--space-2) 0;
  cursor: pointer;
}
.shopping-item__control input {
  margin: 0;
  width: 22px;
  height: 22px;
  accent-color: var(--color-primary-strong);
  cursor: pointer;
}
.shopping-item__control[aria-busy='true'] {
  cursor: wait;
}
.shopping-item__control strong {
  font-size: 0.96rem;
  font-weight: 650;
  overflow-wrap: anywhere;
}
.shopping-item--checked strong {
  font-weight: 500;
  text-decoration: line-through;
  color: var(--color-text-muted);
}
.shopping-item--checked .shopping-list__quantity {
  color: var(--color-text-muted);
}
.shopping-list__quantity {
  text-align: right;
  font-size: 0.92rem;
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
.shopping-list__sources {
  grid-column: 2 / -1;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
.shopping-item__error {
  margin: 0 0 var(--space-2) 32px;
  color: var(--color-error);
  font-size: 0.78rem;
  line-height: 1.4;
}
</style>
