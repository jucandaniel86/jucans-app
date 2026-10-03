// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const mocks = vi.hoisted(() => ({ getActive: vi.fn(), getConfig: vi.fn() }))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: { getActive: mocks.getActive } }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
let route: { name: string; fullPath: string; meta: { requiresAdmin?: boolean } }
vi.mock('vue-router', () => ({
  useRoute: () => route,
  RouterView: { template: '<div />' },
  RouterLink: { template: '<a><slot /></a>' },
}))
import AppLayout from '@/layouts/AppLayout.vue'

describe('shopping preview shell placement', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    route = reactive({ name: 'recipes', fullPath: '/recipes', meta: {} })
    mocks.getActive
      .mockReset()
      .mockResolvedValue({
        data: {
          id: 1,
          items: [{ id: 1, name: 'Lapte', quantity: null, shopping_category: null }],
          items_count: 1,
          recipes_count: 1,
        },
      })
    mocks.getConfig.mockReset().mockResolvedValue({ units: {} })
    document.body.style.overflow = ''
  })

  it('persists across normal routes without refetching and hides on the full list and admin routes', async () => {
    const wrapper = mount(AppLayout, { global: { stubs: { AppDrawer: true, AppHeader: true } } })
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(true)
    route.name = 'recipe-detail'
    route.fullPath = '/recipes/12'
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(true)
    expect(mocks.getActive).toHaveBeenCalledTimes(1)
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
    route.name = 'shopping-list'
    route.fullPath = '/shopping-list'
    await flushPromises()
    expect(document.body.style.overflow).toBe('')
    wrapper.unmount()
  })
})
