// @vitest-environment happy-dom
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import type { ActiveShoppingList } from '@/types/shopping'

const mocks = vi.hoisted(() => ({
  getActive: vi.fn(),
  addRecipe: vi.fn(),
  getConfig: vi.fn(),
  setItemChecked: vi.fn(),
  closeActive: vi.fn(),
  addManualItem: vi.fn(),
  removeRecipe: vi.fn(),
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useAuthStore } from '@/stores/auth'

const item = {
  id: 1,
  name: 'Lapte',
  quantity: '1.000',
  unit: 'liter',
  shopping_category: null,
  is_checked: false,
  ingredient_id: 1,
  calculated_quantity: '1.000',
  quantity_overridden: false,
}
const list: ActiveShoppingList = {
  id: 1,
  name: null,
  status: 'open',
  visibility: 'private',
  created_by: 1,
  closed_at: null,
  created_at: null,
  items: [item],
  items_count: 1,
  unchecked_items_count: 1,
  recipes_count: 1,
}

describe('active shopping list state', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.getActive.mockReset().mockResolvedValue({ data: null })
    mocks.addRecipe.mockReset().mockResolvedValue({ data: { ...list, already_present: false } })
    mocks.getConfig.mockReset().mockResolvedValue({ units: { liter: { label: 'l' } } })
    mocks.setItemChecked.mockReset()
    mocks.closeActive.mockReset().mockResolvedValue(undefined)
    mocks.addManualItem.mockReset()
    mocks.removeRecipe.mockReset()
  })

  it('deduplicates simultaneous loads and caches state across normal navigation', async () => {
    mocks.getActive.mockResolvedValue({ data: list })
    const store = useActiveShoppingListStore()
    await Promise.all([store.load(), store.load()])
    await store.load()
    expect(mocks.getActive).toHaveBeenCalledTimes(1)
    expect(mocks.getConfig).toHaveBeenCalledTimes(1)
    expect(store.summary).toBe('1 produs · 1 rețetă')
  })

  it('replaces the full removal payload and ignores an outstanding older GET', async () => {
    const store = useActiveShoppingListStore()
    store.list = { ...structuredClone(list), recipes: [{ id: 12, name: 'Supă' }] }
    let resolve!: (value: unknown) => void
    mocks.getActive.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const refresh = store.load(true)
    const updated = {
      ...list,
      items: [{ ...item, quantity: '0.500' }],
      recipes: [],
      recipes_count: 0,
    }
    mocks.removeRecipe.mockResolvedValue({ data: updated })
    await store.removeRecipe(12)
    expect(mocks.removeRecipe).toHaveBeenCalledWith(1, 12)
    expect(store.list).toEqual(updated)
    expect(store.loaded).toBe(true)
    resolve({ data: structuredClone(list) })
    await refresh
    expect(store.list).toEqual(updated)
  })

  it('blocks duplicate and conflicting mutations during removal and preserves state on failure', async () => {
    const store = useActiveShoppingListStore()
    store.list = structuredClone(list)
    let reject!: (error: Error) => void
    mocks.removeRecipe.mockReturnValueOnce(
      new Promise((_, fail) => {
        reject = fail
      }),
    )
    const removal = store.removeRecipe(12)
    const failed = expect(removal).rejects.toThrow('offline')
    await expect(store.removeRecipe(12)).rejects.toThrow()
    await expect(
      store.addManualItem({ name: 'Dero', quantity: null, unit: null }),
    ).rejects.toThrow()
    await expect(store.addRecipe(13)).rejects.toThrow()
    await store.setChecked(1, true)
    await store.closeActive()
    expect(mocks.removeRecipe).toHaveBeenCalledTimes(1)
    expect(mocks.addRecipe).not.toHaveBeenCalled()
    expect(mocks.addManualItem).not.toHaveBeenCalled()
    expect(mocks.setItemChecked).not.toHaveBeenCalled()
    expect(mocks.closeActive).not.toHaveBeenCalled()
    reject(new Error('offline'))
    await failed
    expect(store.list).toEqual(list)
    expect(store.removingRecipeId).toBeNull()
  })

  it.each(['closing', 'addingItem', 'addingRecipe', 'checking', 'closed'])(
    'blocks removal while %s',
    async (mode) => {
      const store = useActiveShoppingListStore()
      store.list = { ...structuredClone(list), status: mode === 'closed' ? 'closed' : 'open' }
      if (mode === 'checking') store.pendingChecks = { 1: true }
      else if (mode !== 'closed') store[mode as 'closing' | 'addingItem' | 'addingRecipe'] = true
      await expect(store.removeRecipe(12)).rejects.toThrow()
      expect(mocks.removeRecipe).not.toHaveBeenCalled()
    },
  )

  it('ignores a removal response after session reset', async () => {
    const store = useActiveShoppingListStore()
    store.list = structuredClone(list)
    let resolve!: (value: unknown) => void
    mocks.removeRecipe.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const removal = store.removeRecipe(12)
    const failed = expect(removal).rejects.toThrow('Shopping list changed')
    store.reset()
    resolve({ data: { ...list, recipes: [], recipes_count: 0 } })
    await failed
    expect(store.list).toBeNull()
    expect(store.removingRecipeId).toBeNull()
  })

  it('retains known attachments on navigation when legacy GET responses omit recipes', async () => {
    const store = useActiveShoppingListStore()
    store.list = { ...structuredClone(list), recipes: [{ id: 12, name: 'Supă' }] }
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    await store.load(true)
    expect(store.list?.recipes).toEqual([{ id: 12, name: 'Supă' }])
    mocks.getActive.mockResolvedValue({ data: { ...list, recipes_count: 2 } })
    await store.load(true)
    expect(store.list?.recipes).toBeUndefined()
  })

  it('keeps recipe summaries learned from addition in the same active list state', async () => {
    const store = useActiveShoppingListStore()
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    mocks.addRecipe.mockResolvedValue({
      data: { ...list, recipe: { id: 12, name: 'Supă' }, already_present: false },
    })
    await store.addRecipe(12)
    expect(store.list?.recipes).toEqual([{ id: 12, name: 'Supă' }])
    await store.load(true)
    expect(store.list?.recipes).toEqual([{ id: 12, name: 'Supă' }])
  })

  it('inserts a manual item and updates counts while rejecting a stale GET', async () => {
    const store = useActiveShoppingListStore()
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    await store.load()
    let resolve!: (value: unknown) => void
    mocks.getActive.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const refresh = store.load(true)
    const manual = {
      ...item,
      id: 2,
      name: 'Dero',
      ingredient_id: null,
      calculated_quantity: null,
      unit: 'buc',
      quantity_overridden: true,
    }
    mocks.addManualItem.mockResolvedValue({ data: manual })
    await store.addManualItem({ name: 'Dero', quantity: 1, unit: 'buc' })
    expect(store.list?.items).toHaveLength(2)
    expect(store.list?.items_count).toBe(2)
    expect(store.remainingSummary).toBe('2 de cumpărat · 1 rețetă')
    resolve({ data: structuredClone(list) })
    await refresh
    expect(store.list?.items[1]).toEqual(manual)
    expect(mocks.getActive).toHaveBeenCalledTimes(2)
  })

  it('blocks simultaneous additions and closing, and preserves state after failure', async () => {
    const store = useActiveShoppingListStore()
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    await store.load()
    let reject!: (error: Error) => void
    mocks.addManualItem.mockReturnValueOnce(
      new Promise((_, fail) => {
        reject = fail
      }),
    )
    const input = { name: 'Dero', quantity: null, unit: null }
    const addition = store.addManualItem(input)
    const failed = expect(addition).rejects.toThrow('offline')
    await expect(store.addManualItem(input)).rejects.toThrow()
    await store.closeActive()
    expect(mocks.closeActive).not.toHaveBeenCalled()
    expect(mocks.addManualItem).toHaveBeenCalledTimes(1)
    reject(new Error('offline'))
    await failed
    expect(store.list?.items_count).toBe(1)
    expect(store.addingItem).toBe(false)
  })

  it('ignores a manual addition response after logout', async () => {
    const auth = useAuthStore()
    auth.user = { id: 1, username: 'daniel', avatar: null, is_admin: false }
    const store = useActiveShoppingListStore()
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    await store.load()
    let resolve!: (value: unknown) => void
    mocks.addManualItem.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const addition = store.addManualItem({ name: 'Dero', quantity: null, unit: null })
    const failed = expect(addition).rejects.toThrow('Shopping list changed')
    auth.user = null
    resolve({ data: { ...item, id: 2 } })
    await failed
    expect(store.list).toBeNull()
    expect(store.addingItem).toBe(false)
  })

  it('does not resurrect the active list from an outstanding GET after closing', async () => {
    const store = useActiveShoppingListStore()
    let resolve!: (value: unknown) => void
    mocks.getActive.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const request = store.load()
    await store.closeActive()
    resolve({ data: structuredClone(list) })
    await request
    expect(store.list).toBeNull()
    expect(store.hasItems).toBe(false)
    expect(store.loaded).toBe(true)
  })

  it('appears after adding a recipe and reloads the complete list rather than only affected items', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const complete = {
      ...list,
      items: [item, { ...item, id: 2, name: 'Cimbru', quantity: null }],
      items_count: 2,
      recipes_count: 3,
    }
    mocks.getActive.mockResolvedValue({ data: complete })
    mocks.addRecipe.mockResolvedValue({
      data: { ...complete, items: [item], already_present: false },
    })
    await store.addRecipe(12)
    expect(mocks.addRecipe).toHaveBeenCalledWith(12)
    expect(store.list?.items).toHaveLength(2)
    expect(store.hasItems).toBe(true)
    expect(store.summary).toBe('2 produse · 3 rețete')
  })

  it('keeps unaffected items while updating an existing contribution', async () => {
    const complete = {
      ...list,
      items: [item, { ...item, id: 2, name: 'Cimbru', quantity: null }],
      items_count: 2,
    }
    mocks.getActive.mockResolvedValue({ data: complete })
    const store = useActiveShoppingListStore()
    await store.load()
    mocks.addRecipe.mockResolvedValue({
      data: { ...complete, items: [{ ...item, quantity: '2.000' }], recipes_count: 2 },
    })
    mocks.getActive.mockRejectedValueOnce(new Error('refresh offline'))
    await store.addRecipe(12)
    expect(store.list?.items).toHaveLength(2)
    expect(store.list?.items[0]?.quantity).toBe('2.000')
    expect(store.list?.recipes_count).toBe(2)
  })

  it('does not let an older request overwrite a successful addition', async () => {
    let resolve!: (value: unknown) => void
    mocks.getActive
      .mockReturnValueOnce(
        new Promise((done) => {
          resolve = done
        }),
      )
      .mockResolvedValue({ data: list })
    const store = useActiveShoppingListStore()
    const old = store.load()
    await store.addRecipe(12)
    resolve({ data: null })
    await old
    expect(store.hasItems).toBe(true)
  })

  it('clears data on logout and ignores outstanding requests from the previous user', async () => {
    const auth = useAuthStore()
    auth.user = { id: 1, username: 'daniel', avatar: null, is_admin: false }
    const store = useActiveShoppingListStore()
    let resolve!: (value: unknown) => void
    mocks.getActive.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const old = store.load()
    auth.user = null
    resolve({ data: list })
    await old
    await flushPromises()
    expect(store.list).toBeNull()
    expect(store.loaded).toBe(false)
  })

  it('checks optimistically, prevents duplicate toggles, and unchecks with remaining counts', async () => {
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    const store = useActiveShoppingListStore()
    await store.load()
    let resolve!: (value: unknown) => void
    mocks.setItemChecked.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const request = store.setChecked(1, true)
    expect(store.list?.items[0]?.is_checked).toBe(true)
    expect(store.list?.unchecked_items_count).toBe(0)
    expect(store.remainingSummary).toBe('Totul cumpărat ✓')
    await store.setChecked(1, false)
    expect(mocks.setItemChecked).toHaveBeenCalledTimes(1)
    resolve({ data: { ...item, is_checked: true } })
    await request
    mocks.setItemChecked.mockResolvedValueOnce({ data: item })
    await store.setChecked(1, false)
    expect(store.list?.unchecked_items_count).toBe(1)
    expect(store.list?.items[0]?.quantity).toBe('1.000')
    expect(store.remainingSummary).toBe('1 de cumpărat · 1 rețetă')
  })

  it('rolls back a failed toggle and exposes retry feedback', async () => {
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    const store = useActiveShoppingListStore()
    await store.load()
    mocks.setItemChecked.mockRejectedValueOnce(new Error('offline'))
    await store.setChecked(1, true)
    expect(store.list?.items[0]?.is_checked).toBe(false)
    expect(store.list?.unchecked_items_count).toBe(1)
    expect(store.itemErrors[1]).toContain('Nu am putut salva')
    expect(store.pendingChecks[1]).toBeUndefined()
  })

  it('preserves an optimistic check during a refresh and rejects late stale snapshots', async () => {
    mocks.getActive.mockResolvedValue({ data: structuredClone(list) })
    const store = useActiveShoppingListStore()
    await store.load()
    let resolve!: (value: unknown) => void
    mocks.setItemChecked.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const check = store.setChecked(1, true)
    await store.load(true)
    expect(store.list?.items[0]?.is_checked).toBe(true)
    let resolveLoad!: (value: unknown) => void
    mocks.getActive.mockReturnValueOnce(
      new Promise((done) => {
        resolveLoad = done
      }),
    )
    const refresh = store.load(true)
    resolve({ data: { ...item, is_checked: true } })
    await check
    resolveLoad({ data: structuredClone(list) })
    await refresh
    expect(store.list?.items[0]?.is_checked).toBe(true)
  })
})
