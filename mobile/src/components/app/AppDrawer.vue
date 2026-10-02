<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import AvatarPicker from '@/components/user/AvatarPicker.vue'
import UserAvatar from '@/components/user/UserAvatar.vue'
import { MENU_SECTIONS, type MenuItem } from '@/config/menu'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  open: boolean
  username: string
  avatar: string | null
  loggingOut: boolean
  updatingAvatar: boolean
  avatarError: string
}>()

const emit = defineEmits<{
  close: []
  logout: []
  updateAvatar: [avatar: string]
}>()

const closeButton = ref<HTMLButtonElement | null>(null)
const pickerOpen = ref(false)
const authStore = useAuthStore()

function canAccessMenuItem(item: MenuItem): boolean {
  return Boolean(item.routeName && !item.soon && (!item.restricted || authStore.user?.is_admin))
}

function handleKeydown(event: KeyboardEvent): void {
  if (pickerOpen.value && event.key === 'Escape') {
    pickerOpen.value = false
  } else if (props.open && event.key === 'Escape') {
    emit('close')
  }
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      await nextTick()
      closeButton.value?.focus()
    } else {
      pickerOpen.value = false
    }
  },
)

onMounted(() => document.addEventListener('keydown', handleKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown))
</script>

<template>
  <Transition name="drawer-backdrop">
    <button
      v-if="open"
      class="drawer-backdrop"
      type="button"
      aria-label="Închide meniul"
      @click="$emit('close')"
    />
  </Transition>

  <aside
    class="app-drawer"
    :class="{ 'app-drawer--open': open }"
    :aria-hidden="!open"
    :inert="open ? undefined : true"
    aria-label="Navigare principală"
  >
    <header class="app-drawer__header">
      <div class="app-drawer__brand">Jucans<span>.</span></div>
      <button
        ref="closeButton"
        class="app-drawer__close"
        type="button"
        aria-label="Închide meniul"
        @click="$emit('close')"
      >
        <span aria-hidden="true">×</span>
      </button>
    </header>

    <nav class="app-drawer__nav" aria-label="Secțiuni">
      <template v-for="section in MENU_SECTIONS" :key="section.id">
        <div v-if="section.dividerBefore" class="app-drawer__divider" />

        <div class="app-drawer__section" :class="{ 'app-drawer__section--grouped': section.label }">
          <p v-if="section.label" class="app-drawer__label">{{ section.label }}</p>

          <template v-for="item in section.items" :key="item.id">
            <RouterLink
              v-if="item.routeName && canAccessMenuItem(item)"
              class="drawer-link"
              :to="{ name: item.routeName }"
              @click="$emit('close')"
            >
              <span class="drawer-link__mark" :class="item.markerClass" aria-hidden="true" />
              <span>{{ item.label }}</span>
            </RouterLink>

            <button v-else class="drawer-link" type="button" disabled>
              <span class="drawer-link__mark" :class="item.markerClass" aria-hidden="true" />
              <span>{{ item.label }}</span>
              <span v-if="item.soon" class="drawer-link__soon">Curând</span>
            </button>
          </template>
        </div>
      </template>
    </nav>

    <footer class="app-drawer__footer">
      <button class="app-drawer__user" type="button" @click="pickerOpen = true">
        <UserAvatar :avatar="avatar" :username="username" size="medium" />
        <span class="app-drawer__username">{{ username }}</span>
        <span class="app-drawer__user-action" aria-hidden="true">›</span>
      </button>
      <button
        class="app-drawer__logout"
        type="button"
        :disabled="loggingOut"
        @click="$emit('logout')"
      >
        {{ loggingOut ? 'Se iese…' : 'Logout' }}
      </button>
    </footer>

    <AvatarPicker
      v-if="pickerOpen"
      :selected="avatar"
      :saving="updatingAvatar"
      :error="avatarError"
      @close="pickerOpen = false"
      @select="$emit('updateAvatar', $event)"
    />
  </aside>
</template>

<style scoped>
.drawer-backdrop {
  position: fixed;
  z-index: 40;
  inset: 0;
  width: 100%;
  height: 100%;
  padding: 0;
  border: 0;
  background: var(--color-overlay);
  cursor: default;
}

.drawer-backdrop-enter-active,
.drawer-backdrop-leave-active {
  transition: opacity var(--transition-base);
}

.drawer-backdrop-enter-from,
.drawer-backdrop-leave-to {
  opacity: 0;
}

.app-drawer {
  position: fixed;
  z-index: 50;
  inset: 0 auto 0 0;
  display: flex;
  width: min(86vw, 340px);
  padding: max(var(--space-4), env(safe-area-inset-top)) var(--space-4)
    max(var(--space-4), env(safe-area-inset-bottom));
  flex-direction: column;
  border-right: 1px solid var(--color-border);
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
  transform: translateX(-100%);
  visibility: hidden;
  transition:
    transform var(--transition-base),
    visibility 0s linear 240ms;
}

.app-drawer--open {
  transform: translateX(0);
  visibility: visible;
  transition:
    transform var(--transition-base),
    visibility 0s;
}

.app-drawer__header {
  display: flex;
  min-height: 48px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-4);
  padding: 0 var(--space-1) 0 var(--space-3);
}

.app-drawer__brand {
  font-size: 1.5rem;
  font-weight: 850;
}

.app-drawer__brand span {
  color: var(--color-pink);
}

.app-drawer__close {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  color: var(--color-text-muted);
  font-size: 1.9rem;
  line-height: 1;
  background: var(--color-surface-soft);
  cursor: pointer;
}

.app-drawer__nav {
  display: grid;
  gap: var(--space-1);
  overflow-y: auto;
}

.app-drawer__section {
  display: grid;
  gap: var(--space-1);
}

.app-drawer__section--grouped {
  margin-top: var(--space-3);
}

.app-drawer__label {
  margin: 0;
  padding: 0 var(--space-3) var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  font-weight: 800;
  text-transform: uppercase;
}

.drawer-link {
  display: grid;
  width: 100%;
  min-height: 48px;
  padding: 0 var(--space-3);
  border: 0;
  border-radius: var(--radius-sm);
  grid-template-columns: 12px minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--space-3);
  color: var(--color-text);
  font-weight: 700;
  text-align: left;
  text-decoration: none;
  background: transparent;
  cursor: pointer;
  transition:
    transform var(--transition-fast),
    background-color var(--transition-fast);
}

.drawer-link.router-link-exact-active {
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
}

.drawer-link:disabled {
  color: var(--color-text-muted);
  cursor: not-allowed;
}

.drawer-link:not(:disabled):active {
  transform: scale(0.98);
}

.drawer-link__mark {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}

.drawer-link__mark--turquoise {
  background: var(--color-primary);
}

.drawer-link__mark--pink {
  background: var(--color-pink);
}

.drawer-link__mark--yellow {
  background: var(--color-yellow);
}

.drawer-link__soon {
  padding: 4px 8px;
  border-radius: var(--radius-pill);
  color: var(--color-text-muted);
  font-size: 0.67rem;
  font-weight: 750;
  background: #f2f1ee;
}

.app-drawer__divider {
  height: 1px;
  margin: var(--space-4) var(--space-3);
  background: var(--color-border);
}

.app-drawer__footer {
  display: grid;
  margin-top: auto;
  padding: var(--space-4) var(--space-2) 0;
  border-top: 1px solid var(--color-border);
  gap: var(--space-3);
}

.app-drawer__user {
  display: flex;
  width: 100%;
  min-height: 52px;
  min-width: 0;
  align-items: center;
  padding: var(--space-1);
  border: 0;
  border-radius: 8px;
  gap: var(--space-3);
  color: var(--color-text);
  text-align: left;
  background: transparent;
  cursor: pointer;
}

.app-drawer__username {
  overflow: hidden;
  font-weight: 750;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.app-drawer__user-action {
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 1.5rem;
}

.app-drawer__logout {
  min-height: 44px;
  padding: 0 var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-error);
  font-weight: 750;
  background: var(--color-surface);
  cursor: pointer;
}

.app-drawer__logout:disabled {
  opacity: 0.55;
  cursor: wait;
}
</style>
