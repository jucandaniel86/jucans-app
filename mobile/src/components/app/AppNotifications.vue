<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useNotificationStore } from '@/stores/notifications'

const store = useNotificationStore()
const target = ref<HTMLElement | string>('body')
const symbols = { success: '✓', error: '!', warning: '!', info: 'i' }
const labels = { success: 'Succes', error: 'Eroare', warning: 'Atenție', info: 'Informație' }
let observer: MutationObserver | undefined

function updateTarget(): void {
  // Modal dialogs occupy the browser top layer; render inside the active one.
  const dialogs = document.querySelectorAll<HTMLDialogElement>('dialog[open]')
  target.value = dialogs.item(dialogs.length - 1) ?? 'body'
}
onMounted(() => {
  updateTarget()
  observer = new MutationObserver(updateTarget)
  observer.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['open'],
  })
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <Teleport :to="target">
    <TransitionGroup
      name="notification"
      tag="div"
      class="app-notifications"
      aria-label="Notificări"
    >
      <div
        v-for="notification in store.notifications"
        :key="notification.id"
        class="app-notification"
        :class="`app-notification--${notification.type}`"
      >
        <span class="app-notification__symbol" aria-hidden="true">{{
          symbols[notification.type]
        }}</span>
        <p
          :role="
            notification.type === 'error' || notification.type === 'warning' ? 'alert' : 'status'
          "
          aria-atomic="true"
        >
          <span class="app-notification__label">{{ labels[notification.type] }}: </span
          >{{ notification.message }}
        </p>
        <button
          type="button"
          aria-label="Închide notificarea"
          title="Închide notificarea"
          @click="store.dismiss(notification.id)"
        >
          ×
        </button>
      </div>
    </TransitionGroup>
  </Teleport>
</template>

<style scoped>
.app-notifications {
  position: fixed;
  z-index: 200;
  top: calc(env(safe-area-inset-top, 0px) + 70px);
  left: 50%;
  transform: translateX(-50%);
  display: grid;
  gap: 8px;
  width: min(
    440px,
    calc(100% - 24px - env(safe-area-inset-left, 0px) - env(safe-area-inset-right, 0px))
  );
  max-height: min(50dvh, calc(100dvh - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px) - 100px));
  overflow-y: auto;
  padding: 2px;
  pointer-events: none;
}
.app-notification {
  --notification-color: var(--color-primary-strong);
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr) 36px;
  align-items: center;
  gap: 8px;
  padding: 6px 6px 6px 12px;
  border: 1px solid var(--color-border-strong);
  border-left: 4px solid var(--notification-color);
  border-radius: 8px;
  color: var(--color-text);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
  pointer-events: auto;
}
.app-notification--success {
  --notification-color: var(--color-success);
  background: #f0faf5;
}
.app-notification--error {
  --notification-color: var(--color-error);
  background: var(--color-error-soft);
}
.app-notification--warning {
  --notification-color: #8b640e;
  background: var(--color-yellow-soft);
}
.app-notification--info {
  background: var(--color-primary-soft);
}
.app-notification__symbol {
  color: var(--notification-color);
  font-size: 1rem;
  font-weight: 850;
  text-align: center;
}
.app-notification p {
  margin: 0;
  font-size: 0.86rem;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
.app-notification__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
}
.app-notification button {
  width: 36px;
  height: 36px;
  padding: 0;
  border: 0;
  border-radius: 4px;
  color: var(--color-text-muted);
  background: transparent;
  font-size: 1.35rem;
  cursor: pointer;
}
.app-notification button:hover {
  background: rgb(21 36 61 / 6%);
}
.notification-enter-active,
.notification-leave-active,
.notification-move {
  transition:
    opacity 160ms ease,
    transform 160ms ease;
}
.notification-enter-from,
.notification-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
@media (prefers-reduced-motion: reduce) {
  .notification-enter-active,
  .notification-leave-active,
  .notification-move {
    transition: none;
  }
}
</style>
