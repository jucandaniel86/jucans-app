// @vitest-environment happy-dom
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { ApiError } from '@/services/api'
import type { ActiveShoppingList } from '@/types/shopping'

const mocks = vi.hoisted(() => ({
  getOpen: vi.fn(),
  getList: vi.fn(),
  createOwn: vi.fn(),
  addRecipe: vi.fn(),
  getConfig: vi.fn(),
  setItemChecked: vi.fn(),
  closeList: vi.fn(),
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
const own: ActiveShoppingList = {
  id: 10,
  name: 'Daniel',
  status: 'open',
  visibility: 'private',
  created_by: 1,
  creator: { id: 1, name: 'Daniel', username: 'Daniel', avatar: null },
  is_creator: true,
  is_shared_with_me: false,
  closed_at: null,
  created_at: null,
  items: [item],
  items_count: 1,
  unchecked_items_count: 1,
  recipes_count: 0,
  recipes: [],
}
const shared: ActiveShoppingList = {
  ...own,
  id: 20,
  name: 'Alina',
  visibility: 'shared',
  created_by: 2,
  creator: { id: 2, name: 'Alina', username: 'Alina', avatar: null },
  is_creator: false,
  is_shared_with_me: true,
  items: [{ ...item, id: 2 }],
}
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: Error) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}
describe('current shopping list state', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    useAuthStore().user = { id: 1, username: 'Daniel', avatar: null, is_admin: false }
    vi.resetAllMocks()
    mocks.getOpen.mockResolvedValue({ data: [structuredClone(own)] })
    mocks.getList.mockImplementation(async (id: number) => ({
      data: structuredClone(id === 20 ? shared : own),
    }))
    mocks.getConfig.mockResolvedValue({ units: { liter: { label: 'l' } } })
    mocks.createOwn.mockResolvedValue({ data: own })
    mocks.closeList.mockResolvedValue({ data: { ...own, status: 'closed' } })
  })
  it('deduplicates loads, safely defaults to a sole owned list, and caches navigation', async () => {
    const store = useActiveShoppingListStore()
    await Promise.all([store.load(), store.load()])
    await store.load()
    expect(mocks.getOpen).toHaveBeenCalledTimes(1)
    expect(mocks.getList).toHaveBeenCalledWith(10)
    expect(mocks.getConfig).toHaveBeenCalledTimes(1)
    expect(store.selectedId).toBe(10)
    expect(store.summary).toBe('1 produs · 0 rețete')
  })
  it('requires selection for multiple lists and never implicitly chooses even a sole shared list', async () => {
    const store = useActiveShoppingListStore()
    for (const data of [[shared, own], [shared]]) {
      mocks.getOpen.mockResolvedValue({ data })
      await store.load(true)
      expect(store.list).toBeNull()
      expect(store.selectionRequired).toBe(true)
      await expect(store.addRecipe(12)).rejects.toThrow('Alege lista curentă')
    }
    expect(mocks.addRecipe).not.toHaveBeenCalled()
    expect(mocks.getList).not.toHaveBeenCalled()
  })
  it('runs the Daniel/Alina flow with explicit targets and no mutation reloads', async () => {
    mocks.getOpen.mockResolvedValue({ data: [shared, own] })
    const store = useActiveShoppingListStore()
    await store.load()
    await store.selectList(20)
    const manual = { ...item, id: 3, name: 'Dero', ingredient_id: null }
    mocks.addManualItem.mockResolvedValue({ data: manual })
    await store.addManualItem({ name: 'Dero', quantity: null, unit: null })
    mocks.addRecipe.mockResolvedValue({
      data: {
        ...shared,
        items: [...shared.items, manual],
        items_count: 2,
        recipes: [{ id: 12, name: 'Supă' }],
        recipes_count: 1,
        already_present: false,
      },
    })
    await store.addRecipe(12)
    mocks.setItemChecked.mockResolvedValue({ data: { ...manual, is_checked: true } })
    await store.setChecked(3, true)
    expect(mocks.addManualItem).toHaveBeenCalledWith(20, {
      name: 'Dero',
      quantity: null,
      unit: null,
    })
    expect(mocks.addRecipe).toHaveBeenCalledWith(20, 12)
    expect(mocks.setItemChecked).toHaveBeenCalledWith(20, 3, true)
    expect(store.list?.recipes).toHaveLength(1)
    expect(mocks.getOpen).toHaveBeenCalledTimes(1)
    expect(mocks.getList).toHaveBeenCalledTimes(1)
    expect(own.items).toEqual([item])
    await store.selectList(10)
    await store.addManualItem({ name: 'Bread', quantity: null, unit: null })
    expect(mocks.addManualItem).toHaveBeenLastCalledWith(10, {
      name: 'Bread',
      quantity: null,
      unit: null,
    })
    expect(store.selectedId).toBe(10)
  })
  it('restores per-account selection across store recreation and never leaks it to another account', async () => {
    const store = useActiveShoppingListStore()
    await store.selectList(20)
    expect(localStorage.getItem('jucans.current-shopping-list.1')).toBe('20')
    store.reset()
    mocks.getOpen.mockResolvedValue({ data: [own, shared] })
    await store.load()
    expect(store.list?.id).toBe(20)
    useAuthStore().user = { id: 2, username: 'Alina', avatar: null, is_admin: false }
    mocks.getOpen.mockResolvedValue({ data: [] })
    await store.load()
    expect(store.selectedId).toBeNull()
  })
  it.each(['lost access', 'closed', 'deleted'])(
    'invalidates persisted selection after %s without falling into a shared list',
    async () => {
      localStorage.setItem('jucans.current-shopping-list.1', '99')
      mocks.getOpen.mockResolvedValue({ data: [shared] })
      const store = useActiveShoppingListStore()
      await store.load()
      expect(store.list).toBeNull()
      expect(localStorage.getItem('jucans.current-shopping-list.1')).toBeNull()
      expect(mocks.getList).not.toHaveBeenCalled()
    },
  )
  it('falls back only to an unambiguous owned list after selection is invalidated', async () => {
    localStorage.setItem('jucans.current-shopping-list.1', '99')
    const store = useActiveShoppingListStore()
    await store.load()
    expect(store.selectedId).toBe(10)
  })
  it('rejects closed-list selection without replacing the current list', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    mocks.getList.mockResolvedValueOnce({ data: { ...shared, status: 'closed' } })
    await expect(store.selectList(20)).rejects.toThrow('închisă')
    expect(store.selectedId).toBe(10)
  })
  it('creates an owned list explicitly when only shared lists exist', async () => {
    mocks.getOpen.mockResolvedValue({ data: [shared] })
    const store = useActiveShoppingListStore()
    await store.load()
    await store.createOwn()
    expect(mocks.createOwn).toHaveBeenCalledTimes(1)
    expect(store.selectedId).toBe(10)
  })
  it('creates an owned list for a recipe only when there are no accessible open lists', async () => {
    mocks.getOpen.mockResolvedValue({ data: [] })
    mocks.addRecipe.mockResolvedValue({ data: { ...own, already_present: false } })
    const store = useActiveShoppingListStore()
    await store.addRecipe(12)
    expect(mocks.createOwn).toHaveBeenCalledTimes(1)
    expect(mocks.addRecipe).toHaveBeenCalledWith(10, 12)
  })
  it('checks optimistically, blocks duplicate checks and rolls back network errors', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const request = deferred<{ data: typeof item }>()
    mocks.setItemChecked.mockReturnValueOnce(request.promise)
    const check = store.setChecked(1, true)
    expect(store.list?.items[0]?.is_checked).toBe(true)
    expect(store.remainingSummary).toBe('Totul cumpărat ✓')
    await store.setChecked(1, false)
    expect(mocks.setItemChecked).toHaveBeenCalledTimes(1)
    await expect(store.selectList(20)).rejects.toThrow('ocupată')
    request.reject(new Error('offline'))
    await check
    expect(store.list?.items[0]?.is_checked).toBe(false)
    expect(store.itemErrors[1]).toContain('Nu am putut salva')
  })
  it('preserves pending optimistic checks during refresh and ignores a stale snapshot', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const request = deferred<{ data: typeof item }>()
    mocks.setItemChecked.mockReturnValueOnce(request.promise)
    const check = store.setChecked(1, true)
    await store.load(true)
    expect(store.list?.items[0]?.is_checked).toBe(true)
    const old = deferred<{ data: ActiveShoppingList }>()
    mocks.getList.mockReturnValueOnce(old.promise)
    const refresh = store.load(true)
    await flushPromises()
    request.resolve({ data: { ...item, is_checked: true } })
    await check
    old.resolve({ data: structuredClone(own) })
    await refresh
    expect(store.list?.items[0]?.is_checked).toBe(true)
  })
  it('replaces removal contents and ignores an outstanding older GET', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const old = deferred<{ data: ActiveShoppingList }>()
    mocks.getList.mockReturnValueOnce(old.promise)
    const refresh = store.load(true)
    await flushPromises()
    const updated = { ...own, items: [], items_count: 0, recipes: [], recipes_count: 0 }
    mocks.removeRecipe.mockResolvedValue({ data: updated })
    await store.removeRecipe(12)
    old.resolve({ data: own })
    await refresh
    expect(mocks.removeRecipe).toHaveBeenCalledWith(10, 12)
    expect(store.list).toEqual(updated)
  })
  it.each(['closing', 'addingItem', 'addingRecipe', 'checking', 'closed'])(
    'blocks removal while %s',
    async (mode) => {
      const store = useActiveShoppingListStore()
      store.list = { ...structuredClone(own), status: mode === 'closed' ? 'closed' : 'open' }
      if (mode === 'checking') store.pendingChecks = { 1: true }
      else if (mode !== 'closed') store[mode as 'closing' | 'addingItem' | 'addingRecipe'] = true
      await expect(store.removeRecipe(12)).rejects.toThrow()
      expect(mocks.removeRecipe).not.toHaveBeenCalled()
    },
  )
  it('blocks conflicting mutations during removal and retains state after failure', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const request = deferred<{ data: ActiveShoppingList }>()
    mocks.removeRecipe.mockReturnValueOnce(request.promise)
    const removal = store.removeRecipe(12)
    const failed = expect(removal).rejects.toThrow('offline')
    await expect(store.removeRecipe(12)).rejects.toThrow()
    await expect(
      store.addManualItem({ name: 'Dero', quantity: null, unit: null }),
    ).rejects.toThrow()
    await expect(store.addRecipe(13)).rejects.toThrow()
    await store.setChecked(1, true)
    await store.closeCurrent()
    request.reject(new Error('offline'))
    await failed
    expect(store.list).toEqual(own)
    expect(mocks.closeList).not.toHaveBeenCalled()
    expect(mocks.setItemChecked).not.toHaveBeenCalled()
  })
  it('adds manual items without reload and rejects stale detail responses', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const old = deferred<{ data: ActiveShoppingList }>()
    mocks.getList.mockReturnValueOnce(old.promise)
    const refresh = store.load(true)
    await flushPromises()
    mocks.addManualItem.mockResolvedValue({ data: { ...item, id: 3, name: 'Dero' } })
    await store.addManualItem({ name: 'Dero', quantity: null, unit: null })
    old.resolve({ data: own })
    await refresh
    expect(store.list?.items_count).toBe(2)
    expect(store.remainingSummary).toBe('2 de cumpărat · 0 rețete')
  })
  it('ignores late mutations and reads after logout', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const request = deferred<{ data: typeof item }>()
    mocks.addManualItem.mockReturnValueOnce(request.promise)
    const addition = store.addManualItem({ name: 'Dero', quantity: null, unit: null })
    const failed = expect(addition).rejects.toThrow('Shopping list changed')
    useAuthStore().user = null
    request.resolve({ data: item })
    await failed
    expect(store.list).toBeNull()
    expect(store.addingItem).toBe(false)
    const old = deferred<{ data: ActiveShoppingList[] }>()
    mocks.getOpen.mockReturnValueOnce(old.promise)
    const refresh = store.load()
    store.reset()
    old.resolve({ data: [own] })
    await refresh
    expect(store.list).toBeNull()
  })
  it('closes only the explicit owned current list and cannot resurrect it from a stale GET', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const old = deferred<{ data: ActiveShoppingList }>()
    mocks.getList.mockReturnValueOnce(old.promise)
    const refresh = store.load(true)
    await flushPromises()
    await store.closeCurrent()
    old.resolve({ data: own })
    await refresh
    expect(mocks.closeList).toHaveBeenCalledWith(10)
    expect(store.list).toBeNull()
    expect(localStorage.getItem('jucans.current-shopping-list.1')).toBeNull()
    await store.selectList(20)
    await store.closeCurrent()
    expect(mocks.closeList).toHaveBeenCalledTimes(1)
  })
  it('invalidates a current list when an explicit write loses access without retrying the write elsewhere', async () => {
    const store = useActiveShoppingListStore()
    await store.selectList(20)
    mocks.getOpen.mockResolvedValue({ data: [own] })
    mocks.addManualItem.mockRejectedValueOnce(new ApiError('Forbidden', 403))
    await expect(
      store.addManualItem({ name: 'Dero', quantity: null, unit: null }),
    ).rejects.toThrow()
    expect(store.selectedId).toBe(10)
    expect(mocks.addManualItem).toHaveBeenCalledTimes(1)
  })
})
