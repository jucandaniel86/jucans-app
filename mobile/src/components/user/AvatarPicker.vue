<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

import UserAvatar from '@/components/user/UserAvatar.vue'
import { AVATARS } from '@/config/avatars'

defineProps<{
  selected: string | null
  saving: boolean
  error: string
}>()

const emit = defineEmits<{
  close: []
  select: [avatar: string]
}>()

const closeButton = ref<HTMLButtonElement | null>(null)

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(async () => {
  document.addEventListener('keydown', handleKeydown)
  await nextTick()
  closeButton.value?.focus()
})

onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown))
</script>

<template>
  <Teleport to="body">
    <div class="avatar-picker" role="presentation" @click.self="$emit('close')">
      <section
        class="avatar-picker__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="avatar-picker-title"
      >
        <header class="avatar-picker__header">
          <div>
            <p>Profil</p>
            <h2 id="avatar-picker-title">Alege avatarul</h2>
          </div>
          <button
            ref="closeButton"
            class="avatar-picker__close"
            type="button"
            aria-label="Închide selectorul de avatar"
            @click="$emit('close')"
          >
            <span aria-hidden="true">×</span>
          </button>
        </header>

        <div class="avatar-picker__grid" role="list" aria-label="Avataruri disponibile">
          <button
            v-for="option in AVATARS"
            :key="option.id"
            class="avatar-picker__option"
            :class="{ 'avatar-picker__option--selected': option.id === selected }"
            type="button"
            role="listitem"
            :aria-label="option.label"
            :aria-pressed="option.id === selected"
            :disabled="saving"
            @click="$emit('select', option.id)"
          >
            <UserAvatar :avatar="option.id" :username="option.label" size="large" />
          </button>
        </div>

        <p v-if="saving" class="avatar-picker__status" aria-live="polite">Se salvează…</p>
        <p v-else-if="error" class="avatar-picker__error" role="alert">{{ error }}</p>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.avatar-picker {
  position: fixed;
  z-index: 80;
  inset: 0;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: var(--space-4);
  background: var(--color-overlay);
}

.avatar-picker__panel {
  width: min(100%, 420px);
  padding: var(--space-5);
  border-radius: 8px;
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
}

.avatar-picker__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-5);
}

.avatar-picker__header p,
.avatar-picker__header h2 {
  margin: 0;
}

.avatar-picker__header p {
  color: var(--color-primary-strong);
  font-size: 0.75rem;
  font-weight: 800;
  text-transform: uppercase;
}

.avatar-picker__header h2 {
  margin-top: 2px;
  font-size: 1.2rem;
}

.avatar-picker__close {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  color: var(--color-text-muted);
  font-size: 1.7rem;
  background: var(--color-surface-soft);
  cursor: pointer;
}

.avatar-picker__grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: var(--space-3);
}

.avatar-picker__option {
  display: grid;
  min-width: 0;
  aspect-ratio: 1;
  place-items: center;
  padding: 3px;
  border: 2px solid transparent;
  border-radius: 50%;
  background: transparent;
  cursor: pointer;
  transition:
    transform var(--transition-fast),
    border-color var(--transition-fast),
    background-color var(--transition-fast);
}

.avatar-picker__option--selected {
  border-color: var(--color-pink);
  background: var(--color-pink-soft);
  transform: scale(1.08);
}

.avatar-picker__option:disabled {
  opacity: 0.65;
  cursor: wait;
}

.avatar-picker__status,
.avatar-picker__error {
  min-height: 20px;
  margin: var(--space-4) 0 0;
  font-size: 0.85rem;
  font-weight: 700;
  text-align: center;
}

.avatar-picker__status {
  color: var(--color-text-muted);
}

.avatar-picker__error {
  color: var(--color-error);
}

@media (min-width: 560px) {
  .avatar-picker {
    align-items: center;
  }
}

@media (prefers-reduced-motion: reduce) {
  .avatar-picker__option--selected {
    transform: none;
  }
}
</style>
