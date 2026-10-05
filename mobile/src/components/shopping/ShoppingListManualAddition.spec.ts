// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ActiveShoppingList, ManualShoppingItemInput, ShoppingItem } from '@/types/shopping'

const mocks = vi.hoisted(() => ({ getActive: vi.fn(), addManualItem: vi.fn(), getConfig: vi.fn() }))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
import FloatingShoppingList from '@/components/shopping/FloatingShoppingList.vue'
import ShoppingListView from '@/views/ShoppingListView.vue'

describe('shared manual shopping additions', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    const server = {
      id: 1,
      status: 'open',
      items_count: 1,
      recipes_count: 1,
      unchecked_items_count: 1,
      items: [
        {
          id: 1,
          name: 'Lapte',
          quantity: '1.000',
          unit: 'liter',
          is_checked: false,
          shopping_category: null,
        },
      ],
    } as ActiveShoppingList
    mocks.getActive.mockReset().mockImplementation(async () => ({ data: structuredClone(server) }))
    mocks.getConfig.mockReset().mockResolvedValue({ units: { liter: { label: 'l' } } })
    mocks.addManualItem.mockReset().mockImplementation(async (input: ManualShoppingItemInput) => {
      const item: ShoppingItem = {
        ...input,
        id: server.items.length + 1,
        ingredient_id: null,
        calculated_quantity: null,
        quantity: input.quantity === null ? null : input.quantity.toFixed(3),
        shopping_category: null,
        is_checked: false,
        quantity_overridden: true,
      }
      server.items.push(item)
      server.items_count++
      server.unchecked_items_count++
      return { data: item }
    })
  })

  it.each(['collapsed', 'expanded', 'full'] as const)(
    'adds from %s and immediately updates every mounted context',
    async (context) => {
      const full = mount(ShoppingListView)
      await flushPromises()
      const collapsed = mount(FloatingShoppingList, { props: { expanded: false } })
      const expanded = mount(FloatingShoppingList, { props: { expanded: true } })
      const source = context === 'full' ? full : context === 'expanded' ? expanded : collapsed
      expect(collapsed.findAll('.shopping-quick-add')).toHaveLength(1)
      expect(expanded.findAll('.shopping-quick-add')).toHaveLength(1)
      expect(expanded.find('.floating-shopping__quick-add--external').exists()).toBe(false)
      const quick = source.get('.shopping-quick-add')
      await quick.get('button').trigger('click')
      await quick.get('input[name="name"]').setValue('Dero')
      if (context !== 'collapsed') await quick.get('input[name="quantity"]').setValue('2')
      if (context === 'expanded') await quick.get('input[name="unit"]').setValue('buc')
      await quick.get('form').trigger('submit')
      await flushPromises()
      expect(mocks.addManualItem).toHaveBeenCalledTimes(1)
      expect(mocks.getActive).toHaveBeenCalledTimes(1)
      expect(collapsed.get('.floating-shopping__heading').text()).toContain('2 de cumpărat')
      for (const view of [full, expanded]) {
        const row = view.findAll('li').find((row) => row.text().includes('Dero'))!
        expect(row.text()).toBe(
          context === 'collapsed' ? 'Dero' : context === 'expanded' ? 'Dero2 buc' : 'Dero2',
        )
        expect(view.get('.shopping-list__group h2').text()).toContain('Diverse')
      }
      const sheetChildren = expanded.get('.floating-shopping').element.children
      expect(
        sheetChildren[sheetChildren.length - 2]?.classList.contains('shopping-quick-add'),
      ).toBe(true)
      expect(
        sheetChildren[sheetChildren.length - 1]?.classList.contains('floating-shopping__open'),
      ).toBe(true)
      expect(
        full
          .get('.shopping-quick-add')
          .element.nextElementSibling?.classList.contains('shopping-list__close'),
      ).toBe(true)
      full.unmount()
      collapsed.unmount()
      expanded.unmount()
    },
  )
})
