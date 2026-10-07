import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { foodApi } from '@/services/foodApi'
import { shoppingApi } from '@/services/shoppingApi'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import type { FoodUnit } from '@/types/food'
import type {
  ActiveShoppingList,
  ManualShoppingItemInput,
  ShoppingItemUpdateInput,
  ShoppingListSummary,
} from '@/types/shopping'
import { shoppingListSummary } from '@/utils/shoppingPresentation'

// Existing consumers retain this store ID; its context is now an explicit current list.
export const useActiveShoppingListStore = defineStore('active-shopping-list', () => {
  const auth = useAuthStore()
  const notifications = useNotificationStore()
  const list = ref<ActiveShoppingList | null>(null)
  const openLists = ref<ShoppingListSummary[]>([])
  const selectedId = ref<number | null>(null)
  const units = ref<Record<string, FoodUnit>>({})
  const loading = ref(true)
  const loaded = ref(false)
  const error = ref('')
  const closing = ref(false)
  const creating = ref(false)
  const addingItem = ref(false)
  const addingRecipe = ref(false)
  const removingRecipeId = ref<number | null>(null)
  const pendingItemEdits = ref<Record<number, boolean>>({})
  const pendingChecks = ref<Record<number, boolean>>({})
  const itemErrors = ref<Record<number, string>>({})
  const removalBlocked = computed(
    () =>
      closing.value ||
      creating.value ||
      addingItem.value ||
      addingRecipe.value ||
      Object.keys(pendingItemEdits.value).length > 0 ||
      Object.keys(pendingChecks.value).length > 0,
  )
  const busy = computed(() => removalBlocked.value || removingRecipeId.value !== null)
  const selectionRequired = computed(() => !list.value && openLists.value.length > 0)
  const hasItems = computed(() => Boolean(list.value?.items.length))
  const summary = computed(() => (list.value ? shoppingListSummary(list.value) : ''))
  const remainingSummary = computed(() => {
    if (!list.value) return ''
    const { unchecked_items_count: remaining, recipes_count: recipes } = list.value
    return remaining === 0
      ? 'Totul cumpărat ✓'
      : `${remaining} de cumpărat · ${recipes} ${recipes === 1 ? 'rețetă' : 'rețete'}`
  })
  let pending: Promise<void> | null = null
  let revision = 0
  let sessionRevision = 0
  let hydrated = false
  const storageKey = () => (auth.user ? `jucans.current-shopping-list.${auth.user.id}` : null)
  function persist(): void {
    const key = storageKey()
    if (!key) return
    try {
      if (selectedId.value === null) localStorage.removeItem(key)
      else localStorage.setItem(key, String(selectedId.value))
    } catch {
      /* In-memory selection still works when storage is unavailable. */
    }
  }
  function hydrate(): void {
    if (hydrated) return
    hydrated = true
    const key = storageKey()
    if (!key) return
    try {
      const value = Number(localStorage.getItem(key))
      selectedId.value = Number.isSafeInteger(value) && value > 0 ? value : null
    } catch {
      selectedId.value = null
    }
  }
  function invalidateRequests(): void {
    revision++
    pending = null
    loading.value = false
  }
  function reset(): void {
    sessionRevision++
    invalidateRequests()
    list.value = null
    selectedId.value = null
    openLists.value = []
    units.value = {}
    loaded.value = false
    hydrated = false
    error.value = ''
    pendingChecks.value = {}
    itemErrors.value = {}
    creating.value = false
    closing.value = false
    addingItem.value = false
    addingRecipe.value = false
    removingRecipeId.value = null
    pendingItemEdits.value = {}
  }
  function recount(): void {
    if (list.value)
      list.value.unchecked_items_count = list.value.items.filter((item) => !item.is_checked).length
  }
  function updateSummary(summary: ShoppingListSummary): void {
    const index = openLists.value.findIndex((candidate) => candidate.id === summary.id)
    if (summary.status !== 'open')
      openLists.value = openLists.value.filter((candidate) => candidate.id !== summary.id)
    else if (index < 0) openLists.value.push(summary)
    else openLists.value[index] = summary
    if (list.value?.id === summary.id) {
      if (summary.status === 'open') list.value = { ...list.value, ...summary }
      else clearSelection()
    }
  }
  function clearSelection(): void {
    invalidateRequests()
    selectedId.value = null
    list.value = null
    itemErrors.value = {}
    persist()
  }
  function accept(incoming: ActiveShoppingList): void {
    list.value = incoming
    selectedId.value = incoming.id
    incoming.items.forEach((item) => {
      const desired = pendingChecks.value[item.id]
      if (desired !== undefined) item.is_checked = desired
    })
    recount()
    updateSummary(incoming)
    persist()
  }
  async function loadUnits(): Promise<void> {
    if (Object.keys(units.value).length) return
    const session = sessionRevision
    const config = await foodApi.getConfig()
    if (session === sessionRevision) units.value = config.units
  }
  function load(force = false): Promise<void> {
    if (pending) return pending
    if (loaded.value && !force) return Promise.resolve()
    hydrate()
    const current = revision
    loading.value = true
    error.value = ''
    pending = (async () => {
      try {
        const response = await shoppingApi.getOpen()
        if (current !== revision) return
        openLists.value = response.data.filter((candidate) => candidate.status === 'open')
        let id = selectedId.value ?? list.value?.id ?? null
        if (!openLists.value.some((candidate) => candidate.id === id)) {
          // Shared/public lists never become the working context implicitly.
          id =
            openLists.value.length === 1 && openLists.value[0]?.is_creator
              ? openLists.value[0].id
              : null
        }
        selectedId.value = id
        persist()
        if (id === null) list.value = null
        else {
          const detail = await shoppingApi.getList(id)
          if (current !== revision) return
          if (detail.data.status !== 'open') {
            openLists.value = openLists.value.filter((candidate) => candidate.id !== id)
            selectedId.value = null
            list.value = null
            persist()
          } else {
            accept(detail.data)
            if (detail.data.items.length) await loadUnits()
            if (current !== revision) return
          }
        }
        loaded.value = true
      } catch (failure) {
        if (current !== revision) return
        if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
          const lostId = selectedId.value
          openLists.value = openLists.value.filter((candidate) => candidate.id !== lostId)
          selectedId.value = null
          persist()
        }
        list.value = null
        error.value = 'Nu am putut încărca lista de cumpărături. Încearcă din nou.'
      } finally {
        if (current === revision) {
          loading.value = false
          pending = null
        }
      }
    })()
    return pending
  }
  async function selectList(id: number): Promise<void> {
    if (busy.value) throw new Error('Lista este ocupată. Încearcă din nou.')
    hydrate()
    invalidateRequests()
    const current = revision
    const session = sessionRevision
    loading.value = true
    error.value = ''
    try {
      const response = await shoppingApi.getList(id)
      if (current !== revision || session !== sessionRevision)
        throw new Error('Shopping list changed')
      if (response.data.status !== 'open')
        throw new Error('O listă închisă nu poate deveni lista curentă.')
      accept(response.data)
      if (response.data.items.length) await loadUnits()
      loaded.value = true
    } finally {
      if (current === revision) loading.value = false
    }
  }
  async function createOwn(): Promise<void> {
    if (busy.value) throw new Error('Lista este ocupată. Încearcă din nou.')
    const session = sessionRevision
    creating.value = true
    try {
      const response = await shoppingApi.createOwn()
      if (session !== sessionRevision) throw new Error('Shopping list changed')
      creating.value = false
      await selectList(response.data.id)
    } finally {
      if (session === sessionRevision) creating.value = false
    }
  }
  async function mutationFailed(failure: unknown, id: number): Promise<void> {
    if (
      list.value?.id === id &&
      failure instanceof ApiError &&
      [403, 404].includes(failure.status)
    ) {
      clearSelection()
      loaded.value = false
      await load(true)
    }
  }
  async function setChecked(itemId: number, checked: boolean): Promise<void> {
    const item = list.value?.items.find((item) => item.id === itemId)
    if (
      !item ||
      list.value?.status !== 'open' ||
      closing.value ||
      removingRecipeId.value !== null ||
      pendingChecks.value[itemId] !== undefined ||
      item.is_checked === checked
    )
      return
    const previous = item.is_checked
    const listId = list.value!.id
    const session = sessionRevision
    invalidateRequests()
    pendingChecks.value[itemId] = checked
    delete itemErrors.value[itemId]
    item.is_checked = checked
    recount()
    try {
      const response = await shoppingApi.setItemChecked(listId, itemId, checked)
      if (session !== sessionRevision || list.value?.id !== listId) return
      const current = list.value.items.find((item) => item.id === itemId)
      if (current) current.is_checked = response.data.is_checked
    } catch (failure) {
      if (session !== sessionRevision || list.value?.id !== listId) return
      item.is_checked = previous
      itemErrors.value[itemId] = 'Nu am putut salva modificarea. Încearcă din nou.'
      notifications.error(actionErrorMessage(failure, itemErrors.value[itemId]))
      await mutationFailed(failure, listId)
    } finally {
      if (session === sessionRevision) {
        invalidateRequests()
        delete pendingChecks.value[itemId]
        recount()
        if (list.value) updateSummary(list.value)
      }
    }
  }
  function replaceItem(updated: ActiveShoppingList['items'][number]): void {
    if (!list.value) return
    const index = list.value.items.findIndex((item) => item.id === updated.id)
    if (index >= 0) list.value.items[index] = updated
  }
  async function updateItem(itemId: number, input: ShoppingItemUpdateInput): Promise<void> {
    if (
      !list.value ||
      list.value.status !== 'open' ||
      closing.value ||
      removingRecipeId.value !== null
    )
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    pendingItemEdits.value[itemId] = true
    delete itemErrors.value[itemId]
    try {
      const response = await shoppingApi.updateItem(listId, itemId, input)
      if (session !== sessionRevision || list.value?.id !== listId)
        throw new Error('Shopping list changed')
      invalidateRequests()
      replaceItem(response.data)
      recount()
      updateSummary(list.value)
    } catch (failure) {
      if (session === sessionRevision) {
        itemErrors.value[itemId] = 'Nu am putut salva produsul. Încearcă din nou.'
        await mutationFailed(failure, listId)
      }
      throw failure
    } finally {
      if (session === sessionRevision) delete pendingItemEdits.value[itemId]
    }
  }
  async function deleteItem(itemId: number): Promise<void> {
    if (
      !list.value ||
      list.value.status !== 'open' ||
      closing.value ||
      removingRecipeId.value !== null
    )
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    pendingItemEdits.value[itemId] = true
    delete itemErrors.value[itemId]
    try {
      await shoppingApi.deleteItem(listId, itemId)
      if (session !== sessionRevision || list.value?.id !== listId) return
      invalidateRequests()
      list.value.items = list.value.items.filter((item) => item.id !== itemId)
      list.value.items_count = list.value.items.length
      recount()
      updateSummary(list.value)
    } catch (failure) {
      if (session === sessionRevision) {
        itemErrors.value[itemId] = 'Nu am putut șterge produsul. Încearcă din nou.'
        await mutationFailed(failure, listId)
      }
      throw failure
    } finally {
      if (session === sessionRevision) delete pendingItemEdits.value[itemId]
    }
  }
  async function addRecipe(recipeId: number) {
    if (busy.value) throw new Error('Shopping list is busy')
    if (!loaded.value && !list.value) await load()
    if (error.value) throw new Error(error.value)
    if (!list.value) {
      if (openLists.value.length) throw new Error('Alege lista curentă din Liste de cumpărături.')
      await createOwn()
    }
    if (!list.value || list.value.status !== 'open' || busy.value)
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    addingRecipe.value = true
    invalidateRequests()
    try {
      const response = await shoppingApi.addRecipe(listId, recipeId)
      if (session !== sessionRevision || list.value?.id !== listId || response.data.id !== listId)
        throw new Error('Shopping list changed')
      accept(response.data)
      loaded.value = true
      error.value = ''
      return response
    } catch (failure) {
      if (session === sessionRevision) await mutationFailed(failure, listId)
      throw failure
    } finally {
      if (session === sessionRevision) addingRecipe.value = false
    }
  }
  async function removeRecipe(recipeId: number): Promise<void> {
    if (!list.value || list.value.status !== 'open' || busy.value)
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    removingRecipeId.value = recipeId
    try {
      const response = await shoppingApi.removeRecipe(listId, recipeId)
      if (session !== sessionRevision || list.value?.id !== listId || response.data.id !== listId)
        throw new Error('Shopping list changed')
      invalidateRequests()
      accept(response.data)
      loaded.value = true
      error.value = ''
      itemErrors.value = {}
    } catch (failure) {
      if (session === sessionRevision) await mutationFailed(failure, listId)
      throw failure
    } finally {
      if (session === sessionRevision) removingRecipeId.value = null
    }
  }
  async function addManualItem(input: ManualShoppingItemInput): Promise<void> {
    if (
      !list.value ||
      list.value.status !== 'open' ||
      closing.value ||
      addingItem.value ||
      addingRecipe.value ||
      removingRecipeId.value !== null
    )
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    addingItem.value = true
    try {
      const response = await shoppingApi.addManualItem(listId, input)
      if (session !== sessionRevision || list.value?.id !== listId)
        throw new Error('Shopping list changed')
      invalidateRequests()
      if (!list.value.items.some((item) => item.id === response.data.id))
        list.value.items.push(response.data)
      list.value.items_count = list.value.items.length
      recount()
      updateSummary(list.value)
    } catch (failure) {
      if (session === sessionRevision) await mutationFailed(failure, listId)
      throw failure
    } finally {
      if (session === sessionRevision) addingItem.value = false
    }
  }
  async function closeCurrent(): Promise<void> {
    if (!list.value || !list.value.is_creator || busy.value) return
    const listId = list.value.id
    const session = sessionRevision
    closing.value = true
    try {
      await shoppingApi.closeList(listId)
      if (session !== sessionRevision) return
      openLists.value = openLists.value.filter((candidate) => candidate.id !== listId)
      clearSelection()
      loaded.value = true
    } catch (failure) {
      if (session === sessionRevision) await mutationFailed(failure, listId)
      throw failure
    } finally {
      if (session === sessionRevision) closing.value = false
    }
  }
  watch(() => auth.user?.id, reset, { flush: 'sync' })
  return {
    list,
    openLists,
    selectedId,
    units,
    loading,
    loaded,
    error,
    closing,
    creating,
    addingItem,
    addingRecipe,
    removingRecipeId,
    pendingItemEdits,
    pendingChecks,
    itemErrors,
    removalBlocked,
    busy,
    selectionRequired,
    hasItems,
    summary,
    remainingSummary,
    load,
    reset,
    selectList,
    createOwn,
    updateSummary,
    setChecked,
    updateItem,
    deleteItem,
    addRecipe,
    removeRecipe,
    addManualItem,
    closeCurrent,
  }
})
