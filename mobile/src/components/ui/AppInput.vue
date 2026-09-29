<script setup lang="ts">
import { useId } from 'vue'

withDefaults(
  defineProps<{
    modelValue: string
    label: string
    type?: 'text' | 'password' | 'url'
    error?: string
    disabled?: boolean
    autocomplete?: string
    inputmode?: 'text' | 'numeric' | 'decimal' | 'url'
    maxlength?: number
    placeholder?: string
  }>(),
  {
    type: 'text',
    error: '',
    disabled: false,
    autocomplete: 'off',
    inputmode: 'text',
    maxlength: undefined,
    placeholder: '',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const inputId = useId()

function updateValue(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
}
</script>

<template>
  <div class="app-input" :class="{ 'app-input--error': error }">
    <label class="app-input__label" :for="inputId">{{ label }}</label>
    <input
      :id="inputId"
      class="app-input__control"
      :value="modelValue"
      :type="type"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :aria-invalid="Boolean(error)"
      :aria-describedby="error ? `${inputId}-error` : undefined"
      @input="updateValue"
    />
    <p v-if="error" :id="`${inputId}-error`" class="app-input__error" role="alert">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.app-input {
  display: grid;
  gap: var(--space-2);
}

.app-input__label {
  padding-left: var(--space-1);
  color: var(--color-text);
  font-size: 0.9rem;
  font-weight: 720;
}

.app-input__control {
  width: 100%;
  min-height: 54px;
  padding: 0 var(--space-4);
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  color: var(--color-text);
  font-size: 1rem;
  background: var(--color-surface);
  transition:
    border-color var(--transition-fast),
    box-shadow var(--transition-fast),
    background-color var(--transition-fast);
}

.app-input__control:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: var(--shadow-focus);
}

.app-input__control:disabled {
  color: var(--color-text-muted);
  background: #f1f1ef;
}

.app-input--error .app-input__control {
  border-color: var(--color-error);
  background: var(--color-error-soft);
}

.app-input__error {
  margin: 0;
  padding-left: var(--space-1);
  color: var(--color-error);
  font-size: 0.82rem;
  font-weight: 620;
}
</style>
