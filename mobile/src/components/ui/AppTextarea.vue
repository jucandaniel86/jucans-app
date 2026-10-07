<script setup lang="ts">
import { useId } from 'vue'

withDefaults(
  defineProps<{
    modelValue: string | null
    label: string
    hint?: string
    error?: string
    placeholder?: string
    disabled?: boolean
    rows?: number
    maxlength?: number
  }>(),
  {
    hint: '',
    error: '',
    placeholder: '',
    disabled: false,
    rows: 4,
    maxlength: undefined,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const inputId = useId()

function updateValue(event: Event): void {
  emit('update:modelValue', (event.target as HTMLTextAreaElement).value)
}
</script>

<template>
  <div class="app-textarea" :class="{ 'app-textarea--error': error }">
    <label class="app-textarea__label" :for="inputId">{{ label }}</label>
    <p v-if="hint" :id="`${inputId}-hint`" class="app-textarea__hint">{{ hint }}</p>
    <textarea
      :id="inputId"
      class="app-textarea__control"
      :value="modelValue"
      :rows="rows"
      :disabled="disabled"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :aria-invalid="Boolean(error)"
      :aria-describedby="error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined"
      @input="updateValue"
    />
    <p v-if="error" :id="`${inputId}-error`" class="app-textarea__error" role="alert">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.app-textarea {
  display: grid;
  gap: var(--space-2);
}

.app-textarea__label {
  padding-left: var(--space-1);
  font-size: 0.9rem;
  font-weight: 720;
}

.app-textarea__hint {
  margin: calc(var(--space-1) * -1) 0 0;
  padding-left: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.84rem;
  line-height: 1.45;
}

.app-textarea__control {
  width: 100%;
  min-height: 112px;
  padding: var(--space-4);
  resize: vertical;
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  color: var(--color-text);
  font-size: 1rem;
  line-height: 1.5;
  background: var(--color-surface);
  transition:
    border-color var(--transition-fast),
    box-shadow var(--transition-fast);
}

.app-textarea__control:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: var(--shadow-focus);
}

.app-textarea__control:disabled {
  color: var(--color-text-muted);
  background: #f1f1ef;
}

.app-textarea--error .app-textarea__control {
  border-color: var(--color-error);
  background: var(--color-error-soft);
}

.app-textarea__error {
  margin: 0;
  padding-left: var(--space-1);
  color: var(--color-error);
  font-size: 0.82rem;
  font-weight: 620;
}
</style>
