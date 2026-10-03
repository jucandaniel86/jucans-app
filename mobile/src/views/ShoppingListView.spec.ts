// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ShoppingItem } from '@/types/shopping'

const mocks = vi.hoisted(() => ({ getActive: vi.fn(), getConfig: vi.fn() }))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: { getActive: mocks.getActive } }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))

import ShoppingListView from '@/views/ShoppingListView.vue'

const vegetables = { id: 2, name: 'Legume & fructe', emoji: '🥬', sort_order: 1 }
const dairy = { id: 1, name: 'Lactate & ouă', emoji: '🥛', sort_order: 2 }
function item(
  id: number,
  name: string,
  quantity: string | null,
  unit: string | null,
  category: ShoppingItem['shopping_category'],
): ShoppingItem {
  return {
    id,
    ingredient_id: id,
    name,
    quantity,
    unit,
    shopping_category: category,
    calculated_quantity: '999.000',
    is_checked: false,
    quantity_overridden: true,
  }
}
const items = [
  item(1, 'Lapte', '750.000', 'gram', dairy),
  item(2, 'Roșii', '1.500', 'kilogram', vegetables),
  item(3, 'Ceapă', '3.000', 'piece', vegetables),
  item(4, 'Cimbru', null, 'gram', null),
  item(5, 'Smântână', '500.000', 'milliliter', dairy),
]

describe('ShoppingListView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.getActive.mockResolvedValue({ data: null })
    mocks.getConfig.mockResolvedValue({
      units: {
        gram: { label: 'g' },
        kilogram: { label: 'kg' },
        milliliter: { label: 'ml' },
        piece: { label: 'buc' },
      },
    })
  })

  it('shows a loading state and then an empty list without creating one', async () => {
    const wrapper = mount(ShoppingListView)
    expect(wrapper.find('[role="status"]').text()).toContain('Se încarcă lista')
    await flushPromises()
    expect(wrapper.text()).toContain('Lista de cumpărături este goală.')
    expect(wrapper.text()).toContain('Adaugă rețete în listă din pagina unei rețete.')
    expect(mocks.getActive).toHaveBeenCalledTimes(1)
    expect(mocks.getConfig).not.toHaveBeenCalled()
  })

  it('renders category order, Romanian alphabetical item order and localized user quantities', async () => {
    mocks.getActive.mockResolvedValue({ data: { items, items_count: 5, recipes_count: 3 } })
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    expect(wrapper.text()).toContain('5 produse · 3 rețete')
    expect(wrapper.findAll('.shopping-list__group h2').map((heading) => heading.text())).toEqual([
      '🥬 Legume & fructe',
      '🥛 Lactate & ouă',
      '📦 Diverse',
    ])
    expect(wrapper.findAll('li strong').map((name) => name.text())).toEqual([
      'Ceapă',
      'Roșii',
      'Lapte',
      'Smântână',
      'Cimbru',
    ])
    expect(wrapper.findAll('.shopping-list__quantity').map((quantity) => quantity.text())).toEqual([
      '3 buc',
      '1,5 kg',
      '750 g',
      '500 ml',
    ])
    const thyme = wrapper.findAll('li').find((row) => row.text().includes('Cimbru'))!
    expect(thyme.text()).toBe('Cimbru')
    expect(thyme.find('.shopping-list__quantity').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('999')
    expect(wrapper.text()).not.toContain('kilogram')
  })

  it('handles an existing list with no items', async () => {
    mocks.getActive.mockResolvedValue({ data: { items: [], items_count: 0, recipes_count: 1 } })
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    expect(wrapper.text()).toContain('0 produse · 1 rețetă')
    expect(wrapper.text()).toContain('Lista de cumpărături este goală.')
  })

  it('shows load errors with a working retry', async () => {
    mocks.getActive.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(ShoppingListView)
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('Nu am putut încărca lista')
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(mocks.getActive).toHaveBeenCalledTimes(2)
  })

  it('reads fresh data when reopened after a recipe addition', async () => {
    const first = mount(ShoppingListView)
    await flushPromises()
    first.unmount()
    mocks.getActive.mockResolvedValue({ data: { items, items_count: 5, recipes_count: 1 } })
    const next = mount(ShoppingListView)
    await flushPromises()
    expect(mocks.getActive).toHaveBeenCalledTimes(2)
    expect(next.text()).toContain('Roșii')
  })
})
