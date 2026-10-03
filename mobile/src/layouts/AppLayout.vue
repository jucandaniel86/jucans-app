<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'

import AppDrawer from '@/components/app/AppDrawer.vue'
import AppHeader from '@/components/app/AppHeader.vue'
import { useAuthStore } from '@/stores/auth'
import FloatingShoppingList from '@/components/shopping/FloatingShoppingList.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'

const authStore = useAuthStore()
const shopping = useActiveShoppingListStore()
const route = useRoute()
const shoppingExpanded = ref(false)
const showShopping = computed(
  () =>
    shopping.hasItems &&
    !drawerOpen.value &&
    route.name !== 'shopping-list' &&
    !route.meta.shoppingDetail &&
    !route.meta.requiresAdmin,
)
const drawerOpen = ref(false)
const loggingOut = ref(false)
const updatingAvatar = ref(false)
const avatarError = ref('')
const username = computed(() => authStore.user?.username ?? '')
const avatar = computed(() => authStore.user?.avatar ?? null)

const previousOverflow = document.body.style.overflow
watch([drawerOpen, shoppingExpanded], ([drawer, expanded]) => {
  document.body.style.overflow = drawer || expanded ? 'hidden' : previousOverflow
})
watch(
  () => route.fullPath,
  () => {
    shoppingExpanded.value = false
  },
)
watch(drawerOpen, () => {
  shoppingExpanded.value = false
})
onMounted(() => shopping.load())

onBeforeUnmount(() => {
  document.body.style.overflow = previousOverflow
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
    <div
      class="app-layout__page"
      :inert="drawerOpen || shoppingExpanded ? true : undefined"
      :aria-hidden="drawerOpen || shoppingExpanded"
    >
      <AppHeader :username="username" :avatar="avatar" @open-menu="drawerOpen = true" />
      <main class="app-layout__content" :class="{ 'app-layout__content--shopping': showShopping }">
        <RouterView />
      </main>
    </div>

    <FloatingShoppingList v-if="showShopping" v-model:expanded="shoppingExpanded" />

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

.app-layout__content--shopping {
  padding-bottom: calc(104px + env(safe-area-inset-bottom, 0px));
}

@media (max-width: 359px) {
  .app-layout__content {
    padding-right: var(--space-4);
    padding-left: var(--space-4);
  }
}
</style>
