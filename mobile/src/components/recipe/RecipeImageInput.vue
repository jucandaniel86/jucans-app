<script setup lang="ts">
import AppImageInput from '@/components/ui/AppImageInput.vue'

const props = withDefaults(
  defineProps<{
    previewUrl: string | null
    hasImage: boolean
    error?: string
    disabled?: boolean
  }>(),
  { error: '', disabled: false },
)

const emit = defineEmits<{
  select: [file: File]
  remove: []
}>()
</script>

<template>
  <AppImageInput
    v-bind="props"
    label="Imagine rețetă"
    alt-text="Previzualizare imagine rețetă"
    @select="emit('select', $event)"
    @remove="emit('remove')"
  />
</template>
