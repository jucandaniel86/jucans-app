import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
  patch: vi.fn<(path: string, body: unknown) => Promise<unknown>>(),
}))

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn<() => Promise<unknown>>(),
    post: vi.fn<() => Promise<unknown>>(),
    patch: apiMocks.patch,
  },
  setApiAccessToken: vi.fn<(token: string | null) => void>(),
  setUnauthorizedHandler: vi.fn<(handler: () => void | Promise<void>) => void>(),
}))

import { useAuthStore } from '@/stores/auth'

describe('auth store avatar updates', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    apiMocks.patch.mockReset()
  })

  it('updates the current user after a successful API response', async () => {
    const store = useAuthStore()
    store.user = { id: 1, username: 'daniel', avatar: null }
    apiMocks.patch.mockResolvedValue({
      data: { id: 1, username: 'daniel', avatar: 'avatar-06' },
    })

    await store.updateAvatar('avatar-06')

    expect(apiMocks.patch).toHaveBeenCalledWith('/auth/avatar', { avatar: 'avatar-06' })
    expect(store.user?.avatar).toBe('avatar-06')
  })
})
