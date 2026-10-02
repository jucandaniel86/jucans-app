// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  getConfig: vi.fn<() => Promise<unknown>>(),
  listShoppingCategories: vi.fn<() => Promise<unknown>>(),
  listAdminIngredients: vi.fn<(filters?: unknown) => Promise<unknown>>(),
  updateAdminIngredient: vi.fn<(ingredientId: number, payload: unknown) => Promise<unknown>>(),
  previewIngredientMerge: vi.fn<() => Promise<unknown>>(),
  mergeIngredients: vi.fn<() => Promise<unknown>>(),
}))

vi.mock('@/services/foodApi', () => ({
  foodApi: mocks,
}))

import AdminIngredientsView from '@/views/AdminIngredientsView.vue'

let intersectionCallback: IntersectionObserverCallback | undefined

const ingredient = {
  id: 9,
  name: 'Sare',
  default_unit: 'gram',
  is_shoppable: true,
  shopping_category: { id: 2, name: 'Condimente', emoji: '🧂', sort_order: 1 },
  aliases: [{ id: 4, alias: 'sare fină' }],
}

describe('AdminIngredientsView', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  beforeEach(() => {
    intersectionCallback = undefined
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        constructor(callback: IntersectionObserverCallback) {
          intersectionCallback = callback
        }

        observe() {}
        disconnect() {}
      },
    )

    mocks.getConfig.mockReset().mockResolvedValue({
      units: {
        gram: { name: 'Gram', label: 'g', aliases: [] },
        piece: { name: 'Bucată', label: 'buc', aliases: [] },
      },
    })
    mocks.listShoppingCategories.mockReset().mockResolvedValue({
      data: [
        { id: 2, name: 'Condimente', emoji: '🧂', sort_order: 1 },
        { id: 3, name: 'Legume', emoji: '🥕', sort_order: 2 },
      ],
    })
    mocks.listAdminIngredients.mockReset().mockResolvedValue({
      data: [ingredient],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        links: [],
        path: '/api/admin/ingredients',
        per_page: 20,
        to: 1,
        total: 1,
      },
    })
    mocks.updateAdminIngredient.mockReset().mockResolvedValue({
      data: {
        ...ingredient,
        name: 'Sare fină',
        default_unit: 'piece',
        is_shoppable: false,
        shopping_category: { id: 3, name: 'Legume', emoji: '🥕', sort_order: 2 },
      },
    })
    mocks.previewIngredientMerge.mockReset()
    mocks.mergeIngredients.mockReset()
  })

  it('loads ingredients, opens edit UI, and patches visible ingredient data', async () => {
    const wrapper = mount(AdminIngredientsView)
    await flushPromises()

    expect(mocks.getConfig).toHaveBeenCalled()
    expect(mocks.listShoppingCategories).toHaveBeenCalled()
    expect(mocks.listAdminIngredients).toHaveBeenCalledWith({
      search: undefined,
      page: 1,
      perPage: 20,
    })
    expect(wrapper.text()).toContain('Sare')
    expect(wrapper.text()).toContain('🧂 Condimente')

    await wrapper.find('.ingredient-row__content').trigger('click')
    await wrapper.find('input[type="text"]').setValue('Sare fină')
    await wrapper.findAll('select')[0]!.setValue('piece')
    await wrapper.findAll('select')[1]!.setValue('3')
    await wrapper.find('input[type="checkbox"]').setValue(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(mocks.updateAdminIngredient).toHaveBeenCalledWith(9, {
      name: 'Sare fină',
      default_unit: 'piece',
      shopping_category_id: 3,
      is_shoppable: false,
    })
    expect(wrapper.text()).toContain('Sare fină')
    expect(wrapper.text()).toContain('🥕 Legume')
    expect(wrapper.text()).toContain('Nu automat')
  })

  it('keeps the actions button independent and opens edit from its menu', async () => {
    const wrapper = mount(AdminIngredientsView)
    await flushPromises()

    await wrapper.get('[aria-label="Acțiuni pentru Sare"]').trigger('click')

    expect(wrapper.find('.ingredient-editor').exists()).toBe(false)
    expect(wrapper.get('[role="menu"]').text()).toContain('Editează')
    expect(wrapper.get('[role="menu"]').text()).toContain('Combină')

    await wrapper.get('[role="menuitem"]').trigger('click')

    expect(wrapper.get('.ingredient-editor').text()).toContain('Sare')
  })

  it('opens merge from the independent actions menu and updates the loaded list on success', async () => {
    const target = {
      ...ingredient,
      id: 12,
      name: 'Sare de mare',
      aliases: [],
    }
    mocks.listAdminIngredients.mockResolvedValue({
      data: [ingredient, target],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        links: [],
        path: '/api/admin/ingredients',
        per_page: 20,
        to: 2,
        total: 2,
      },
    })

    const wrapper = mount(AdminIngredientsView, {
      global: { stubs: { teleport: true } },
    })
    await flushPromises()

    await wrapper.get('[aria-label="Acțiuni pentru Sare"]').trigger('click')
    const mergeAction = wrapper
      .findAll('[role="menuitem"]')
      .find((button) => button.text() === 'Combină')!
    await mergeAction.trigger('click')

    expect(wrapper.get('[role="dialog"]').text()).toContain('Combină ingredient')
    expect(wrapper.get('[role="dialog"]').text()).toContain('Sare')
    expect(wrapper.find('.ingredient-editor').exists()).toBe(false)

    wrapper.getComponent({ name: 'IngredientMergeSheet' }).vm.$emit('merged', {
      target: { ...target, name: 'Sare marină', aliases: [{ id: 8, alias: 'Sare' }] },
      merged_source: { id: 9, name: 'Sare' },
      result: { recipe_ingredient_count: 1, alias_count: 1, needs_review_count: 0 },
    })
    await flushPromises()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.findAll('.ingredient-row')).toHaveLength(1)
    expect(wrapper.text()).toContain('Sare marină')
    expect(wrapper.text()).toContain('1 ingredient')
    expect(wrapper.get('[role="status"]').text()).toContain('Ingredientele au fost combinate.')
  })

  it('maps the active filter to server parameters and combines it with search', async () => {
    vi.useFakeTimers()
    const wrapper = mount(AdminIngredientsView)
    await flushPromises()

    mocks.listAdminIngredients.mockResolvedValue({
      data: [ingredient],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        links: [],
        path: '/api/admin/ingredients',
        per_page: 20,
        to: 1,
        total: 7,
      },
    })

    const filter = (label: string) =>
      wrapper.findAll('.filter-chip').find((button) => button.text() === label)!

    await filter('Fără unitate').trigger('click')
    await flushPromises()

    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: undefined,
      page: 1,
      perPage: 20,
      missingUnit: true,
    })
    expect(filter('Fără unitate').attributes('aria-pressed')).toBe('true')
    expect(wrapper.text()).toContain('7 ingrediente')

    await wrapper.get('input[type="search"]').setValue(' sare ')
    vi.advanceTimersByTime(350)
    await flushPromises()

    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: 'sare',
      page: 1,
      perPage: 20,
      missingUnit: true,
    })

    await filter('Fără categorie').trigger('click')
    await flushPromises()
    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: 'sare',
      page: 1,
      perPage: 20,
      missingCategory: true,
    })

    await filter('Nu se cumpără automat').trigger('click')
    await flushPromises()
    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: 'sare',
      page: 1,
      perPage: 20,
      isShoppable: false,
    })

    await filter('Toate').trigger('click')
    await flushPromises()
    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: 'sare',
      page: 1,
      perPage: 20,
    })
    expect(wrapper.findAll('.filter-chip[aria-pressed="true"]')).toHaveLength(1)
  })

  it('loads the next page when the infinite-scroll sentinel approaches the viewport', async () => {
    mocks.listAdminIngredients
      .mockReset()
      .mockResolvedValueOnce({
        data: [ingredient],
        links: { first: null, last: null, prev: null, next: '/api/admin/ingredients?page=2' },
        meta: {
          current_page: 1,
          from: 1,
          last_page: 2,
          links: [],
          path: '/api/admin/ingredients',
          per_page: 20,
          to: 1,
          total: 2,
        },
      })
      .mockResolvedValueOnce({
        data: [
          {
            ...ingredient,
            id: 10,
            name: 'Piper',
            default_unit: null,
            shopping_category: null,
            is_shoppable: false,
          },
        ],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          current_page: 2,
          from: 2,
          last_page: 2,
          links: [],
          path: '/api/admin/ingredients',
          per_page: 20,
          to: 2,
          total: 2,
        },
      })
      .mockResolvedValueOnce({
        data: [ingredient],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          current_page: 1,
          from: 1,
          last_page: 1,
          links: [],
          path: '/api/admin/ingredients',
          per_page: 20,
          to: 1,
          total: 1,
        },
      })

    const wrapper = mount(AdminIngredientsView)
    await flushPromises()

    intersectionCallback?.(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      {} as IntersectionObserver,
    )
    await flushPromises()

    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: undefined,
      page: 2,
      perPage: 20,
    })
    expect(wrapper.text()).toContain('Piper')
    expect(wrapper.text()).toContain('Fără unitate')
    expect(wrapper.text()).toContain('Fără categorie')
    expect(wrapper.findAll('.ingredient-row')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('Încarcă mai multe')

    const missingCategoryFilter = wrapper
      .findAll('.filter-chip')
      .find((button) => button.text() === 'Fără categorie')!
    await missingCategoryFilter.trigger('click')
    await flushPromises()

    expect(mocks.listAdminIngredients).toHaveBeenLastCalledWith({
      search: undefined,
      page: 1,
      perPage: 20,
      missingCategory: true,
    })
    expect(wrapper.findAll('.ingredient-row')).toHaveLength(1)
  })
})
