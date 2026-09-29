<script setup lang="ts">
import UserAvatar from '@/components/user/UserAvatar.vue'

defineProps<{
  username: string
  avatar: string | null
}>()

defineEmits<{
  openMenu: []
}>()
</script>

<template>
  <header class="app-header">
    <div class="app-header__inner">
      <button
        class="app-header__menu"
        type="button"
        aria-label="Deschide meniul"
        @click="$emit('openMenu')"
      >
        <span />
        <span />
        <span />
      </button>

      <div class="app-header__brand" aria-label="Jucans">
        Jucans<span aria-hidden="true">.</span>
      </div>

      <span class="app-header__username">
        <UserAvatar :avatar="avatar" :username="username" size="small" />
        {{ username }}
      </span>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  position: sticky;
  z-index: 20;
  top: 0;
  padding-top: env(safe-area-inset-top);
  border-bottom: 1px solid rgb(230 225 216 / 72%);
  background: rgb(255 248 236 / 92%);
  backdrop-filter: blur(16px);
}

.app-header__inner {
  display: grid;
  width: min(100%, 640px);
  min-height: 62px;
  margin: 0 auto;
  padding: 0 var(--space-4);
  grid-template-columns: 48px minmax(0, 1fr) minmax(60px, auto);
  align-items: center;
}

.app-header__menu {
  display: grid;
  width: 44px;
  height: 44px;
  place-content: center;
  gap: 5px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  cursor: pointer;
  transition:
    transform var(--transition-fast),
    background-color var(--transition-fast);
}

.app-header__menu span {
  width: 21px;
  height: 2.5px;
  border-radius: var(--radius-pill);
  background: var(--color-text);
}

.app-header__menu:hover {
  background: var(--color-primary-soft);
}

.app-header__menu:active {
  transform: scale(0.93);
}

.app-header__brand {
  justify-self: start;
  font-size: 1.32rem;
  font-weight: 850;
  letter-spacing: 0;
}

.app-header__brand span {
  color: var(--color-pink);
}

.app-header__username {
  max-width: 110px;
  overflow: hidden;
  color: var(--color-text-muted);
  font-size: 0.88rem;
  font-weight: 700;
  text-align: right;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
}
</style>
