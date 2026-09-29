// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  getRecipe: vi.fn<(recipeId: number) => Promise<unknown>>(),
  randomRecipe: vi.fn<(tags?: number[], exclude?: number) => Promise<unknown>>(),
  replace: vi.fn<(location: unknown) => void>(),
  route: { query: { recipe: '12', tags: '2,5' } },
}))

vi.mock('@/services/foodApi', () => ({
  foodApi: {
    getRecipe: mocks.getRecipe,
    randomRecipe: mocks.randomRecipe,
  },
}))

vi.mock('vue-router', () => ({
  useRoute: () => mocks.route,
  useRouter: () => ({ replace: mocks.replace }),
  RouterLink: { template: '<a><slot /></a>' },
}))

import RandomRecipeView from '@/views/RandomRecipeView.vue'

const recipe = {
  id: 12,
  name: 'Ciorbă',
  description: null,
  image: null,
  image_url: null,
  url: null,
  creator: { id: 1, username: 'daniel', avatar: null },
  tags: [],
  created_at: null,
  updated_at: null,
}

describe('RandomRecipeView', () => {
  beforeEach(() => {
    mocks.getRecipe.mockReset().mockResolvedValue({ data: recipe })
    mocks.randomRecipe.mockReset().mockResolvedValue({ data: { ...recipe, id: 21 } })
    mocks.replace.mockReset()
  })

  it('chooses another recipe with the same tags and excludes the current one', async () => {
    const wrapper = mount(RandomRecipeView)
    await flushPromises()

    await wrapper
      .findAll('button')
      .find((button) => button.text().includes('Alege alta'))!
      .trigger('click')
    await flushPromises()

    expect(mocks.randomRecipe).toHaveBeenCalledWith([2, 5], 12)
    expect(mocks.replace).toHaveBeenCalledWith({
      name: 'recipe-random',
      query: { recipe: '21', tags: '2,5' },
    })
  })
})
