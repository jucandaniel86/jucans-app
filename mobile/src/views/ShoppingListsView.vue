<script lang="ts">
type IndexTab = 'active' | 'history'
let rememberedTab: IndexTab = 'active'
</script>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import ShoppingExpenseForm from '@/components/shopping/ShoppingExpenseForm.vue'
import { ApiError } from '@/services/api'
import ShoppingListVisibilityBadge from '@/components/shopping/ShoppingListVisibilityBadge.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { shoppingApi } from '@/services/shoppingApi'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import type { ShoppingListSummary } from '@/types/shopping'
import type { PaginationMeta } from '@/types/food'
import { shoppingListName, shoppingListSummary } from '@/utils/shoppingPresentation'

const lists = ref<ShoppingListSummary[]>([])
const pagination = ref<PaginationMeta | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const shopping = useActiveShoppingListStore()
const notifications = useNotificationStore()
const router = useRouter()
const selecting = ref<number | null>(null)
const tab = ref<IndexTab>(rememberedTab)
const panelId = useId()
const expenseDialog = ref<HTMLDialogElement | null>(null)
const expenseForm = ref<InstanceType<typeof ShoppingExpenseForm> | null>(null)
const expenseList = ref<ShoppingListSummary | null>(null)
const expenseSaving = ref(false)
async function openExpense(list: ShoppingListSummary): Promise<void> {
  if (
    expenseSaving.value ||
    list.status !== 'closed' ||
    list.has_expense ||
    !list.can_register_expense
  )
    return
  expenseList.value = list
  await nextTick()
  expenseDialog.value?.showModal()
}
function closeExpense(): void {
  if (expenseSaving.value) return
  expenseDialog.value?.close()
  expenseList.value = null
}
async function registerExpense(): Promise<void> {
  if (expenseSaving.value || !expenseList.value) return
  const input = expenseForm.value?.input()
  if (!input) return
  const target = expenseList.value
  expenseSaving.value = true
  try {
    const result = await shoppingApi.registerExpense(target.id, { ...input, close_list: false })
    if (
      result.data?.shopping_list_id !== target.id ||
      result.data.project_id !== input.project_id ||
      result.data.list_status !== 'closed' ||
      !result.data.note_id
    )
      throw new ApiError('Înregistrarea costului nu a putut fi confirmată.', 0)
    // Update only this user's row; no list reload, reopening or current-list selection.
    target.has_expense = true
    target.can_register_expense = false
    if (result.data.note_deleted)
      notifications.warning('Costul există, dar nota este ștearsă. Restaurează nota din proiect.')
    else notifications.success('Costul a fost înregistrat.')
    expenseDialog.value?.close()
    expenseList.value = null
  } catch (failure) {
    expenseForm.value?.showError(failure)
  } finally {
    expenseSaving.value = false
  }
}
const history = computed(() => lists.value.filter((list) => list.status === 'closed'))
const historyCount = computed(() =>
  pagination.value?.total !== undefined
    ? Math.max(history.value.length, pagination.value.total - shopping.openLists.length)
    : history.value.length,
)
const visibleLists = computed(() => (tab.value === 'active' ? shopping.openLists : history.value))
watch(tab, (value) => {
  rememberedTab = value
})

async function keydown(event: KeyboardEvent): Promise<void> {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  tab.value =
    event.key === 'Home'
      ? 'active'
      : event.key === 'End'
        ? 'history'
        : tab.value === 'active'
          ? 'history'
          : 'active'
  await nextTick()
  document.getElementById(`${panelId}-${tab.value}`)?.focus()
}

async function load(page = 1): Promise<void> {
  if (loadingMore.value) return
  if (page === 1) loading.value = true
  else loadingMore.value = true
  error.value = ''
  try {
    const response = await shoppingApi.list(page)
    if (page === 1) await shopping.load(true)
    lists.value = page === 1 ? response.data : [...lists.value, ...response.data]
    pagination.value = response.meta
  } catch {
    error.value = 'Nu am putut încărca listele de cumpărături. Încearcă din nou.'
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}
async function select(id: number): Promise<void> {
  selecting.value = id
  try {
    await shopping.selectList(id)
  } catch (failure) {
    notifications.error(actionErrorMessage(failure, 'Nu am putut selecta lista. Încearcă din nou.'))
  } finally {
    selecting.value = null
  }
}
async function create(): Promise<void> {
  try {
    await shopping.createOwn()
    notifications.success('Lista a fost creată.')
    if (shopping.list)
      await router.push({ name: 'shopping-list-detail', params: { id: shopping.list.id } })
  } catch (failure) {
    notifications.error(actionErrorMessage(failure, 'Nu am putut crea lista. Încearcă din nou.'))
  }
}
onMounted(() => load())
</script>

<template>
  <section class="shopping-lists">
    <h1>Liste de cumpărături</h1>
    <div
      class="shopping-lists__tabs"
      role="tablist"
      aria-label="Liste de cumpărături"
      @keydown="keydown"
    >
      <button
        v-for="option in ['active', 'history'] as const"
        :id="`${panelId}-${option}`"
        :key="option"
        type="button"
        role="tab"
        :aria-selected="tab === option"
        :aria-controls="panelId"
        :tabindex="tab === option ? 0 : -1"
        @click="tab = option"
      >
        <span>{{ option === 'active' ? '🛒 Active' : '🕘 Istoric' }}</span>
        <span>({{ option === 'active' ? shopping.openLists.length : historyCount }})</span>
      </button>
    </div>
    <AppButton
      v-if="tab === 'active' && !loading && !shopping.openLists.some((list) => list.is_creator)"
      variant="secondary"
      :loading="shopping.creating"
      :disabled="shopping.busy || shopping.loading"
      @click="create"
      >Creează lista mea</AppButton
    >
    <p v-if="loading" role="status">Se încarcă listele…</p>
    <template v-else>
      <div :id="panelId" role="tabpanel" :aria-labelledby="`${panelId}-${tab}`" tabindex="0">
        <div v-if="!visibleLists.length && !error" class="shopping-lists__empty">
          <h2>{{ tab === 'active' ? 'Nu ai liste deschise.' : 'Nu există liste în istoric.' }}</h2>
        </div>
        <div
          v-for="list in visibleLists"
          :key="list.id"
          class="shopping-lists__row"
          :class="{
            'shopping-lists__row--current':
              list.status === 'open' && shopping.selectedId === list.id,
          }"
        >
          <RouterLink
            class="shopping-lists__link"
            :to="{ name: 'shopping-list-detail', params: { id: list.id } }"
          >
            <strong>{{ shoppingListName(list) }}</strong
            ><ShoppingListVisibilityBadge :list="list" show-creator /><small>{{
              shoppingListSummary(list)
            }}</small
            ><small v-if="list.status === 'open'" class="shopping-lists__remaining"
              >{{ list.unchecked_items_count }} de cumpărat</small
            >
          </RouterLink>
          <span
            v-if="list.status === 'open' && shopping.selectedId === list.id"
            class="shopping-lists__current"
            >✓ Curentă</span
          >
          <button
            v-else-if="list.status === 'open'"
            type="button"
            class="shopping-lists__select"
            :disabled="shopping.busy || shopping.loading || selecting !== null"
            :aria-label="`Folosește lista: ${shoppingListName(list)} · ${list.creator?.name ?? ''}`"
            @click="select(list.id)"
          >
            {{ selecting === list.id ? 'Se selectează…' : 'Folosește lista' }}
          </button>
          <span v-else-if="list.has_expense" class="shopping-lists__expense-recorded"
            >✓ Cost înregistrat</span
          >
          <button
            v-else-if="list.can_register_expense"
            type="button"
            class="shopping-lists__select"
            :disabled="expenseSaving"
            @click="openExpense(list)"
          >
            Adaugă cost
          </button>
          <span v-else class="shopping-lists__chevron" aria-hidden="true">›</span>
        </div>
      </div>
      <AppButton
        v-if="tab === 'history' && pagination && pagination.current_page < pagination.last_page"
        variant="secondary"
        :loading="loadingMore"
        @click="load(pagination!.current_page + 1)"
        >Mai multe liste</AppButton
      >
    </template>
    <div v-if="error" role="alert">
      <p>{{ error }}</p>
      <AppButton
        variant="secondary"
        @click="load(lists.length ? (pagination?.current_page ?? 0) + 1 : 1)"
        >Încearcă din nou</AppButton
      >
    </div>
    <dialog
      ref="expenseDialog"
      class="shopping-lists__expense-dialog"
      aria-labelledby="expense-title"
      @cancel.prevent="closeExpense"
    >
      <form v-if="expenseList" novalidate @submit.prevent="registerExpense">
        <h2 id="expense-title">Adaugă cost</h2>
        <p>{{ shoppingListName(expenseList) }}</p>
        <ShoppingExpenseForm ref="expenseForm" :key="expenseList.id" :disabled="expenseSaving" />
        <div class="shopping-lists__expense-actions">
          <AppButton variant="secondary" :disabled="expenseSaving" @click="closeExpense"
            >Anulează</AppButton
          >
          <AppButton type="submit" :loading="expenseSaving" :disabled="expenseForm?.loading"
            >Adaugă cost</AppButton
          >
        </div>
      </form>
    </dialog>
  </section>
</template>

<style scoped>
.shopping-lists {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  min-height: 100%;
  min-width: 0;
}
.shopping-lists__expense-recorded {
  max-width: 110px;
  color: var(--color-primary-strong);
  font-size: 0.8rem;
}
.shopping-lists__expense-dialog {
  width: calc(100% - 2 * var(--space-4));
  max-width: 400px;
  max-height: calc(100dvh - 2 * var(--space-4));
  overflow-y: auto;
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text);
  background: var(--color-surface);
}
.shopping-lists__expense-dialog::backdrop {
  background: var(--color-overlay);
}
.shopping-lists__expense-dialog h2 {
  margin: 0;
  font-size: 1.2rem;
}
.shopping-lists__expense-dialog p {
  font-size: 0.9rem;
}
.shopping-lists__expense-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}
.shopping-lists__tabs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  border-bottom: 1px solid var(--color-border);
}
.shopping-lists__tabs button {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  align-content: center;
  justify-content: center;
  gap: var(--space-1);
  height: 48px;
  min-width: 0;
  padding: var(--space-1) var(--space-2);
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 750;
  line-height: 1.2;
  cursor: pointer;
}
.shopping-lists__tabs button[aria-selected='true'] {
  border-bottom-color: var(--color-primary-strong);
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
}
.shopping-lists h1 {
  margin: 0;
  font-size: 1.65rem;
  line-height: 1.2;
  overflow-wrap: anywhere;
}
.shopping-lists__link {
  flex: 1;
  min-width: 0;
  text-decoration: none;
  display: grid;
  gap: var(--space-1);
}
.shopping-lists__select {
  min-height: 44px;
  max-width: 110px;
  padding: var(--space-2);
  border: 0;
  background: transparent;
  color: var(--color-primary-strong);
  font-size: 0.8rem;
  font-weight: 700;
  cursor: pointer;
}
.shopping-lists__select:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.shopping-lists__current {
  flex-shrink: 0;
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--color-primary-strong);
}
.shopping-lists__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  min-height: 76px;
  padding: var(--space-3) 0;
  border-bottom: 1px solid var(--color-border);
  text-decoration: none;
}
.shopping-lists__row--current strong {
  color: var(--color-primary-strong);
}
.shopping-lists__row div {
  min-width: 0;
  display: grid;
  gap: var(--space-1);
}
.shopping-lists__row strong {
  font-size: 1rem;
  overflow-wrap: anywhere;
}
.shopping-lists__row small {
  font-size: 0.82rem;
  color: var(--color-text-muted);
}
.shopping-lists__row .shopping-lists__remaining {
  color: var(--color-primary-strong);
  font-weight: 650;
}
.shopping-lists__chevron {
  color: var(--color-text-muted);
  font-size: 1.5rem;
}
.shopping-lists__empty {
  display: grid;
  justify-items: center;
  gap: var(--space-3);
  padding: var(--space-10) 0;
  text-align: center;
}
.shopping-lists__empty h2 {
  margin: 0;
  font-size: 1.1rem;
}
.shopping-lists__empty p {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
.shopping-lists__empty > span {
  font-size: 2rem;
}
.shopping-lists__empty a {
  padding: var(--space-3);
  color: var(--color-primary-strong);
  font-weight: 700;
}
</style>
