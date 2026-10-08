<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue'
import AppModal from '@/components/app/AppModal.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { useNotificationStore } from '@/stores/notifications'

const store = useNotificationStore()
const confirmation = computed(() => store.confirmations[0])

onBeforeUnmount(() => {
  while (store.confirmations.length) store.resolveConfirmation(false)
})
</script>

<template>
  <AppModal
    :model-value="!!confirmation"
    :title="confirmation?.title"
    @update:model-value="!$event && store.resolveConfirmation(false)"
  >
    <p class="confirmation-message">{{ confirmation?.message }}</p>
    <template #footer>
      <AppButton variant="secondary" @click="store.resolveConfirmation(false)">
        {{ confirmation?.cancelText ?? 'Anulează' }}
      </AppButton>
      <AppButton @click="store.resolveConfirmation(true)">
        {{ confirmation?.confirmText ?? 'Confirmă' }}
      </AppButton>
    </template>
  </AppModal>
</template>

<style scoped>
.confirmation-message {
  white-space: pre-line;
  overflow-wrap: anywhere;
}
</style>
