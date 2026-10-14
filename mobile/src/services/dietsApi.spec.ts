import { beforeEach, describe, expect, it, vi } from 'vitest'
const mocks = vi.hoisted(() => ({
  get: vi.fn<(path: string) => Promise<unknown>>(),
  post: vi.fn<(path: string, body: unknown) => Promise<unknown>>(),
}))
vi.mock('@/services/api', () => ({ api: mocks }))
import { dietsApi } from './dietsApi'

it('loads the diet resource collection without server-side status filtering', async () => {
  const response = { data: [] }
  mocks.get.mockResolvedValueOnce(response)
  expect(await dietsApi.getDiets()).toBe(response)
  expect(mocks.get).toHaveBeenCalledWith('/diets')
})

describe('diet general information multipart requests', () => {
  beforeEach(() => {
    mocks.post.mockClear()
    mocks.post.mockResolvedValue({ data: { id: 2 } })
  })
  it('uses POST method override and omits thumbnail fields when preserving the existing image', async () => {
    await dietsApi.updateDiet(2, { name: 'Dieta', description: null, notes: null })
    const [path, body] = mocks.post.mock.calls[0]!
    expect(path).toBe('/diets/2')
    expect(body).toBeInstanceOf(FormData)
    expect(Object.fromEntries((body as FormData).entries())).toEqual({
      _method: 'PATCH',
      name: 'Dieta',
      description: '',
      notes: '',
    })
  })
  it('sends a new file or explicit removal only when requested', async () => {
    const thumbnail = new File(['image'], 'diet.png', { type: 'image/png' })
    await dietsApi.updateDiet(2, {
      name: 'Dieta',
      description: 'Descriere',
      notes: 'Observații',
      thumbnail,
    })
    expect((mocks.post.mock.calls[0]![1] as FormData).get('thumbnail')).toBe(thumbnail)
    await dietsApi.updateDiet(2, {
      name: 'Dieta',
      description: null,
      notes: null,
      removeThumbnail: true,
    })
    expect((mocks.post.mock.calls[1]![1] as FormData).get('remove_thumbnail')).toBe('1')
  })
})
