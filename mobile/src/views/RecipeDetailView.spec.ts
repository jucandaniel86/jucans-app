// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'
import { createPinia, setActivePinia } from 'pinia'

const mocks = vi.hoisted(() => ({
  getRecipe: vi.fn(),
  getConfig: vi.fn(),
  addRecipe: vi.fn(),
  back: vi.fn(),
}))
vi.mock('@/services/foodApi', () => ({
  foodApi: { getRecipe: mocks.getRecipe, getConfig: mocks.getConfig },
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: { addRecipe: mocks.addRecipe } }))
vi.mock('@/stores/activeShoppingList', () => ({
  useActiveShoppingListStore: () => ({ addRecipe: mocks.addRecipe }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '12' } }),
  useRouter: () => ({ back: mocks.back }),
  RouterLink: { template: '<a><slot /></a>' },
}))

import RecipeDetailView from '@/views/RecipeDetailView.vue'

describe('RecipeDetail shopping action', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mocks.getRecipe.mockResolvedValue({
      data: {
        id: 12,
        name: 'Carbonara',
        tags: [],
        ingredients: [],
        image_url: null,
        creator: { id: 1, username: 'daniel', avatar: null },
      },
    })
    mocks.getConfig.mockResolvedValue({ units: {} })
    mocks.addRecipe.mockResolvedValue({ data: { already_present: false } })
  })

  async function render() {
    const wrapper = mount(RecipeDetailView)
    await flushPromises()
    return {
      wrapper,
      button: wrapper
        .findAll('button')
        .find((button) => button.text().includes('Adaugă la listă'))!,
    }
  }

  it('adds the displayed recipe and shows success without navigating away', async () => {
    const { wrapper, button } = await render()
    await button.trigger('click')
    await flushPromises()
    expect(mocks.addRecipe).toHaveBeenCalledWith(12)
    expect(wrapper.find('[role="status"]').text()).toBe('Adăugat în lista de cumpărături')
    expect(wrapper.text()).toContain('Carbonara')
    expect(wrapper.text()).toContain('Deschide lista')
    expect(mocks.back).not.toHaveBeenCalled()
  })

  it('disables duplicate submissions while loading', async () => {
    let resolve!: (value: unknown) => void
    mocks.addRecipe.mockReturnValue(
      new Promise((done) => {
        resolve = done
      }),
    )
    const { button } = await render()
    await button.trigger('click')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-busy')).toBe('true')
    await button.trigger('click')
    expect(mocks.addRecipe).toHaveBeenCalledTimes(1)
    resolve({ data: { already_present: false } })
    await flushPromises()
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('treats already-added responses as useful feedback', async () => {
    mocks.addRecipe.mockResolvedValue({ data: { already_present: true } })
    const { wrapper, button } = await render()
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="status"]').text()).toBe('Rețeta este deja în listă')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('identifies ingredients requiring review and clears errors on retry', async () => {
    mocks.addRecipe.mockRejectedValueOnce(
      new ApiError('Validation error', 422, {
        'recipe_ingredients.17.needs_review': ['Ingredient 9 (Lapte) requires review.'],
        'recipe_ingredients.18.unit': ['Ingredient 10 (Parmezan) does not use its canonical unit.'],
      }),
    )
    const { wrapper, button } = await render()
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain(
      'Unele ingrediente necesită verificare.',
    )
    expect(wrapper.find('[role="alert"]').text()).toContain('Lapte · Parmezan')
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(true)
  })

  it('shows network errors and allows retry', async () => {
    mocks.addRecipe.mockRejectedValueOnce(new ApiError('Nu ne-am putut conecta la server.', 0))
    const { wrapper, button } = await render()
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('Nu ne-am putut conecta la server.')
    expect(button.attributes('disabled')).toBeUndefined()
  })
})
