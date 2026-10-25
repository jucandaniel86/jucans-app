<script setup lang="ts">
defineProps<{ modelValue: 'calendar' | 'list'; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: 'calendar' | 'list'] }>()
</script>

<template>
  <div class="task-view-switch" role="group" aria-label="Mod afișare taskuri">
    <button
      type="button"
      aria-label="Afișează lista de taskuri"
      title="Listă"
      :aria-pressed="modelValue === 'list'"
      :disabled="disabled"
      @click="emit('update:modelValue', 'list')"
    >
      <svg
        aria-hidden="true"
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
      >
        <path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" />
      </svg>
    </button>
    <button
      type="button"
      aria-label="Afișează calendarul săptămânal"
      title="Calendar"
      :aria-pressed="modelValue === 'calendar'"
      :disabled="disabled"
      @click="emit('update:modelValue', 'calendar')"
    >
      <svg
        aria-hidden="true"
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
      >
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M16 3v4M8 3v4M3 11h18M8 15h.01M12 15h.01M16 15h.01" />
      </svg>
    </button>
  </div>
</template>
