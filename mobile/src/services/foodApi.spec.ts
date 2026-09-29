import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
  get: vi.fn<(path: string) => Promise<unknown>>(),
  post: vi.fn<(path: string, body?: unknown) => Promise<unknown>>(),
}))

vi.mock('@/services/api', () => ({
  api: {
    get: apiMocks.get,
    post: apiMocks.post,
  },
}))

import { foodApi } from '@/services/foodApi'
import type { RecipeCreatePayload } from '@/types/food'

const payload: RecipeCreatePayload = {
  name: 'Supă',
  description: 'Rețeta familiei',
  url: null,
  tags: [2],
  ingredients: [
    {
      ingredient_id: 17,
      value: 1,
      unit: 'bunch',
      raw_text: 'o legătură de pătrunjel',
    },
  ],
}

describe('foodApi recipe multipart requests', () => {
  beforeEach(() => {
    apiMocks.get.mockReset()
    apiMocks.get.mockResolvedValue({ data: [] })
    apiMocks.post.mockReset()
    apiMocks.post.mockResolvedValue({ data: { id: 1, name: 'Supă' } })
  })

  it('builds recipe list filters and retrieves recipe details', async () => {
    await foodApi.listRecipes({
      search: ' cartofi ',
      tags: [1, 4],
      sort: 'name',
      page: 2,
      perPage: 10,
    })
    await foodApi.getRecipe(7)

    expect(apiMocks.get).toHaveBeenNthCalledWith(
      1,
      '/recipes?search=cartofi&tags=1%2C4&sort=name&page=2&per_page=10',
    )
    expect(apiMocks.get).toHaveBeenNthCalledWith(2, '/recipes/7')
  })

  it('requests a random recipe with tag intersection and optional exclusion', async () => {
    await foodApi.randomRecipe([2, 5], 17)

    expect(apiMocks.get).toHaveBeenCalledWith('/recipes?tags=2%2C5&random=true&exclude=17')
  })

  it('creates recipes as multipart while preserving recipe fields', async () => {
    const image = new File(['image'], 'reteta.jpg', { type: 'image/jpeg' })

    await foodApi.createRecipe(payload, image)

    const [path, body] = apiMocks.post.mock.calls[0]!
    const formData = body as FormData
    expect(path).toBe('/recipes')
    expect(formData).toBeInstanceOf(FormData)
    expect(formData.get('name')).toBe('Supă')
    expect(formData.get('tags[0]')).toBe('2')
    expect(formData.get('ingredients[0][ingredient_id]')).toBe('17')
    expect(formData.get('ingredients[0][raw_text]')).toBe('o legătură de pătrunjel')
    expect(formData.get('image')).toBe(image)
  })

  it('uses method spoofing when replacing or removing an image', async () => {
    await foodApi.updateRecipe(8, payload, { removeImage: true })

    const [path, body] = apiMocks.post.mock.calls[0]!
    const formData = body as FormData
    expect(path).toBe('/recipes/8')
    expect(formData.get('_method')).toBe('PATCH')
    expect(formData.get('remove_image')).toBe('1')
    expect(formData.get('tags_present')).toBe('1')
    expect(formData.get('ingredients_present')).toBe('1')
    expect(formData.get('image')).toBeNull()
  })
})
