// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
const mocks = vi.hoisted(() => ({
  getList: vi.fn(),
  getActive: vi.fn(),
  closeActive: vi.fn(),
  getConfig: vi.fn(),
  push: vi.fn(),
  route: { params: { id: '2' } },
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
vi.mock('vue-router', () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ push: mocks.push }),
  RouterLink: { template: '<a><slot /></a>' },
}))
import ShoppingListView from '@/views/ShoppingListView.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import type { ActiveShoppingList } from '@/types/shopping'

const item = {
  id: 3,
  name: 'Lapte',
  quantity: '500.000',
  unit: 'milliliter',
  is_checked: true,
  shopping_category: null,
}
const closed = {
  id: 2,
  name: null,
  status: 'closed',
  closed_at: '2026-09-28T12:00:00Z',
  items: [item],
  items_count: 1,
  recipes_count: 1,
  unchecked_items_count: 0,
}

describe('shopping list history and closing', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.route.params.id = '2'
    mocks.getList.mockReset().mockResolvedValue({ data: structuredClone(closed) })
    mocks.getActive.mockReset().mockResolvedValue({ data: null })
    mocks.closeActive.mockReset().mockResolvedValue({ data: closed })
    mocks.getConfig.mockReset().mockResolvedValue({ units: { milliliter: { label: 'ml' } } })
    mocks.push.mockReset()
  })
  it('renders history read-only with checked state, date and quantities without replacing active data', async () => {
    const shopping = useActiveShoppingListStore()
    shopping.list = { ...closed, id: 1, status: 'open' } as ActiveShoppingList
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    expect(wrapper.find('h1').text()).toBe('28 septembrie 2026')
    expect(wrapper.text()).toContain('Închisă')
    expect(wrapper.text()).toContain('500 ml')
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
    expect(wrapper.find('input').element).toHaveProperty('checked', true)
    expect(wrapper.find('.shopping-list__close').exists()).toBe(false)
    expect(wrapper.find('.shopping-quick-add').exists()).toBe(false)
    expect(shopping.list.id).toBe(1)
    expect(mocks.getActive).not.toHaveBeenCalled()
  })
  it('requires confirmation, supports cancel, closes and returns to the index with empty active state', async () => {
    const active = { ...closed, status: 'open', closed_at: null }
    mocks.getList.mockResolvedValue({ data: active })
    mocks.getActive.mockResolvedValue({ data: structuredClone(active) })
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    await wrapper.find('.shopping-list__close').trigger('click')
    expect(wrapper.find('dialog').attributes('open')).toBeDefined()
    expect(wrapper.find('dialog').text()).toContain('Lista va fi mutată în istoric')
    expect(mocks.closeActive).not.toHaveBeenCalled()
    await wrapper
      .findAll('dialog button')
      .find((button) => button.text() === 'Anulează')!
      .trigger('click')
    expect(wrapper.find('dialog').attributes('open')).toBeUndefined()
    await wrapper.find('.shopping-list__close').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.closeActive).toHaveBeenCalledTimes(1)
    expect(useActiveShoppingListStore().list).toBeNull()
    expect(useActiveShoppingListStore().hasItems).toBe(false)
    expect(mocks.push).toHaveBeenCalledWith({ name: 'shopping-lists' })
  })
  it('retains the list and confirmation dialog after a failed close', async () => {
    const active = { ...closed, status: 'open' }
    mocks.getList.mockResolvedValue({ data: active })
    mocks.getActive.mockResolvedValue({ data: structuredClone(active) })
    mocks.closeActive.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    await wrapper.find('.shopping-list__close').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('dialog [role="alert"]').text()).toContain('Nu am putut închide lista')
    expect(useActiveShoppingListStore().list?.id).toBe(2)
    expect(mocks.push).not.toHaveBeenCalled()
  })
})
