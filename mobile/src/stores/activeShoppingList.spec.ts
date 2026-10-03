// @vitest-environment happy-dom
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  getActive: vi.fn(),
  addRecipe: vi.fn(),
  getConfig: vi.fn(),
  setItemChecked: vi.fn(),
  closeActive: vi.fn(),
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
}
const list = { id: 1, items: [item], items_count: 1, unchecked_items_count: 1, recipes_count: 1 }

describe('active shopping list state', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.getActive.mockReset().mockResolvedValue({ data: null })
    mocks.addRecipe.mockReset().mockResolvedValue({ data: { ...list, already_present: false } })
    mocks.getConfig.mockReset().mockResolvedValue({ units: { liter: { label: 'l' } } })
    mocks.setItemChecked.mockReset()
    mocks.closeActive.mockReset().mockResolvedValue(undefined)
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
