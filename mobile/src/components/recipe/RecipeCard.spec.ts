// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'

const routerMocks = vi.hoisted(() => ({
  push: vi.fn<(location: unknown) => void>(),
}))
const shoppingMocks = vi.hoisted(() => ({ addRecipe: vi.fn() }))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: shoppingMocks }))
vi.mock('@/stores/activeShoppingList', () => ({
  useActiveShoppingListStore: () => ({ addRecipe: shoppingMocks.addRecipe }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => routerMocks,
}))

import RecipeCard from '@/components/recipe/RecipeCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { RecipeSummary } from '@/types/food'

const recipe: RecipeSummary = {
  id: 12,
  name: 'Ciorbă rădăuțeană',
  description: null,
  image: null,
  image_url: null,
  url: 'https://youtube.com/watch?v=test',
  creator: { id: 1, username: 'daniel', avatar: null },
  tags: [
    { id: 1, name: 'Ciorbă', normalized_name: 'ciorba', emoji: '🍲' },
    { id: 2, name: 'Pui', normalized_name: 'pui', emoji: '🐔' },
    { id: 3, name: 'Rapid', normalized_name: 'rapid', emoji: '⚡' },
    { id: 4, name: 'Favorit', normalized_name: 'favorit', emoji: null },
  ],
  created_at: '2026-09-29T10:00:00Z',
  updated_at: '2026-09-29T10:00:00Z',
}

describe('RecipeCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    routerMocks.push.mockReset()
    shoppingMocks.addRecipe.mockReset().mockResolvedValue({ data: { already_present: false } })
  })

  it('shows the fallback, three tags and the remaining count', () => {
    const wrapper = mount(RecipeCard, { props: { recipe } })

    expect(wrapper.find('img').classes()).toContain('recipe-card__image--placeholder')
    expect(wrapper.findAll('.recipe-card__tags span')).toHaveLength(4)
    expect(wrapper.text()).toContain('+1')
    expect(wrapper.text()).toContain('▶ YouTube')
  })

  it('opens the recipe while the source link remains independent', async () => {
    const wrapper = mount(RecipeCard, { props: { recipe } })

    await wrapper.trigger('click')
    expect(routerMocks.push).toHaveBeenCalledWith({
      name: 'recipe-detail',
      params: { id: 12 },
    })

    routerMocks.push.mockReset()
    const sourceLink = wrapper.find('a')
    sourceLink.element.addEventListener('click', (event) => event.preventDefault(), { once: true })
    await sourceLink.trigger('click')
    expect(routerMocks.push).not.toHaveBeenCalled()
  })

  it('opens edit directly without triggering card navigation', async () => {
    useAuthStore().user = { id: 1, username: 'daniel', avatar: null, is_admin: true }

    const wrapper = mount(RecipeCard, { props: { recipe } })

    await wrapper.find('button').trigger('click')

    expect(routerMocks.push).toHaveBeenCalledTimes(1)
    expect(routerMocks.push).toHaveBeenCalledWith({
      name: 'recipe-edit',
      params: { id: 12 },
    })
  })

  it('adds the recipe from the author row without opening the card', async () => {
    const wrapper = mount(RecipeCard, { props: { recipe } })
    const button = wrapper.find('.recipe-card__footer button')
    expect(button.attributes('aria-label')).toContain(recipe.name)
    await button.trigger('keydown', { key: 'Enter' })
    await button.trigger('click')
    await flushPromises()

    expect(shoppingMocks.addRecipe).toHaveBeenCalledWith(12)
    expect(routerMocks.push).not.toHaveBeenCalled()
    expect(useNotificationStore().notifications.at(-1)?.message).toBe('Adăugat în lista de cumpărături')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('prevents duplicate clicks while adding', async () => {
    let resolve!: (value: unknown) => void
    shoppingMocks.addRecipe.mockReturnValue(
      new Promise((done) => {
        resolve = done
      }),
    )
    const wrapper = mount(RecipeCard, { props: { recipe } })
    const button = wrapper.find('.recipe-card__footer button')
    await button.trigger('click')
    expect(button.attributes('disabled')).toBeDefined()
    await button.trigger('click')
    expect(shoppingMocks.addRecipe).toHaveBeenCalledTimes(1)
    resolve({ data: { already_present: false } })
    await flushPromises()
    expect(button.attributes('disabled')).toBeUndefined()
    expect(routerMocks.push).not.toHaveBeenCalled()
  })

  it('shows feedback when the recipe is already present', async () => {
    shoppingMocks.addRecipe.mockResolvedValue({ data: { already_present: true } })
    const wrapper = mount(RecipeCard, { props: { recipe } })
    await wrapper.find('.recipe-card__footer button').trigger('click')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)).toMatchObject({ type: 'info', message: 'Rețeta este deja în listă' })
  })

  it('shows review errors with ingredient names and allows retry', async () => {
    shoppingMocks.addRecipe.mockRejectedValueOnce(
      new ApiError('Validation', 422, {
        'recipe_ingredients.17.needs_review': ['Ingredient 9 (Lapte) requires review.'],
      }),
    )
    const wrapper = mount(RecipeCard, { props: { recipe } })
    const button = wrapper.find('.recipe-card__footer button')
    await button.trigger('click')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)?.message).toContain(
      'Unele ingrediente necesită verificare.',
    )
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Lapte')
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(useNotificationStore().notifications.at(-1)?.type).toBe('success')
    expect(routerMocks.push).not.toHaveBeenCalled()
  })
})
