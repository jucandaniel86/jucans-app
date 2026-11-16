import { beforeEach, expect, it, vi } from 'vitest'
const mocks = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/services/api', () => ({ api: mocks }))
import { projectsApi } from '@/services/projectsApi'
beforeEach(() => vi.clearAllMocks())
it('loads each Shopping project page with one filtered request', async () => {
  await projectsApi.list('active', 1, 5, true)
  await projectsApi.list('active', 2, 5, true)
  expect(mocks.get.mock.calls).toEqual([
    ['/projects?status=active&page=1&per_page=5&shopping_only=1'],
    ['/projects?status=active&page=2&per_page=5&shopping_only=1'],
  ])
})
it('preserves the query used by other Project screens', async () => {
  await projectsApi.list('active')
  expect(mocks.get).toHaveBeenCalledExactlyOnceWith('/projects?status=active&page=1&per_page=30')
})
