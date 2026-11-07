<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { shoppingApi } from '@/services/shoppingApi'
import type { ShoppingCategory } from '@/types/food'
const props = defineProps<{ modelValue: number | null; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()
const categories = ref<ShoppingCategory[]>([])
const error = ref('')
async function load(): Promise<void> {
  error.value = ''
  try {
    categories.value = (await shoppingApi.categories()).data
  } catch {
    error.value = 'Categoriile nu au putut fi încărcate.'
  }
}
onMounted(load)
</script>
<template>
  <div class="shopping-category-select">
    <AppSelect
      :model-value="props.modelValue ?? ''"
      label="Categorie"
      :disabled="disabled"
      :error="error"
      :options="[
        { value: '', label: 'Fără categorie' },
        ...categories.map((category) => ({
          value: String(category.id),
          label: `${category.emoji ?? ''} ${category.name}`.trim(),
        })),
      ]"
      @update:model-value="emit('update:modelValue', $event ? Number($event) : null)"
    />
    <button v-if="error" type="button" :disabled="disabled" @click="load">
      Reîncarcă categoriile
    </button>
  </div>
</template>
<style scoped>
.shopping-category-select {
  grid-column: 1 / -1;
  min-width: 0;
}
.shopping-category-select :deep(.app-select) {
  gap: var(--space-1);
}
.shopping-category-select :deep(select) {
  min-height: 44px;
  border-radius: 4px;
}
button {
  border: 0;
  background: transparent;
  color: var(--color-primary-strong);
  min-height: 44px;
}
</style>
