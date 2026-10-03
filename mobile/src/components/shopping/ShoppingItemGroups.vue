<script setup lang="ts">
import { computed } from 'vue'
import type { FoodUnit } from '@/types/food'
import type { ShoppingItem } from '@/types/shopping'
import ShoppingItemRow from '@/components/shopping/ShoppingItemRow.vue'
import { groupShoppingItems } from '@/utils/shoppingPresentation'

const props = defineProps<{
  items: ShoppingItem[]
  units: Record<string, FoodUnit>
  readonly?: boolean
}>()
const groups = computed(() => groupShoppingItems(props.items.filter((item) => !item.is_checked)))
const checkedItems = computed(() =>
  props.items.filter((item) => item.is_checked).sort((a, b) => a.name.localeCompare(b.name, 'ro')),
)
</script>

<template>
  <div class="shopping-groups">
    <section
      v-for="group in groups"
      :key="group.category?.id ?? 'other'"
      class="shopping-list__group"
    >
      <h2>
        <span aria-hidden="true">{{ group.category ? group.category.emoji : '📦' }}</span>
        {{ group.category?.name ?? 'Diverse' }}
      </h2>
      <ul>
        <ShoppingItemRow
          v-for="item in group.items"
          :key="item.id"
          :item="item"
          :units="units"
          :readonly="readonly"
        />
      </ul>
    </section>
    <section v-if="checkedItems.length" class="shopping-list__group shopping-list__purchased">
      <h2><span aria-hidden="true">✓</span> Cumpărate</h2>
      <ul>
        <ShoppingItemRow
          v-for="item in checkedItems"
          :key="item.id"
          :item="item"
          :units="units"
          :readonly="readonly"
        />
      </ul>
    </section>
  </div>
</template>

<style scoped>
.shopping-groups {
  display: grid;
  gap: var(--space-6);
}
.shopping-list__group h2 {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  padding-bottom: var(--space-2);
  border-bottom: 2px solid var(--color-primary-soft);
  font-size: 0.8rem;
  font-weight: 800;
  text-transform: uppercase;
  overflow-wrap: anywhere;
}
.shopping-list__group h2 span {
  flex-shrink: 0;
  font-size: 1.1rem;
}
.shopping-list__group ul {
  margin: 0;
  padding: 0;
  list-style: none;
}
</style>
