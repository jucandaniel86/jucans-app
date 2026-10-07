<script setup lang="ts">
import ShoppingListVisibilityBadge from '@/components/shopping/ShoppingListVisibilityBadge.vue'
import type { ShoppingListSummary } from '@/types/shopping'
defineProps<{ list: ShoppingListSummary }>()
</script>

<template>
  <ShoppingListVisibilityBadge class="shopping-access" :list="list" show-creator />
</template>

<style scoped>
.shopping-access {
  overflow-wrap: anywhere;
}
</style>
