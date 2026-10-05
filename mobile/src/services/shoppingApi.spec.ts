import { describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }))
vi.mock('@/services/api', () => ({ api: mocks }))
import { shoppingApi } from '@/services/shoppingApi'

describe('shoppingApi', () => {
  it('uses the active-list endpoints through the shared API client', async () => {
    await shoppingApi.getActive()
    await shoppingApi.addRecipe(12)
    await shoppingApi.addManualItem({ name: 'Dero', quantity: 1, unit: 'buc' })
    await shoppingApi.setItemChecked(3, true)
    await shoppingApi.list(2)
    await shoppingApi.getList(5)
    await shoppingApi.closeActive()
    await shoppingApi.removeRecipe(5, 12)
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists/active')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/active/recipes/12')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/active/items', {
      name: 'Dero',
      quantity: 1,
      unit: 'buc',
    })
    expect(mocks.patch).toHaveBeenCalledWith('/shopping-lists/active/items/3', { is_checked: true })
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists?page=2')
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists/5')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/active/close')
    expect(mocks.delete).toHaveBeenCalledWith('/shopping-lists/5/recipes/12')
  })
})
