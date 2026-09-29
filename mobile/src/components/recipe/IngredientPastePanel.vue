<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'

defineProps<{
  modelValue: string
  processing: boolean
  error: string
}>()

defineEmits<{
  'update:modelValue': [value: string]
  process: []
}>()
</script>

<template>
  <div class="paste-panel">
    <AppTextarea
      :model-value="modelValue"
      label="Lipește lista aici"
      hint="Câte un ingredient pe fiecare rând. Textul original va fi păstrat."
      placeholder="500 g carne tocata&#10;2 oua&#10;o legatura de patrunjel"
      :rows="8"
      :maxlength="50000"
      :error="error"
      :disabled="processing"
      @update:model-value="$emit('update:modelValue', $event)"
    />
    <AppButton block :loading="processing" @click="$emit('process')">
      <span aria-hidden="true">✨</span> Procesează ingredientele
    </AppButton>
  </div>
</template>

<style scoped>
.paste-panel {
  display: grid;
  gap: var(--space-4);
}

.paste-panel :deep(textarea) {
  min-height: 220px;
  font-family: inherit;
}
</style>
