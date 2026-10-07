import { describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  getText: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))
vi.mock('@/services/api', () => ({ api: mocks }))
import { shoppingApi } from '@/services/shoppingApi'

describe('shoppingApi', () => {
  it('uses explicit list IDs for all mutations through the shared API client', async () => {
    await shoppingApi.getOpen()
    await shoppingApi.addRecipe(5, 12)
    await shoppingApi.addManualItem(5, { name: 'Dero', quantity: 1, unit: 'buc' })
    await shoppingApi.setItemChecked(5, 3, true)
    await shoppingApi.updateItem(5, 3, { name: 'Saci', quantity: 2, unit: 'buc' })
    await shoppingApi.deleteItem(5, 3)
    await shoppingApi.exportText(5)
    await shoppingApi.list(2)
    await shoppingApi.getList(5)
    await shoppingApi.closeList(5)
    await shoppingApi.createOwn()
    await shoppingApi.getShareableUsers()
    await shoppingApi.removeRecipe(5, 12)
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists/open')
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists/shareable-users')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/5/recipes/12')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/5/items', {
      name: 'Dero',
      quantity: 1,
      unit: 'buc',
    })
    expect(mocks.patch).toHaveBeenCalledWith('/shopping-lists/5/items/3', { is_checked: true })
    expect(mocks.patch).toHaveBeenCalledWith('/shopping-lists/5/items/3', {
      name: 'Saci',
      quantity: 2,
      unit: 'buc',
    })
    expect(mocks.delete).toHaveBeenCalledWith('/shopping-lists/5/items/3')
    expect(mocks.getText).toHaveBeenCalledWith('/shopping-lists/5/export')
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists?page=2')
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists/5')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/5/close')
    expect(mocks.delete).toHaveBeenCalledWith('/shopping-lists/5/recipes/12')
  })
  it('uses the existing sharing contracts with explicit list and account IDs', async () => {
    await shoppingApi.getUsers(5)
    await shoppingApi.attachUser(5, 2)
    await shoppingApi.detachUser(5, 2)
    await shoppingApi.setVisibility(5, 'shared')
    expect(mocks.get).toHaveBeenCalledWith('/shopping-lists/5/users')
    expect(mocks.post).toHaveBeenCalledWith('/shopping-lists/5/users', { user_id: 2 })
    expect(mocks.delete).toHaveBeenCalledWith('/shopping-lists/5/users/2')
    expect(mocks.patch).toHaveBeenCalledWith('/shopping-lists/5/visibility', {
      visibility: 'shared',
    })
  })
})
