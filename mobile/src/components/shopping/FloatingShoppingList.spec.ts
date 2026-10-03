// @vitest-environment happy-dom
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import FloatingShoppingList from '@/components/shopping/FloatingShoppingList.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import type { ActiveShoppingList } from '@/types/shopping'

const items = [
  {
    id: 1,
    name: 'Lapte',
    quantity: '1.500',
    unit: 'liter',
    shopping_category: { id: 1, name: 'Lactate', emoji: '🥛', sort_order: 2 },
  },
  {
    id: 2,
    name: 'Ceapă',
    quantity: '3.000',
    unit: 'piece',
    shopping_category: { id: 2, name: 'Legume', emoji: '🥬', sort_order: 1 },
  },
  { id: 3, name: 'Cimbru', quantity: null, unit: null, shopping_category: null },
]

describe('floating shopping preview', () => {
  beforeEach(() => setActivePinia(createPinia()))
  function render() {
    return mount(FloatingShoppingList, {
      props: { expanded: false },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
  }
  function populate() {
    const shopping = useActiveShoppingListStore()
    shopping.list = {
      id: 1,
      items,
      items_count: 3,
      unchecked_items_count: 3,
      recipes_count: 2,
    } as ActiveShoppingList
    shopping.units = {
      liter: { name: 'Litru', label: 'l', aliases: [] },
      piece: { name: 'Bucată', label: 'buc', aliases: [] },
    }
    return shopping
  }

  it('renders nothing when no list exists or it has no items', async () => {
    const wrapper = render()
    expect(wrapper.find('.floating-shopping').exists()).toBe(false)
    const shopping = populate()
    shopping.list!.items = []
    await flushPromises()
    expect(wrapper.find('.floating-shopping').exists()).toBe(false)
  })

  it('shows actual counts and expands into ordered, localized groups', async () => {
    populate()
    const wrapper = render()
    expect(wrapper.text()).toContain('3 de cumpărat · 2 rețete')
    expect(wrapper.find('ul').exists()).toBe(false)
    await wrapper.find('.floating-shopping__toggle').trigger('click')
    expect(wrapper.emitted('update:expanded')?.[0]).toEqual([true])
    await wrapper.setProps({ expanded: true })
    expect(wrapper.findAll('h2').map((h) => h.text())).toEqual([
      '🥬 Legume',
      '🥛 Lactate',
      '📦 Diverse',
    ])
    expect(wrapper.text()).toContain('1,5 l')
    expect(wrapper.text()).toContain('3 buc')
    expect(
      wrapper
        .findAll('li')
        .find((row) => row.text().includes('Cimbru'))
        ?.text(),
    ).toBe('Cimbru')
    await wrapper.find('.floating-shopping__toggle').trigger('click')
    expect(wrapper.emitted('update:expanded')?.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('links to the full list and collapses; Escape also collapses', async () => {
    populate()
    const wrapper = render()
    await wrapper.setProps({ expanded: true })
    const link = wrapper.findComponent(RouterLinkStub)
    expect(link.props('to')).toEqual({ name: 'shopping-list' })
    await link.trigger('click')
    expect(wrapper.emitted('update:expanded')?.at(-1)).toEqual([false])
    await wrapper.find('.floating-shopping').trigger('keydown', { key: 'Escape' })
    expect(wrapper.emitted('update:expanded')?.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('keeps the floating bar visible when everything is checked', async () => {
    const shopping = populate()
    shopping.list!.items = shopping.list!.items.map((item) => ({ ...item, is_checked: true }))
    shopping.list!.unchecked_items_count = 0
    const wrapper = render()
    expect(wrapper.text()).toContain('Totul cumpărat ✓')
    await wrapper.setProps({ expanded: true })
    expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['✓ Cumpărate'])
    expect(wrapper.findAll('input:checked')).toHaveLength(3)
    wrapper.unmount()
  })
})
