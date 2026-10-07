// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'
import { createPinia, setActivePinia } from 'pinia'
import { useNotificationStore } from '@/stores/notifications'

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
    expect(useNotificationStore().notifications.at(-1)?.message).toBe('Adăugat în lista de cumpărături')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Carbonara')
    expect(wrapper.text()).toContain('Alege lista')
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
    expect(useNotificationStore().notifications.at(-1)).toMatchObject({ type: 'info', message: 'Rețeta este deja în listă' })
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
    expect(useNotificationStore().notifications.at(-1)?.message).toContain(
      'Unele ingrediente necesită verificare.',
    )
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Lapte · Parmezan')
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(useNotificationStore().notifications.at(-1)?.type).toBe('success')
  })

  it('shows network errors and allows retry', async () => {
    mocks.addRecipe.mockRejectedValueOnce(new ApiError('Nu ne-am putut conecta la server.', 0))
    const { wrapper, button } = await render()
    await button.trigger('click')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Nu ne-am putut conecta la server.')
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('places the actual YouTube source before Shopping List actions', async () => {
    const data = (await mocks.getRecipe()).data
    mocks.getRecipe.mockResolvedValue({ data: { ...data, url: 'https://youtu.be/recipe' } })
    const { wrapper } = await render()
    const source = wrapper.find('.recipe-detail__source')
    expect(source.text()).toBe('▶ Vezi pe YouTube')
    expect(source.attributes('href')).toBe('https://youtu.be/recipe')
    expect(source.attributes('target')).toBe('_blank')
    expect(source.attributes('rel')).toBe('noopener noreferrer')
    expect(source.element.nextElementSibling?.className).toBe('recipe-detail__shopping')
    wrapper.unmount()
  })

  it('omits missing or unsafe sources and labels other sources compactly', async () => {
    const data = (await mocks.getRecipe()).data
    for (const url of [null, 'javascript:alert(1)', 'https://example.com/recipe']) {
      mocks.getRecipe.mockResolvedValue({ data: { ...data, url } })
      const { wrapper } = await render()
      const source = wrapper.find('.recipe-detail__source')
      expect(source.exists()).toBe(url === 'https://example.com/recipe')
      if (source.exists()) expect(source.text()).toBe('↗ Vezi sursa')
      wrapper.unmount()
    }
  })
})
