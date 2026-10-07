<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppButton from '@/components/ui/AppButton.vue'
import ShoppingItemGroups from '@/components/shopping/ShoppingItemGroups.vue'
import ShoppingListQuickAdd from '@/components/shopping/ShoppingListQuickAdd.vue'
import ShoppingListTabs from '@/components/shopping/ShoppingListTabs.vue'
import ShoppingListRecipes from '@/components/shopping/ShoppingListRecipes.vue'
import ShoppingListSharing from '@/components/shopping/ShoppingListSharing.vue'
import ShoppingListVisibilityBadge from '@/components/shopping/ShoppingListVisibilityBadge.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { shoppingApi } from '@/services/shoppingApi'
import { foodApi } from '@/services/foodApi'
import { exportShoppingListText } from '@/utils/shoppingExport'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import type { ActiveShoppingList, ShoppingListSummary, ShoppingListTab } from '@/types/shopping'
import type { FoodUnit } from '@/types/food'
import {
  shoppingListCreatorLabel,
  shoppingListName,
  shoppingListSummary,
} from '@/utils/shoppingPresentation'

const shopping = useActiveShoppingListStore()
const notifications = useNotificationStore()
const tab = ref<ShoppingListTab>('shopping')
const panelId = useId()
const content = ref<HTMLElement | null>(null)
const route = useRoute()
const router = useRouter()
const detail = ref<ActiveShoppingList | null>(null)
const detailUnits = ref<Record<string, FoodUnit>>({})
const detailLoading = ref(false)
const detailError = ref('')
const exporting = ref(false)
const sharingBusy = ref(false)
const confirmDialog = ref<HTMLDialogElement | null>(null)
let request = 0
const list = computed(() =>
  route.params.id &&
  (shopping.error || detail.value?.status === 'closed' || detail.value?.id !== shopping.list?.id)
    ? detail.value
    : shopping.list,
)
const readonly = computed(
  () =>
    list.value?.status === 'closed' ||
    Boolean(route.params.id && (shopping.error || list.value?.id !== shopping.list?.id)),
)
const units = computed(() => (readonly.value ? detailUnits.value : shopping.units))
const loading = computed(() => detailLoading.value || (!route.params.id && shopping.loading))
const error = computed(() => (route.params.id ? detailError.value : shopping.error))
const summary = computed(() => (list.value ? shoppingListSummary(list.value) : ''))
const creatorLabel = computed(() => (list.value ? shoppingListCreatorLabel(list.value) : ''))
const canEdit = computed(() => list.value && !readonly.value && !loading.value && !error.value)
const canClose = computed(() => canEdit.value && list.value?.is_creator)
const checking = computed(() => Object.keys(shopping.pendingChecks).length > 0)

async function loadList(): Promise<void> {
  const sequence = ++request
  detailError.value = ''
  detail.value = null
  detailUnits.value = {}
  sharingBusy.value = false
  tab.value = 'shopping'
  confirmDialog.value?.close()
  if (!route.params.id) {
    await shopping.load(true)
    return
  }
  const id = Number(route.params.id)
  if (!Number.isInteger(id) || id < 1) {
    detailError.value = 'Lista nu este validă.'
    return
  }
  detailLoading.value = true
  try {
    const response = await shoppingApi.getList(id)
    if (sequence !== request) return
    detail.value = response.data
    if (response.data.status === 'open' && shopping.list?.id === id) {
      shopping.updateSummary(response.data)
      shopping.list = response.data
    }
    if (response.data.items.length) {
      if (Object.keys(shopping.units).length) detailUnits.value = shopping.units
      else {
        const config = await foodApi.getConfig()
        if (sequence === request) detailUnits.value = config.units
      }
    }
  } catch {
    if (sequence === request)
      detailError.value = 'Nu am putut încărca lista de cumpărături. Încearcă din nou.'
  } finally {
    if (sequence === request) detailLoading.value = false
  }
}

function updateSharing(summary: ShoppingListSummary): void {
  if (detail.value?.id === summary.id) detail.value = { ...detail.value, ...summary }
  shopping.updateSummary(summary)
}
async function exportList(): Promise<void> {
  if (!list.value || exporting.value) return
  exporting.value = true
  try {
    const result = await exportShoppingListText(list.value.id)
    if (result === 'empty') notifications.info('Nu mai sunt produse de cumpărat.')
  } catch (failure) {
    notifications.error(actionErrorMessage(failure, 'Nu am putut exporta lista. Încearcă din nou.'))
  } finally {
    exporting.value = false
  }
}
async function useList(): Promise<void> {
  if (!detail.value || detail.value.status !== 'open') return
  detailError.value = ''
  try {
    await shopping.selectList(detail.value.id)
  } catch (failure) {
    notifications.error(actionErrorMessage(failure, 'Nu am putut selecta lista. Încearcă din nou.'))
  }
}

async function closeList(): Promise<void> {
  if (
    !canClose.value ||
    sharingBusy.value ||
    shopping.removalBlocked ||
    shopping.removingRecipeId !== null
  )
    return
  try {
    await shopping.closeCurrent()
    notifications.success('Lista a fost închisă.')
    confirmDialog.value?.close()
    await router.push({ name: 'shopping-lists' })
  } catch (failure) {
    notifications.error(actionErrorMessage(failure, 'Nu am putut închide lista. Încearcă din nou.'))
  }
}
function openCloseDialog(): void {
  confirmDialog.value?.showModal()
}
watch(() => route.params.id, loadList, { immediate: true })
watch(tab, () => {
  if (content.value) content.value.scrollTop = 0
})
</script>

<template>
  <section class="shopping-list" :aria-busy="loading">
    <header class="shopping-list__header">
      <div class="shopping-list__title-row">
        <h1>{{ list ? shoppingListName(list) : 'Lista de cumpărături' }}</h1>
        <ShoppingListSharing
          v-if="!loading && !error && list?.is_creator"
          :key="list.id"
          :list="list"
          compact
          :disabled="shopping.removalBlocked || shopping.removingRecipeId !== null"
          @updated="updateSharing"
          @busy="sharingBusy = $event"
        />
        <RouterLink
          class="shopping-list__dismiss"
          :to="{ name: 'shopping-lists' }"
          aria-label="Înapoi la liste"
          title="Înapoi la liste"
        >
          <span aria-hidden="true">×</span>
        </RouterLink>
      </div>
      <p v-if="!loading && !error && list" class="shopping-list__summary">
        {{ summary }} · {{ creatorLabel }}
      </p>
      <div v-if="!loading && !error && list" class="shopping-list__meta">
        <ShoppingListVisibilityBadge :list="list" />
        <span v-if="list.status === 'closed'" class="shopping-list__closed">Închisă</span>
        <span v-else-if="!readonly">✓ Lista curentă</span>
        <button
          class="shopping-list__export"
          type="button"
          :disabled="exporting"
          @click="exportList"
        >
          Export
        </button>
      </div>
      <p
        v-if="list?.status === 'open' && readonly && !loading"
        class="shopping-list__use"
        role="status"
      >
        <AppButton
          variant="secondary"
          :disabled="shopping.busy || shopping.loading"
          @click="useList"
          >Folosește lista</AppButton
        >
      </p>
    </header>

    <ShoppingListTabs
      v-if="list && !loading && !error"
      v-model="tab"
      :items-count="list.items_count"
      :recipes-count="list.recipes_count"
      :panel-id="panelId"
    />
    <div
      ref="content"
      :id="panelId"
      class="shopping-list__items"
      :role="list && !loading && !error ? 'tabpanel' : 'region'"
      :aria-labelledby="list && !loading && !error ? `${panelId}-${tab}` : undefined"
      tabindex="0"
    >
      <div v-if="loading" class="shopping-list__loading" role="status">
        <p>Se încarcă lista…</p>
        <span v-for="row in 5" :key="row" aria-hidden="true" />
      </div>
      <div v-else-if="error" class="shopping-list__state" role="alert">
        <p>{{ error }}</p>
        <AppButton variant="secondary" @click="loadList">Încearcă din nou</AppButton>
      </div>
      <ShoppingListRecipes
        v-else-if="list && tab === 'recipes'"
        :list="list"
        :readonly="readonly"
      />
      <div v-else-if="!list || !list.items.length" class="shopping-list__state">
        <span class="shopping-list__empty-icon" aria-hidden="true">🛒</span>
        <h2>
          {{
            shopping.selectionRequired && !list
              ? 'Alege lista curentă.'
              : 'Lista de cumpărături este goală.'
          }}
        </h2>
        <RouterLink v-if="!list && shopping.selectionRequired" :to="{ name: 'shopping-lists' }"
          >Liste de cumpărături</RouterLink
        >
        <p>Adaugă rețete în listă din pagina unei rețete.</p>
        <RouterLink :to="{ name: 'recipes' }">Vezi rețetele</RouterLink>
      </div>
      <ShoppingItemGroups v-else :items="list.items" :units="units" :readonly="readonly" />
    </div>
    <footer v-if="canEdit" class="shopping-list__actions">
      <ShoppingListQuickAdd v-if="tab === 'shopping'" />
      <AppButton
        v-if="canClose"
        class="shopping-list__close"
        variant="secondary"
        :disabled="sharingBusy || shopping.removalBlocked || shopping.removingRecipeId !== null"
        @click="openCloseDialog"
        >Închide lista</AppButton
      >
    </footer>
    <dialog
      ref="confirmDialog"
      class="shopping-list__dialog"
      aria-labelledby="close-list-title"
      @cancel="shopping.closing && $event.preventDefault()"
    >
      <form @submit.prevent="closeList">
        <h2 id="close-list-title">Închide lista?</h2>
        <p>Lista va fi mutată în istoric și nu va mai putea fi modificată.</p>
        <div>
          <AppButton
            variant="secondary"
            :disabled="shopping.closing"
            @click="confirmDialog?.close()"
            >Anulează</AppButton
          ><AppButton
            type="submit"
            :loading="shopping.closing"
            :disabled="
              checking ||
              shopping.addingItem ||
              shopping.addingRecipe ||
              shopping.removingRecipeId !== null
            "
            >Închide lista</AppButton
          >
        </div>
      </form>
    </dialog>
  </section>
</template>

<style scoped>
.shopping-list__dismiss {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border: 1px solid color-mix(in srgb, var(--color-error) 30%, var(--color-border));
  border-radius: 8px;
  color: var(--color-error);
  background: color-mix(in srgb, var(--color-error) 8%, var(--color-surface));
  font-size: 1.5rem;
  line-height: 1;
  text-decoration: none;
}
.shopping-list__dismiss:hover {
  background: color-mix(in srgb, var(--color-error) 14%, var(--color-surface));
}
.shopping-list__dismiss:focus-visible {
  outline: 2px solid var(--color-error);
  outline-offset: 2px;
}
.shopping-list__title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}
.shopping-list__title-row h1 {
  flex: 1;
  min-width: 0;
}
.shopping-list__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin-top: var(--space-2);
  color: var(--color-primary-strong);
  font-size: 0.8rem;
  font-weight: 750;
}
.shopping-list__closed {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}
.shopping-list__export {
  min-height: 32px;
  margin-left: auto;
  border: 0;
  padding: 0 var(--space-1);
  color: var(--color-primary-strong);
  background: transparent;
  font-size: 0.8rem;
  font-weight: 800;
  cursor: pointer;
}
.shopping-list__export:disabled {
  opacity: 0.55;
  cursor: wait;
}
.shopping-list__close {
  flex-shrink: 0;
  color: var(--color-error);
}
.shopping-list__dialog {
  width: min(calc(100% - 32px), 440px);
  padding: var(--space-5);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-text);
  background: var(--color-surface);
}
.shopping-list__dialog::backdrop {
  background: var(--color-overlay);
}
.shopping-list__dialog h2 {
  margin: 0;
  font-size: 1.2rem;
}
.shopping-list__dialog p {
  font-size: 0.9rem;
  line-height: 1.5;
}
.shopping-list__dialog form > div {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
.shopping-list {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  gap: var(--space-2);
}
.shopping-list__header {
  flex-shrink: 0;
}
.shopping-list__items {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.shopping-list__actions {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  min-height: 0;
}
.shopping-list__actions > :deep(.shopping-quick-add) {
  flex-shrink: 1;
  min-height: 0;
  overflow-y: auto;
}
.shopping-list__header h1 {
  margin: 0;
  font-size: 1.35rem;
  line-height: 1.2;
  overflow-wrap: anywhere;
}
.shopping-list__summary {
  margin: var(--space-1) 0 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
  line-height: 1.35;
}
.shopping-list__use {
  margin: var(--space-2) 0 0;
}
.shopping-list__use :deep(.app-button) {
  min-height: 44px;
  padding: 0 var(--space-4);
  border-radius: 8px;
}
.shopping-list__state {
  display: grid;
  gap: var(--space-3);
  justify-items: center;
  padding: var(--space-10) 0;
  text-align: center;
}
.shopping-list__state h2 {
  margin: 0;
  font-size: 1.1rem;
}
.shopping-list__state p {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  line-height: 1.5;
}
.shopping-list__state a {
  padding: var(--space-3);
  color: var(--color-primary-strong);
  font-weight: 700;
}
.shopping-list__empty-icon {
  font-size: 2rem;
}
.shopping-list__loading {
  display: grid;
  gap: var(--space-3);
}
.shopping-list__loading p {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
.shopping-list__loading span {
  height: 44px;
  border-radius: 4px;
  background: var(--color-primary-soft);
}

@media (max-height: 500px) {
  .shopping-list {
    gap: var(--space-1);
  }
  .shopping-list__header h1 {
    font-size: 1rem;
  }
  .shopping-list__closed,
  .shopping-list__meta,
  .shopping-list__summary {
    margin-top: var(--space-1);
  }
}
</style>
