<script setup lang="ts">
import { useId } from 'vue'

export interface SelectOption {
  value: string
  label: string
}

withDefaults(
  defineProps<{
    modelValue: string
    label: string
    options: SelectOption[]
    error?: string
    disabled?: boolean
  }>(),
  {
    error: '',
    disabled: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const inputId = useId()

function updateValue(event: Event): void {
  emit('update:modelValue', (event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="app-select" :class="{ 'app-select--error': error }">
    <label class="app-select__label" :for="inputId">{{ label }}</label>
    <div class="app-select__wrap">
      <select
        :id="inputId"
        class="app-select__control"
        :value="modelValue"
        :disabled="disabled"
        :aria-invalid="Boolean(error)"
        :aria-describedby="error ? `${inputId}-error` : undefined"
        @change="updateValue"
      >
        <option v-for="option in options" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
      <span class="app-select__arrow" aria-hidden="true">⌄</span>
    </div>
    <p v-if="error" :id="`${inputId}-error`" class="app-select__error" role="alert">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.app-select {
  display: grid;
  gap: var(--space-2);
}

.app-select__label {
  padding-left: var(--space-1);
  font-size: 0.82rem;
  font-weight: 720;
}

.app-select__wrap {
  position: relative;
}

.app-select__control {
  width: 100%;
  min-height: 48px;
  padding: 0 40px 0 var(--space-3);
  appearance: none;
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  color: var(--color-text);
  font-size: 0.92rem;
  background: var(--color-surface);
}

.app-select__control:focus {
  border-color: var(--color-primary);
  outline: none;
  box-shadow: var(--shadow-focus);
}

.app-select__arrow {
  position: absolute;
  top: 50%;
  right: var(--space-3);
  color: var(--color-text-muted);
  font-size: 1.2rem;
  pointer-events: none;
  transform: translateY(-58%);
}

.app-select--error .app-select__control {
  border-color: var(--color-error);
}

.app-select__error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.78rem;
  font-weight: 620;
}
</style>
