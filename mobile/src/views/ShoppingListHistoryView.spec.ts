// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
const mocks = vi.hoisted(() => ({
  getList: vi.fn(),
  getOpen: vi.fn(),
  closeList: vi.fn(),
  getConfig: vi.fn(),
  setItemChecked: vi.fn(),
  addManualItem: vi.fn(),
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
import { useNotificationStore } from '@/stores/notifications'
import type { ActiveShoppingList } from '@/types/shopping'
import ShoppingListSharing from '@/components/shopping/ShoppingListSharing.vue'

const item = {
  id: 3,
  name: 'Lapte',
  quantity: '500.000',
  unit: 'milliliter',
  is_checked: true,
  shopping_category: null,
}
const closed = {
  is_creator: true,
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
    mocks.getOpen.mockReset().mockResolvedValue({ data: [] })
    mocks.closeList.mockReset().mockResolvedValue({ data: closed })
    mocks.getConfig.mockReset().mockResolvedValue({ units: { milliliter: { label: 'ml' } } })
    mocks.push.mockReset()
    mocks.setItemChecked.mockReset()
    mocks.addManualItem.mockReset()
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
    expect(mocks.getOpen).not.toHaveBeenCalled()
  })
  it('requires confirmation, supports cancel, closes and returns to the index with empty active state', async () => {
    const active = { ...closed, status: 'open', closed_at: null }
    mocks.getList.mockResolvedValue({ data: active })
    await useActiveShoppingListStore().selectList(2)
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    await wrapper.find('.shopping-list__close').trigger('click')
    expect(wrapper.find('dialog').attributes('open')).toBeDefined()
    expect(wrapper.find('dialog').text()).toContain('Lista va fi mutată în istoric')
    expect(mocks.closeList).not.toHaveBeenCalled()
    await wrapper
      .findAll('dialog button')
      .find((button) => button.text() === 'Anulează')!
      .trigger('click')
    expect(wrapper.find('dialog').attributes('open')).toBeUndefined()
    await wrapper.find('.shopping-list__close').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.closeList).toHaveBeenCalledTimes(1)
    expect(useActiveShoppingListStore().list).toBeNull()
    expect(useActiveShoppingListStore().hasItems).toBe(false)
    expect(mocks.push).toHaveBeenCalledWith({ name: 'shopping-lists' })
  })
  it('retains the list and confirmation dialog after a failed close', async () => {
    const active = { ...closed, status: 'open' }
    mocks.getList.mockResolvedValue({ data: active })
    await useActiveShoppingListStore().selectList(2)
    mocks.closeList.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    await wrapper.find('.shopping-list__close').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Nu am putut închide lista')
    expect(useActiveShoppingListStore().list?.id).toBe(2)
    expect(mocks.push).not.toHaveBeenCalled()
  })
  it('allows a shared recipient to check and add items but not close or manage sharing', async () => {
    const shared = {
      ...closed,
      status: 'open',
      visibility: 'shared',
      is_creator: false,
      is_shared_with_me: true,
      created_by: 1,
      creator: { id: 1, name: 'daniel', username: 'daniel', avatar: null },
    }
    mocks.getList.mockResolvedValue({ data: structuredClone(shared) })
    mocks.setItemChecked.mockResolvedValue({ data: { ...item, is_checked: false } })
    await useActiveShoppingListStore().load()
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    expect(wrapper.text()).toContain('Partajată cu tine')
    expect(wrapper.text()).toContain('Creată de daniel')
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Folosește lista')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('.shopping-list__close').exists()).toBe(false)
    expect(wrapper.find('.shopping-sharing').exists()).toBe(false)
    expect(wrapper.find('.shopping-quick-add').exists()).toBe(true)
    await wrapper.get('input[type="checkbox"]').setValue(false)
    await flushPromises()
    expect(mocks.setItemChecked).toHaveBeenCalledWith(2, 3, false)
    mocks.addManualItem.mockResolvedValue({
      data: { ...item, id: 4, name: 'Dero', is_checked: false },
    })
    await wrapper.get('.shopping-quick-add__action').trigger('click')
    await wrapper.get('input[name="name"]').setValue('Dero')
    await wrapper.get('.shopping-quick-add form').trigger('submit')
    await flushPromises()
    expect(mocks.addManualItem).toHaveBeenCalledWith(2, {
      name: 'Dero',
      quantity: null,
      unit: null,
    })
    expect(wrapper.text()).toContain('Dero')
  })
  it('views another open list read-only until explicitly selected without changing current context', async () => {
    const shared = { ...closed, status: 'open', is_creator: false, is_shared_with_me: true }
    mocks.getList.mockResolvedValue({ data: shared })
    const shopping = useActiveShoppingListStore()
    shopping.list = { ...closed, id: 1, status: 'open' } as ActiveShoppingList
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    expect(wrapper.text()).toContain('Lapte')
    expect(wrapper.text()).toContain('Folosește lista')
    expect(wrapper.get('input[type="checkbox"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.shopping-quick-add').exists()).toBe(false)
    expect(shopping.list.id).toBe(1)
    expect(mocks.getOpen).not.toHaveBeenCalled()
  })
  it('merges visibility metadata without discarding list contents', async () => {
    mocks.getList.mockResolvedValue({
      data: { ...closed, visibility: 'private', recipes: [{ id: 12, name: 'Supă' }] },
    })
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    wrapper.getComponent(ShoppingListSharing).vm.$emit('updated', { id: 2, visibility: 'shared' })
    await flushPromises()
    expect(wrapper.text()).toContain('Lapte')
    expect(wrapper.text()).toContain('Partajată')
    await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
    expect(wrapper.text()).toContain('Supă')
  })
})
