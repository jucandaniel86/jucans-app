// @vitest-environment happy-dom

import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  listTags: vi.fn<() => Promise<unknown>>(),
  listRecipes: vi.fn<(filters?: unknown) => Promise<unknown>>(),
  randomRecipe: vi.fn<(tags?: number[], exclude?: number) => Promise<unknown>>(),
  push: vi.fn<(location: unknown) => void>(),
}))

vi.mock('@/services/foodApi', () => ({
  foodApi: {
    listTags: mocks.listTags,
    listRecipes: mocks.listRecipes,
    randomRecipe: mocks.randomRecipe,
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mocks.push }),
}))

import { useAuthStore } from '@/stores/auth'
import HomeView from '@/views/HomeView.vue'

describe('HomeView random food widget', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    setActivePinia(createPinia())
    useAuthStore().user = { id: 1, username: 'daniel', avatar: null }
    mocks.listTags.mockReset().mockResolvedValue({
      data: [
        { id: 2, name: 'Pui', normalized_name: 'pui', emoji: '🐔', recipe_count: 12 },
        { id: 5, name: 'Rapid', normalized_name: 'rapid', emoji: '⚡', recipe_count: 16 },
      ],
    })
    mocks.listRecipes.mockReset().mockResolvedValue({ meta: { total: 42 }, data: [] })
    mocks.randomRecipe.mockReset().mockResolvedValue({ data: { id: 27 } })
    mocks.push.mockReset()
  })

  afterEach(() => vi.useRealTimers())

  it('updates the intersection count and navigates with selected tags', async () => {
    const wrapper = mount(HomeView)
    await flushPromises()

    expect(wrapper.text()).toContain('42 rețete disponibile')
    expect(wrapper.text()).toContain('Pui (12)')

    await wrapper.findAll('.food-card__tags button')[0]!.trigger('click')
    mocks.listRecipes.mockResolvedValueOnce({ meta: { total: 12 }, data: [] })
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()

    expect(mocks.listRecipes).toHaveBeenLastCalledWith({ tags: [2], page: 1, perPage: 1 })
    expect(wrapper.text()).toContain('12 rețete disponibile')

    await wrapper.find('.food-card__action button').trigger('click')
    await flushPromises()

    expect(mocks.randomRecipe).toHaveBeenCalledWith([2])
    expect(mocks.push).toHaveBeenCalledWith({
      name: 'recipe-random',
      query: { recipe: '27', tags: '2' },
    })
  })
})
