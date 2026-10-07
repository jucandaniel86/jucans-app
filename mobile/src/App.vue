<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'

import AppLoading from '@/components/app/AppLoading.vue'
import AppNotifications from '@/components/app/AppNotifications.vue'
import { useAuthStore } from '@/stores/auth'

const MINIMUM_LOADING_TIME = 750

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const isInitialized = ref(false)
const isAuthenticated = computed(() => authStore.isAuthenticated)

async function enforceCurrentRoute(): Promise<void> {
  if (route.meta.requiresAuth && !authStore.isAuthenticated) {
    await router.replace({ name: 'login' })
  } else if (route.meta.guestOnly && authStore.isAuthenticated) {
    await router.replace({ name: 'home' })
  } else if (route.meta.requiresAdmin && !authStore.user?.is_admin) {
    await router.replace({ name: 'home' })
  }
}

onMounted(async () => {
  await Promise.all([
    authStore.restoreSession(),
    new Promise((resolve) => window.setTimeout(resolve, MINIMUM_LOADING_TIME)),
  ])

  await enforceCurrentRoute()
  isInitialized.value = true
})

watch(isAuthenticated, async () => {
  if (isInitialized.value) {
    await enforceCurrentRoute()
  }
})
</script>

<template>
  <RouterView v-slot="{ Component }">
    <Transition name="app-reveal">
      <AppLoading v-if="!isInitialized" key="loading" />
      <component :is="Component" v-else key="application" />
    </Transition>
  </RouterView>
  <AppNotifications />
</template>
