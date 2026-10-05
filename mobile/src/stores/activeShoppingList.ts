import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'

import { foodApi } from '@/services/foodApi'
import { shoppingApi } from '@/services/shoppingApi'
import { useAuthStore } from '@/stores/auth'
import type { FoodUnit } from '@/types/food'
import type {
  ActiveShoppingList,
  ManualShoppingItemInput,
  ShoppingRecipeResult,
} from '@/types/shopping'
import { shoppingListSummary } from '@/utils/shoppingPresentation'

export const useActiveShoppingListStore = defineStore('active-shopping-list', () => {
  const list = ref<ActiveShoppingList | null>(null)
  const units = ref<Record<string, FoodUnit>>({})
  const loading = ref(true)
  const error = ref('')
  const loaded = ref(false)
  const closing = ref(false)
  const addingItem = ref(false)
  const addingRecipe = ref(false)
  const removingRecipeId = ref<number | null>(null)
  const pendingChecks = ref<Record<number, boolean>>({})
  const removalBlocked = computed(
    () =>
      closing.value ||
      addingItem.value ||
      addingRecipe.value ||
      Object.keys(pendingChecks.value).length > 0,
  )
  const itemErrors = ref<Record<number, string>>({})
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

  function reset(): void {
    sessionRevision++
    revision++
    pending = null
    list.value = null
    units.value = {}
    loaded.value = false
    loading.value = false
    error.value = ''
    pendingChecks.value = {}
    itemErrors.value = {}
    addingItem.value = false
    addingRecipe.value = false
    removingRecipeId.value = null
  }

  function recount(): void {
    if (list.value)
      list.value.unchecked_items_count = list.value.items.filter((item) => !item.is_checked).length
  }

  function preservePendingChecks(): void {
    list.value?.items.forEach((item) => {
      const desired = pendingChecks.value[item.id]
      if (desired !== undefined) item.is_checked = desired
    })
    recount()
  }

  function invalidateRequests(): void {
    revision++
    pending = null
    loading.value = false
  }

  async function setChecked(itemId: number, checked: boolean): Promise<void> {
    const item = list.value?.items.find((item) => item.id === itemId)
    if (
      !item ||
      closing.value ||
      removingRecipeId.value !== null ||
      list.value?.status === 'closed' ||
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
      const response = await shoppingApi.setItemChecked(itemId, checked)
      if (session !== sessionRevision || list.value?.id !== listId) return
      const current = list.value.items.find((item) => item.id === itemId)
      if (current) current.is_checked = response.data.is_checked
    } catch {
      if (session !== sessionRevision || list.value?.id !== listId) return
      const current = list.value.items.find((item) => item.id === itemId)
      if (current) current.is_checked = previous
      itemErrors.value[itemId] = 'Nu am putut salva modificarea. Încearcă din nou.'
    } finally {
      if (session === sessionRevision) {
        invalidateRequests()
        delete pendingChecks.value[itemId]
        recount()
      }
    }
  }

  function load(force = false): Promise<void> {
    if (pending) return pending
    if (loaded.value && !force) return Promise.resolve()
    const currentRevision = revision
    const currentSession = sessionRevision
    loading.value = true
    error.value = ''
    pending = (async () => {
      try {
        const response = await shoppingApi.getActive()
        if (currentRevision !== revision) return
        list.value = response.data ? retainRecipes(response.data) : null
        preservePendingChecks()
        if (response.data?.items.length && Object.keys(units.value).length === 0) {
          const config = await foodApi.getConfig()
          if (currentSession !== sessionRevision) return
          units.value = config.units
          if (currentRevision !== revision) return
        }
        loaded.value = true
      } catch {
        if (currentRevision === revision) {
          error.value = 'Nu am putut încărca lista de cumpărături. Încearcă din nou.'
        }
      } finally {
        if (currentRevision === revision) {
          loading.value = false
          pending = null
        }
      }
    })()
    return pending
  }

  async function synchronizeAddition(result: ShoppingRecipeResult): Promise<void> {
    // An earlier GET must not overwrite the successful addition with an older snapshot.
    revision++
    pending = null
    loaded.value = false
    if (list.value?.id === result.id || result.items.length === result.items_count) {
      const items = new Map(
        (list.value?.id === result.id ? list.value.items : []).map((item) => [item.id, item]),
      )
      result.items.forEach((item) => items.set(item.id, item))
      const recipes = list.value?.id === result.id ? list.value.recipes : undefined
      const attached = recipes ? new Map(recipes.map((recipe) => [recipe.id, recipe])) : new Map()
      if (result.recipe) attached.set(result.recipe.id, result.recipe)
      list.value = {
        ...result,
        items: [...items.values()],
        recipes: attached.size === result.recipes_count ? [...attached.values()] : undefined,
      }
      preservePendingChecks()
    }
    await load(true)
  }

  async function addRecipe(recipeId: number) {
    if (removingRecipeId.value !== null || addingRecipe.value || closing.value)
      throw new Error('Shopping list is busy')
    const session = sessionRevision
    addingRecipe.value = true
    try {
      const response = await shoppingApi.addRecipe(recipeId)
      if (session === sessionRevision) await synchronizeAddition(response.data)
      return response
    } finally {
      if (session === sessionRevision) addingRecipe.value = false
    }
  }

  function retainRecipes(incoming: ActiveShoppingList): ActiveShoppingList {
    if (incoming.recipes) return incoming
    if (incoming.recipes_count === 0) return { ...incoming, recipes: [] }
    // Legacy GET responses omit recipes; retain only a complete known attachment set.
    const recipes = list.value && list.value.id === incoming.id ? list.value.recipes : undefined
    return recipes?.length === incoming.recipes_count ? { ...incoming, recipes } : incoming
  }

  async function removeRecipe(recipeId: number): Promise<void> {
    if (
      !list.value ||
      list.value.status === 'closed' ||
      removingRecipeId.value !== null ||
      removalBlocked.value
    )
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    removingRecipeId.value = recipeId
    try {
      const response = await shoppingApi.removeRecipe(listId, recipeId)
      if (session !== sessionRevision || list.value?.id !== listId || response.data.id !== listId)
        throw new Error('Shopping list changed')
      invalidateRequests()
      list.value = response.data
      loaded.value = true
      error.value = ''
      itemErrors.value = {}
    } finally {
      if (session === sessionRevision) removingRecipeId.value = null
    }
  }

  async function addManualItem(input: ManualShoppingItemInput): Promise<void> {
    if (
      !list.value ||
      list.value.status === 'closed' ||
      closing.value ||
      addingItem.value ||
      removingRecipeId.value !== null
    )
      throw new Error('Shopping list is unavailable')
    const listId = list.value.id
    const session = sessionRevision
    addingItem.value = true
    try {
      const response = await shoppingApi.addManualItem(input)
      if (session !== sessionRevision || list.value?.id !== listId)
        throw new Error('Shopping list changed')
      invalidateRequests()
      if (!list.value.items.some((item) => item.id === response.data.id))
        list.value.items.push(response.data)
      list.value.items_count = list.value.items.length
      recount()
    } finally {
      if (session === sessionRevision) addingItem.value = false
    }
  }

  const auth = useAuthStore()
  async function closeActive(): Promise<void> {
    if (
      closing.value ||
      addingItem.value ||
      addingRecipe.value ||
      removingRecipeId.value !== null ||
      Object.keys(pendingChecks.value).length
    )
      return
    closing.value = true
    const session = sessionRevision
    try {
      await shoppingApi.closeActive()
      if (session === sessionRevision) {
        reset()
        loaded.value = true
      }
    } finally {
      closing.value = false
    }
  }
  watch(() => auth.user?.id, reset, { flush: 'sync' })

  return {
    list,
    units,
    loading,
    error,
    loaded,
    closing,
    addingItem,
    addingRecipe,
    removingRecipeId,
    removalBlocked,
    hasItems,
    summary,
    remainingSummary,
    pendingChecks,
    itemErrors,
    load,
    reset,
    addRecipe,
    removeRecipe,
    addManualItem,
    setChecked,
    closeActive,
  }
})
