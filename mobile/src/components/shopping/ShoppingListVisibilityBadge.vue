<script setup lang="ts">
import type { ShoppingListSummary } from '@/types/shopping'
import { shoppingListCreatorLabel, shoppingListVisibilityLabel } from '@/utils/shoppingPresentation'

withDefaults(
  defineProps<{
    list: Pick<ShoppingListSummary, 'visibility' | 'is_creator' | 'is_shared_with_me' | 'creator'>
    showCreator?: boolean
  }>(),
  { showCreator: false },
)

const icons = { private: '🔒', shared: '👥', public: '🌐' }
</script>

<template>
  <span class="shopping-visibility" :class="`shopping-visibility--${list.visibility}`">
    <span class="shopping-visibility__badge">
      <span aria-hidden="true">{{ icons[list.visibility] }}</span>
      <span>{{ shoppingListVisibilityLabel(list) }}</span>
    </span>
    <span v-if="showCreator" class="shopping-visibility__creator">
      · {{ shoppingListCreatorLabel(list) }}
    </span>
  </span>
</template>

<style scoped>
.shopping-visibility {
  --visibility-bg: var(--color-primary-soft);
  --visibility-border: rgb(18 174 184 / 30%);
  --visibility-text: var(--color-primary-strong);
  display: inline-flex;
  min-width: 0;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.8rem;
  line-height: 1.2;
}
.shopping-visibility--private {
  --visibility-bg: var(--color-error-soft);
  --visibility-border: rgb(196 61 85 / 22%);
  --visibility-text: var(--color-error);
}
.shopping-visibility--shared {
  --visibility-bg: #e7f8ef;
  --visibility-border: rgb(38 140 105 / 22%);
  --visibility-text: var(--color-success);
}
.shopping-visibility--public {
  --visibility-bg: #e8f5ff;
  --visibility-border: rgb(8 127 138 / 20%);
  --visibility-text: var(--color-primary-strong);
}
.shopping-visibility__badge {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  max-width: 100%;
  padding: 3px var(--space-2);
  border: 1px solid var(--visibility-border);
  border-radius: var(--radius-pill);
  color: var(--visibility-text);
  background: var(--visibility-bg);
  font-weight: 750;
}
.shopping-visibility__creator {
  min-width: 0;
  overflow-wrap: anywhere;
}
</style>
