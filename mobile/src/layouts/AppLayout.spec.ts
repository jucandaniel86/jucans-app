// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const mocks = vi.hoisted(() => ({
  getOpen: vi.fn(),
  getList: vi.fn(),
  getConfig: vi.fn(),
  setItemChecked: vi.fn(),
}))
vi.mock('@/services/shoppingApi', () => ({
  shoppingApi: {
    getOpen: mocks.getOpen,
    getList: mocks.getList,
    setItemChecked: mocks.setItemChecked,
  },
}))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
let route: {
  name: string
  fullPath: string
  meta: { requiresAdmin?: boolean; shoppingList?: 'floating' | 'header' | 'hidden' }
}
vi.mock('vue-router', () => ({
  useRoute: () => route,
  RouterView: { template: '<div />' },
  RouterLink: { template: '<a><slot /></a>' },
}))
import AppLayout from '@/layouts/AppLayout.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'

describe('shopping preview shell placement', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    route = reactive({ name: 'recipes', fullPath: '/recipes', meta: { shoppingList: 'floating' } })
    mocks.getOpen
      .mockReset()
      .mockResolvedValue({ data: [{ id: 1, status: 'open', is_creator: true }] })
    mocks.getList.mockReset().mockResolvedValue({
      data: {
        id: 1,
        status: 'open',
        is_creator: true,
        items: [
          { id: 1, name: 'Lapte', quantity: null, shopping_category: null, is_checked: false },
        ],
        items_count: 1,
        recipes_count: 1,
      },
    })
    mocks.getConfig.mockReset().mockResolvedValue({ units: {} })
    document.body.style.overflow = ''
    mocks.setItemChecked.mockReset().mockImplementation(async (_listId, id, is_checked) => ({
      data: { id, name: 'Lapte', quantity: null, shopping_category: null, is_checked },
    }))
  })

  it('persists across normal routes without refetching and hides on the full list and admin routes', async () => {
    const wrapper = mount(AppLayout, { global: { stubs: { AppDrawer: true, AppHeader: true } } })
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(true)
    route.name = 'recipe-detail'
    route.fullPath = '/recipes/12'
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(true)
    expect(mocks.getOpen).toHaveBeenCalledTimes(1)
    expect(mocks.getList).toHaveBeenCalledTimes(1)
    route.meta.shoppingList = 'hidden'
    route.name = 'shopping-list'
    route.fullPath = '/shopping-list'
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(false)
    route.name = 'admin-ingredients'
    route.meta.requiresAdmin = true
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(false)
    wrapper.unmount()
  })

  it('locks background scrolling while expanded and restores it on navigation', async () => {
    const wrapper = mount(AppLayout, { global: { stubs: { AppDrawer: true, AppHeader: true } } })
    await flushPromises()
    await wrapper.find('.floating-shopping__toggle').trigger('click')
    expect(document.body.style.overflow).toBe('hidden')
    expect(wrapper.find('.app-layout__page').attributes('inert')).toBeDefined()
    route.meta.shoppingList = 'hidden'
    route.name = 'shopping-list'
    route.fullPath = '/shopping-list'
    await flushPromises()
    expect(document.body.style.overflow).toBe('')
    wrapper.unmount()
  })
  function renderHeader() {
    delete route.meta.shoppingList
    return mount(AppLayout, {
      attachTo: document.body,
      global: { stubs: { AppDrawer: true } },
    })
  }

  it('defaults to header, checks products and updates the badge without refetching', async () => {
    const wrapper = renderHeader()
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(false)
    expect(wrapper.find('.app-layout__content--shopping').exists()).toBe(false)
    expect(wrapper.find('.app-header__shopping-count').text()).toBe('1')
    const trigger = wrapper.get('.app-header__shopping')
    ;(trigger.element as HTMLButtonElement).focus()
    await trigger.trigger('click')
    const dialog = document.querySelector<HTMLDialogElement>('.shopping-overlay')!
    expect(dialog.open).toBe(true)
    expect(document.body.style.overflow).toBe('hidden')
    expect(document.body.style.position).toBe('fixed')
    expect(wrapper.get('.app-layout__page').attributes('inert')).toBeDefined()
    dialog.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click()
    await flushPromises()
    expect(mocks.setItemChecked).toHaveBeenCalledWith(1, 1, true)
    expect(useActiveShoppingListStore().list?.unchecked_items_count).toBe(0)
    expect(wrapper.find('.app-header__shopping-count').text()).toBe('0')
    expect(dialog.querySelector('.shopping-list__purchased')).not.toBeNull()
    dialog.querySelector<HTMLButtonElement>('[aria-label="Închide lista de cumpărături"]')!.click()
    await flushPromises()
    expect(document.querySelector('.shopping-overlay')).toBeNull()
    expect(document.body.style.position).toBe('')
    expect(document.body.style.overflow).toBe('')
    expect(document.activeElement).toBe(trigger.element)
    expect(route.fullPath).toBe('/recipes')
    expect(mocks.getOpen).toHaveBeenCalledTimes(1)
    expect(mocks.getList).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('closes through Escape, backdrop and navigation, restoring the background', async () => {
    const wrapper = renderHeader()
    await flushPromises()
    for (const event of ['cancel', 'click']) {
      await wrapper.get('.app-header__shopping').trigger('click')
      document
        .querySelector('.shopping-overlay')!
        .dispatchEvent(new Event(event, { cancelable: true }))
      await flushPromises()
      expect(document.querySelector('.shopping-overlay')).toBeNull()
      expect(document.body.style.overflow).toBe('')
      expect(route.fullPath).toBe('/recipes')
    }
    await wrapper.get('.app-header__shopping').trigger('click')
    route.fullPath = '/recipes/12'
    await flushPromises()
    expect(document.querySelector('.shopping-overlay')).toBeNull()
    expect(document.body.style.position).toBe('')
    wrapper.unmount()
  })

  it('hides both controls in hidden mode and closes when the active list disappears', async () => {
    const wrapper = renderHeader()
    await flushPromises()
    await wrapper.get('.app-header__shopping').trigger('click')
    route.meta.shoppingList = 'hidden'
    await flushPromises()
    expect(document.querySelector('.shopping-overlay')).toBeNull()
    expect(wrapper.find('.app-header__shopping').exists()).toBe(false)
    expect(wrapper.find('.floating-shopping').exists()).toBe(false)
    route.meta.shoppingList = 'header'
    await flushPromises()
    await wrapper.get('.app-header__shopping').trigger('click')
    useActiveShoppingListStore().list = null
    await flushPromises()
    expect(wrapper.find('.app-header__shopping').exists()).toBe(false)
    expect(document.querySelector('.shopping-overlay')).toBeNull()
    expect(document.body.style.overflow).toBe('')
    wrapper.unmount()
  })

  it('restores the original body styles and scroll position on unmount', async () => {
    document.body.style.position = 'relative'
    document.body.style.top = '2px'
    document.body.style.width = '90%'
    document.body.style.overflow = 'auto'
    const scrollY = vi.spyOn(window, 'scrollY', 'get').mockReturnValue(240)
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    const wrapper = renderHeader()
    await flushPromises()
    await wrapper.get('.app-header__shopping').trigger('click')
    expect(document.body.style.top).toBe('-240px')
    wrapper.unmount()
    await flushPromises()
    expect(document.body.style.position).toBe('relative')
    expect(document.body.style.top).toBe('2px')
    expect(document.body.style.width).toBe('90%')
    expect(document.body.style.overflow).toBe('auto')
    expect(scrollTo).toHaveBeenCalledWith(0, 240)
    scrollY.mockRestore()
    scrollTo.mockRestore()
    document.body.style.cssText = ''
  })
})
