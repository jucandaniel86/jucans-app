// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ActiveShoppingList } from '@/types/shopping'

const mocks = vi.hoisted(() => ({
  getActive: vi.fn(),
  getList: vi.fn(),
  removeRecipe: vi.fn(),
  getConfig: vi.fn(),
  route: { params: {} as Record<string, string> },
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/foodApi', () => ({ foodApi: { getConfig: mocks.getConfig } }))
vi.mock('vue-router', () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
import ShoppingListView from '@/views/ShoppingListView.vue'
import FloatingShoppingList from '@/components/shopping/FloatingShoppingList.vue'
import ShoppingListRecipes from '@/components/shopping/ShoppingListRecipes.vue'
import ShoppingListTabs from '@/components/shopping/ShoppingListTabs.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'

const initial: ActiveShoppingList = {
  id: 1,
  name: null,
  visibility: 'private',
  created_by: 1,
  created_at: null,
  closed_at: null,
  status: 'open',
  items_count: 2,
  unchecked_items_count: 2,
  recipes_count: 2,
  items: [
    {
      id: 1,
      name: 'Lapte',
      quantity: '2.000',
      unit: 'liter',
      shopping_category: null,
      is_checked: false,
      ingredient_id: 1,
      calculated_quantity: '2.000',
      quantity_overridden: false,
    },
    {
      id: 2,
      name: 'Dero',
      quantity: null,
      unit: null,
      shopping_category: null,
      is_checked: false,
      ingredient_id: null,
      calculated_quantity: null,
      quantity_overridden: true,
    },
  ],
  recipes: [
    {
      id: 12,
      name: 'Supă',
      image_url: '/soup.jpg',
      tags: [{ id: 1, name: 'Cină', normalized_name: 'cina', emoji: null }],
    },
    { id: 13, name: 'Paste' },
  ],
}
const updated: ActiveShoppingList = {
  ...initial,
  recipes_count: 1,
  recipes: [initial.recipes![1]!],
  items: [{ ...initial.items[0]!, quantity: '1.000' }, initial.items[1]!],
}
const global = { stubs: { Teleport: true } }

describe('shopping recipes tabs and shared removal', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.route.params = {}
    mocks.getActive.mockReset().mockResolvedValue({ data: structuredClone(initial) })
    mocks.getList
      .mockReset()
      .mockResolvedValue({ data: { ...structuredClone(initial), id: 9, status: 'closed' } })
    mocks.getConfig.mockReset().mockResolvedValue({ units: { liter: { label: 'l' } } })
    mocks.removeRecipe.mockReset().mockResolvedValue({ data: structuredClone(updated) })
    HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
      this.open = true
    })
    HTMLDialogElement.prototype.close = vi.fn(function (this: HTMLDialogElement) {
      this.open = false
    })
  })

  it.each(['full', 'floating'] as const)(
    'confirms removal from %s and synchronizes every context',
    async (sourceName) => {
      const full = mount(ShoppingListView, { global })
      await flushPromises()
      const floating = mount(FloatingShoppingList, { props: { expanded: true }, global })
      const collapsed = mount(FloatingShoppingList, { props: { expanded: false }, global })
      expect(collapsed.find('[role="tablist"]').exists()).toBe(false)
      for (const view of [full, floating]) {
        expect(view.get('[role="tab"]').attributes('aria-selected')).toBe('true')
        expect(view.find('.shopping-quick-add').exists()).toBe(true)
        await view.findAll('[role="tab"]')[1]!.trigger('click')
        expect(view.find('.shopping-quick-add').exists()).toBe(false)
        expect(view.findAll('.shopping-recipes__row')).toHaveLength(2)
      }
      expect(
        full.findComponent(ShoppingListRecipes).findComponent({ name: 'RouterLink' }).props('to'),
      ).toEqual({ name: 'recipe-detail', params: { id: 12 } })
      const source = sourceName === 'full' ? full : floating
      await source.get('[aria-label="Elimină: Supă"]').trigger('click')
      expect(mocks.removeRecipe).not.toHaveBeenCalled()
      expect(source.get('.shopping-recipes__dialog').text()).toContain('Elimini rețeta din listă?')
      await source.get('.shopping-recipes__dialog form').trigger('submit')
      await flushPromises()
      expect(mocks.removeRecipe).toHaveBeenCalledExactlyOnceWith(1, 12)
      expect(mocks.getActive).toHaveBeenCalledTimes(1)
      for (const view of [full, floating]) {
        expect(view.findAll('.shopping-recipes__row')).toHaveLength(1)
        expect(view.findAll('[role="tab"]')[1]!.text()).toContain('(1)')
        await view.findAll('[role="tab"]')[0]!.trigger('click')
        expect(view.get('#shopping-item-1').element.closest('li')?.textContent).toContain('1 l')
        expect(view.get('#shopping-item-2').element.closest('li')?.textContent).toContain('Dero')
        expect(view.find('.shopping-quick-add').exists()).toBe(true)
      }
      expect(collapsed.get('.floating-shopping__heading').text()).toContain('1 rețetă')
      full.unmount()
      floating.unmount()
      collapsed.unmount()
    },
  )

  it('supports cancellation, loading, duplicate prevention, error and retry', async () => {
    const store = useActiveShoppingListStore()
    store.list = structuredClone(initial)
    const view = mount(ShoppingListRecipes, { props: { list: store.list! }, global })
    await view.get('[aria-label="Elimină: Supă"]').trigger('click')
    await view.get('.shopping-recipes__confirmation button').trigger('click')
    expect(view.find('dialog').exists()).toBe(false)
    expect(mocks.removeRecipe).not.toHaveBeenCalled()
    let reject!: (error: Error) => void
    mocks.removeRecipe.mockReturnValueOnce(
      new Promise((_, fail) => {
        reject = fail
      }),
    )
    await view.get('[aria-label="Elimină: Supă"]').trigger('click')
    await view.get('form').trigger('submit')
    await view.get('form').trigger('submit')
    expect(mocks.removeRecipe).toHaveBeenCalledTimes(1)
    expect(view.get('.shopping-recipes__destructive').attributes('disabled')).toBeDefined()
    reject(new Error('offline'))
    await flushPromises()
    expect(view.get('[role="alert"]').text()).toContain('Nu am putut elimina rețeta')
    expect(store.list).toEqual(initial)
    await view.get('form').trigger('submit')
    await flushPromises()
    expect(store.list?.recipes_count).toBe(1)
    view.unmount()
  })

  it('keeps an expanded empty sheet visible after the last recipe is removed', async () => {
    const store = useActiveShoppingListStore()
    store.list = { ...structuredClone(initial), recipes_count: 1, recipes: [initial.recipes![0]!] }
    const view = mount(FloatingShoppingList, { props: { expanded: true }, global })
    await view.findAll('[role="tab"]')[1]!.trigger('click')
    mocks.removeRecipe.mockResolvedValueOnce({
      data: {
        ...initial,
        items: [],
        items_count: 0,
        unchecked_items_count: 0,
        recipes: [],
        recipes_count: 0,
      },
    })
    await view.get('[aria-label="Elimină: Supă"]').trigger('click')
    await view.get('form').trigger('submit')
    await flushPromises()
    expect(view.text()).toContain('Nu sunt rețete în această listă.')
    expect(view.find('.floating-shopping__open').exists()).toBe(true)
    expect(view.findAll('[role="tab"]')[0]!.text()).toContain('(0)')
    await view.setProps({ expanded: false })
    expect(view.find('.floating-shopping').exists()).toBe(false)
    view.unmount()
  })

  it('shows closed history recipes read-only without replacing active state', async () => {
    mocks.route.params = { id: '9' }
    const store = useActiveShoppingListStore()
    store.list = structuredClone(initial)
    const view = mount(ShoppingListView, { global })
    await flushPromises()
    await view.findAll('[role="tab"]')[1]!.trigger('click')
    expect(view.findAll('.shopping-recipes__row')).toHaveLength(2)
    expect(view.find('[aria-label^="Elimină:"]').exists()).toBe(false)
    expect(view.find('.shopping-list__actions').exists()).toBe(false)
    expect(store.list).toEqual(initial)
    expect(mocks.getActive).not.toHaveBeenCalled()
    view.unmount()
  })

  it('does not misrepresent unavailable attachment data as an empty recipe list', () => {
    const view = mount(ShoppingListRecipes, { props: { list: { ...initial, recipes: undefined } } })
    expect(view.text()).toContain('Nu am putut încărca rețetele acestei liste.')
    expect(view.text()).not.toContain('Nu sunt rețete')
    view.unmount()
  })

  it('supports keyboard tab selection and counts', async () => {
    const view = mount(ShoppingListTabs, {
      props: { modelValue: 'shopping', itemsCount: 2, recipesCount: 1, panelId: 'test' },
    })
    await view.get('[role="tablist"]').trigger('keydown', { key: 'End' })
    expect(view.emitted('update:modelValue')?.[0]).toEqual(['recipes'])
    await view.setProps({ modelValue: 'recipes' })
    expect(view.findAll('button')[1]!.attributes('tabindex')).toBe('0')
    await view.get('[role="tablist"]').trigger('keydown', { key: 'ArrowLeft' })
    expect(view.emitted('update:modelValue')?.[1]).toEqual(['shopping'])
    view.unmount()
  })
})
