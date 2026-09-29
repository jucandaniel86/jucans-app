<script setup lang="ts">
import { useId } from 'vue'

import jucansPlaceholder from '@/assets/jucans_logo.png'

withDefaults(
  defineProps<{
    previewUrl: string | null
    hasImage: boolean
    error?: string
    disabled?: boolean
  }>(),
  {
    error: '',
    disabled: false,
  },
)

const emit = defineEmits<{
  select: [file: File]
  remove: []
}>()

const inputId = useId()

function selectFile(event: Event): void {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]

  if (file) emit('select', file)
  target.value = ''
}
</script>

<template>
  <div class="recipe-image-input">
    <span class="recipe-image-input__label">Imagine rețetă</span>
    <div class="recipe-image-input__preview" :class="{ 'recipe-image-input__preview--error': error }">
      <img
        :src="previewUrl ?? jucansPlaceholder"
        :class="{ 'recipe-image-input__placeholder': !previewUrl }"
        alt="Previzualizare imagine rețetă"
      />
    </div>

    <input
      :id="inputId"
      class="recipe-image-input__native"
      type="file"
      accept="image/jpeg,image/png,image/webp"
      :disabled="disabled"
      @change="selectFile"
    />

    <div class="recipe-image-input__actions">
      <label :for="inputId" :aria-disabled="disabled">
        <span aria-hidden="true">📷</span>
        {{ hasImage ? 'Schimbă imaginea' : 'Alege imagine' }}
      </label>
      <button v-if="hasImage" type="button" :disabled="disabled" @click="$emit('remove')">
        Șterge imaginea
      </button>
    </div>

    <p class="recipe-image-input__hint">Recomandat: 500 × 500 px</p>
    <p v-if="error" class="recipe-image-input__error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.recipe-image-input {
  display: grid;
  justify-items: start;
  gap: var(--space-2);
}

.recipe-image-input__label {
  font-size: 0.82rem;
  font-weight: 750;
}

.recipe-image-input__preview {
  width: min(100%, 240px);
  aspect-ratio: 1;
  overflow: hidden;
  border: 1.5px solid var(--color-border-strong);
  border-radius: 8px;
  background: var(--color-surface-soft);
}

.recipe-image-input__preview--error {
  border-color: var(--color-error);
}

.recipe-image-input__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.recipe-image-input__preview .recipe-image-input__placeholder {
  padding: var(--space-4);
  object-fit: contain;
}

.recipe-image-input__native {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  clip-path: inset(50%);
  white-space: nowrap;
}

.recipe-image-input__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.recipe-image-input__actions label,
.recipe-image-input__actions button {
  display: inline-flex;
  min-height: 42px;
  align-items: center;
  padding: 0 var(--space-3);
  border: 1px solid var(--color-primary);
  border-radius: 8px;
  gap: var(--space-2);
  color: var(--color-primary-strong);
  font-weight: 750;
  background: var(--color-primary-soft);
  cursor: pointer;
}

.recipe-image-input__actions button {
  border-color: var(--color-border);
  color: var(--color-error);
  background: var(--color-surface);
}

.recipe-image-input__actions [aria-disabled='true'],
.recipe-image-input__actions button:disabled {
  opacity: 0.55;
  pointer-events: none;
}

.recipe-image-input__hint,
.recipe-image-input__error {
  margin: 0;
  font-size: 0.8rem;
}

.recipe-image-input__hint {
  color: var(--color-text-muted);
}

.recipe-image-input__error {
  color: var(--color-error);
  font-weight: 650;
}
</style>
