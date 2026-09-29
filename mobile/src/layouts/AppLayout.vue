<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterView } from 'vue-router'

import AppDrawer from '@/components/app/AppDrawer.vue'
import AppHeader from '@/components/app/AppHeader.vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const drawerOpen = ref(false)
const loggingOut = ref(false)
const updatingAvatar = ref(false)
const avatarError = ref('')
const username = computed(() => authStore.user?.username ?? '')
const avatar = computed(() => authStore.user?.avatar ?? null)

watch(drawerOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

async function logout(): Promise<void> {
  loggingOut.value = true

  try {
    await authStore.logout()
    drawerOpen.value = false
  } finally {
    loggingOut.value = false
  }
}

async function updateAvatar(nextAvatar: string): Promise<void> {
  if (nextAvatar === avatar.value || updatingAvatar.value) return

  updatingAvatar.value = true
  avatarError.value = ''

  try {
    await authStore.updateAvatar(nextAvatar)
  } catch {
    avatarError.value = 'Nu am putut salva avatarul. Încearcă din nou.'
  } finally {
    updatingAvatar.value = false
  }
}
</script>

<template>
  <div class="app-layout">
    <div class="app-layout__page" :inert="drawerOpen ? true : undefined" :aria-hidden="drawerOpen">
      <AppHeader :username="username" :avatar="avatar" @open-menu="drawerOpen = true" />
      <main class="app-layout__content">
        <RouterView />
      </main>
    </div>

    <AppDrawer
      :open="drawerOpen"
      :username="username"
      :avatar="avatar"
      :logging-out="loggingOut"
      :updating-avatar="updatingAvatar"
      :avatar-error="avatarError"
      @close="drawerOpen = false"
      @logout="logout"
      @update-avatar="updateAvatar"
    />
  </div>
</template>

<style scoped>
.app-layout,
.app-layout__page {
  min-height: 100vh;
  min-height: 100dvh;
}

.app-layout__page {
  background: var(--color-background);
}

.app-layout__content {
  width: min(100%, 640px);
  margin: 0 auto;
  padding: var(--space-6) var(--space-5) max(var(--space-8), env(safe-area-inset-bottom));
}

@media (max-width: 359px) {
  .app-layout__content {
    padding-right: var(--space-4);
    padding-left: var(--space-4);
  }
}
</style>
