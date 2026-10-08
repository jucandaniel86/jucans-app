<script setup lang="ts">
withDefaults(
  defineProps<{
    type?: 'button' | 'submit' | 'reset'
    variant?: 'primary' | 'secondary'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  {
    type: 'button',
    variant: 'primary',
    loading: false,
    disabled: false,
    block: false,
  },
)
</script>

<template>
  <button
    class="app-button"
    :class="[`app-button--${variant}`, { 'app-button--block': block }]"
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading"
  >
    <span v-if="loading" class="app-button__spinner" aria-hidden="true" />
    <span class="app-button__label" :class="{ 'app-button__label--hidden': loading }">
      <slot />
    </span>
  </button>
</template>

<style scoped>
.app-button {
  position: relative;
  display: inline-flex;
  min-height: 52px;
  align-items: center;
  justify-content: center;
  padding: 0 var(--space-6);
  border: 1px solid transparent;
  border-radius: var(--radius-md);
  font-weight: 750;
  letter-spacing: 0;
  cursor: pointer;
  transition:
    transform var(--transition-fast),
    background-color var(--transition-fast),
    box-shadow var(--transition-fast);
}

.app-button--primary {
  color: #fff;
  background: var(--color-primary-strong);
  box-shadow: 0 8px 18px rgb(8 127 138 / 22%);
}

.app-button--secondary {
  color: var(--color-text);
  background: var(--color-surface);
  border-color: var(--color-border);
}

.app-button--block {
  width: 100%;
}

.app-button:not(:disabled):active {
  transform: scale(0.97);
}

.app-button:disabled {
  cursor: not-allowed;
  opacity: 0.58;
  box-shadow: none;
}

.app-button__label--hidden {
  visibility: hidden;
}

.app-button__label {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: var(--space-1);
}

.app-button__spinner {
  position: absolute;
  width: 22px;
  height: 22px;
  border: 3px solid rgb(255 255 255 / 42%);
  border-top-color: #fff;
  border-radius: 50%;
  animation: button-spin 700ms linear infinite;
}

@keyframes button-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
