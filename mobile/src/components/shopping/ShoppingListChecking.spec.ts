// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  getOpen: vi.fn(),
  getList: vi.fn(),
  setItemChecked: vi.fn(),
  getConfig: vi.fn(),
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
import FloatingShoppingList from '@/components/shopping/FloatingShoppingList.vue'
import ShoppingListView from '@/views/ShoppingListView.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useNotificationStore } from '@/stores/notifications'

const initial = {
  id: 1,
  status: 'open',
  is_creator: true,
  items_count: 2,
  recipes_count: 1,
  unchecked_items_count: 2,
  items: [
    {
      id: 1,
      name: 'Roșii',
      quantity: '1.500',
      unit: 'kilogram',
      is_checked: false,
      shopping_category: { id: 1, name: 'Legume', emoji: '🥬', sort_order: 1 },
    },
    {
      id: 2,
      name: 'Ouă',
      quantity: '6.000',
      unit: 'piece',
      is_checked: false,
      shopping_category: { id: 2, name: 'Lactate', emoji: '🥛', sort_order: 2 },
    },
  ],
}

describe('shared shopping checkbox interaction', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    const server = structuredClone(initial)
    mocks.getOpen.mockReset().mockImplementation(async () => ({ data: [structuredClone(server)] }))
    mocks.getList.mockReset().mockImplementation(async () => ({ data: structuredClone(server) }))
    mocks.getConfig
      .mockReset()
      .mockResolvedValue({ units: { kilogram: { label: 'kg' }, piece: { label: 'buc' } } })
    mocks.setItemChecked
      .mockReset()
      .mockImplementation(async (_listId: number, id: number, checked: boolean) => {
        const item = server.items.find((item) => item.id === id)!
        item.is_checked = checked
        server.unchecked_items_count = server.items.filter((item) => !item.is_checked).length
        return { data: structuredClone(item) }
      })
  })

  it('checks in the preview, shows purchased items in the full screen, and unchecks in both', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const floating = mount(FloatingShoppingList, { props: { expanded: true } })
    await floating.find('input[aria-label="Marchează ca cumpărat: Roșii"]').setValue(true)
    await flushPromises()
    expect(floating.find('.shopping-list__purchased').text()).toContain('Roșii')
    expect(floating.findAll('h2').map((h) => h.text())).toEqual(['🥛 Lactate', '✓ Cumpărate'])
    expect(floating.text()).toContain('1 de cumpărat · 1 rețetă')
    const full = mount(ShoppingListView)
    await flushPromises()
    expect(full.find('.shopping-list__purchased input').element).toHaveProperty('checked', true)
    await full.find('.shopping-list__purchased input').setValue(false)
    await flushPromises()
    expect(full.find('.shopping-list__purchased').exists()).toBe(false)
    expect(floating.find('.shopping-list__purchased').exists()).toBe(false)
    expect(floating.text()).toContain('2 de cumpărat · 1 rețetă')
    expect(full.text()).toContain('1,5 kg')
    full.unmount()
    floating.unmount()
  })

  it('moves optimistically, disables the moved checkbox and restores the category on failure', async () => {
    let reject!: (error: Error) => void
    mocks.setItemChecked.mockReturnValueOnce(
      new Promise((_, fail) => {
        reject = fail
      }),
    )
    const full = mount(ShoppingListView)
    await flushPromises()
    await full.find('input[aria-label="Marchează ca cumpărat: Roșii"]').setValue(true)
    expect(full.find('.shopping-list__purchased').text()).toContain('Roșii')
    expect(full.find('.shopping-list__purchased input').attributes('disabled')).toBeDefined()
    reject(new Error('offline'))
    await flushPromises()
    expect(full.find('.shopping-list__purchased').exists()).toBe(false)
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Nu am putut salva modificarea')
    expect(full.find('input[aria-label="Marchează ca cumpărat: Roșii"]').element).toHaveProperty(
      'checked',
      false,
    )
    full.unmount()
  })

  it('keeps one alphabetical purchased section and the floating bar when all items are checked', async () => {
    const store = useActiveShoppingListStore()
    await store.load()
    const floating = mount(FloatingShoppingList, { props: { expanded: true } })
    await store.setChecked(1, true)
    await store.setChecked(2, true)
    await flushPromises()
    expect(floating.findAll('h2').map((h) => h.text())).toEqual(['✓ Cumpărate'])
    expect(floating.findAll('li strong').map((name) => name.text())).toEqual(['Ouă', 'Roșii'])
    expect(floating.find('.floating-shopping__heading').text()).toContain('Totul cumpărat ✓')
    expect(store.list?.items_count).toBe(2)
    floating.unmount()
  })
})
