// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  listRecipeIngredientReviews: vi.fn<() => Promise<unknown>>(),
  push: vi.fn(),
  replace: vi.fn(),
  route: { query: {} as Record<string, string> },
}))

vi.mock('@/services/foodApi', () => ({
  foodApi: {
    listRecipeIngredientReviews: mocks.listRecipeIngredientReviews,
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ push: mocks.push, replace: mocks.replace }),
}))

import AdminRecipeReviewsView from '@/views/AdminRecipeReviewsView.vue'

let intersectionCallback: IntersectionObserverCallback | undefined

const tomatoReview = {
  id: 513,
  recipe: { id: 15, name: 'Tocăniță' },
  ingredient: { id: 117, name: 'Suc de roșii', default_unit: 'liter' },
  value: '750.000',
  unit: 'milliliter',
  raw_text: '750 ml suc de rosii',
  needs_review: true,
}

const bayLeafReview = {
  id: 514,
  recipe: { id: 16, name: 'Ciorbă' },
  ingredient: { id: 118, name: 'Foi de dafin', default_unit: 'piece' },
  value: null,
  unit: 'none',
  raw_text: 'foi de dafin',
  needs_review: true,
}

function response(data = [tomatoReview, bayLeafReview], total = data.length) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      links: [],
      path: '/api/admin/recipe-ingredient-reviews',
      per_page: 20,
      to: data.length || null,
      total,
    },
  }
}

describe('AdminRecipeReviewsView', () => {
  beforeEach(() => {
    mocks.route.query = {}
    mocks.push.mockReset()
    mocks.replace.mockReset()
    mocks.listRecipeIngredientReviews.mockReset().mockResolvedValue(response())
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
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('renders compact review information and server total', async () => {
    const wrapper = mount(AdminRecipeReviewsView)
    await flushPromises()

    expect(mocks.listRecipeIngredientReviews).toHaveBeenCalledWith({
      search: undefined,
      page: 1,
      perPage: 20,
    })
    expect(wrapper.text()).toContain('2 ingrediente de verificat')
    expect(wrapper.text()).toContain('Suc de roșii')
    expect(wrapper.text()).toContain('Tocăniță')
    expect(wrapper.text()).toContain('750 ml suc de rosii')
    expect(wrapper.text()).toContain('750 · milliliter')
    expect(wrapper.text()).toContain('liter')
    expect(wrapper.text()).toContain('Fără cantitate · none')
    expect(wrapper.findAll('.review-row--mismatch')).toHaveLength(2)
  })

  it('navigates to the existing recipe editor with return context', async () => {
    mocks.route.query = { search: 'rosii' }
    mocks.listRecipeIngredientReviews.mockResolvedValue(response([tomatoReview], 1))
    const wrapper = mount(AdminRecipeReviewsView)
    await flushPromises()

    await wrapper.get('.review-row__correct').trigger('click')

    expect(mocks.push).toHaveBeenCalledWith({
      name: 'recipe-edit',
      params: { id: 15 },
      query: {
        returnTo: 'admin-recipe-reviews',
        reviewIngredient: 117,
        reviewSearch: 'rosii',
      },
    })
  })

  it('debounces search, keeps it in the route, and reloads page one', async () => {
    vi.useFakeTimers()
    const wrapper = mount(AdminRecipeReviewsView)
    await flushPromises()
    mocks.listRecipeIngredientReviews.mockClear()

    await wrapper.get('input[type="search"]').setValue('  supă  ')
    vi.advanceTimersByTime(350)
    await flushPromises()

    expect(mocks.replace).toHaveBeenCalledWith({
      name: 'admin-recipe-reviews',
      query: { search: 'supă' },
    })
    expect(mocks.listRecipeIngredientReviews).toHaveBeenCalledWith({
      search: 'supă',
      page: 1,
      perPage: 20,
    })
  })

  it('keeps server pagination and loads the next page near the bottom', async () => {
    mocks.listRecipeIngredientReviews
      .mockReset()
      .mockResolvedValueOnce({
        ...response([tomatoReview], 2),
        meta: { ...response([tomatoReview], 2).meta, last_page: 2 },
      })
      .mockResolvedValueOnce({
        ...response([bayLeafReview], 2),
        meta: { ...response([bayLeafReview], 2).meta, current_page: 2, last_page: 2 },
      })
    const wrapper = mount(AdminRecipeReviewsView)
    await flushPromises()

    intersectionCallback?.(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      {} as IntersectionObserver,
    )
    await flushPromises()

    expect(mocks.listRecipeIngredientReviews).toHaveBeenLastCalledWith({
      search: undefined,
      page: 2,
      perPage: 20,
    })
    expect(wrapper.findAll('.review-row')).toHaveLength(2)
  })
})
