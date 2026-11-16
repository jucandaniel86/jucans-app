// @vitest-environment happy-dom
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}
const user = { id: 1, username: 'test', avatar: null, is_admin: false }
function response(status = 200, payload: unknown = { data: user }) {
  return new Response(JSON.stringify(payload), { status })
}
let store: ReturnType<typeof import('./auth').useAuthStore>
let client: typeof import('@/services/api')
let fetchMock: ReturnType<typeof vi.fn<typeof fetch>>
let diagnostics: ReturnType<typeof vi.spyOn>
beforeEach(async () => {
  vi.resetModules()
  vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test/api')
  const { createPinia, setActivePinia } = await import('pinia')
  setActivePinia(createPinia())
  localStorage.clear()
  localStorage.setItem('jucans.auth-token', 'old-token')
  fetchMock = vi.fn<typeof fetch>()
  vi.stubGlobal('fetch', fetchMock)
  diagnostics = vi.spyOn(console, 'debug').mockImplementation(() => {})
  client = await import('@/services/api')
  store = (await import('./auth')).useAuthStore()
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.unstubAllEnvs()
})

it.each([0, 403, 429, 500])('preserves an unverified token after status %s', async (status) => {
  if (status === 0) fetchMock.mockRejectedValue(new TypeError('Load failed'))
  else fetchMock.mockResolvedValue(response(status, { message: 'Unavailable' }))
  await store.restoreSession()
  expect(localStorage.getItem('jucans.auth-token')).toBe('old-token')
  expect(store.token).toBe('old-token')
  expect(store.user).toBeNull()
  expect(store.isAuthenticated).toBe(false)
  expect(store.restorationError).not.toBe('')
  expect(store.isRestoring).toBe(false)
  expect(fetchMock).toHaveBeenCalledTimes(1)
})

it('invalidates an unauthorized token', async () => {
  fetchMock.mockResolvedValue(response(401, { message: 'Unauthenticated.' }))
  await store.restoreSession()
  expect(store.token).toBeNull()
  expect(store.user).toBeNull()
  expect(localStorage.getItem('jucans.auth-token')).toBeNull()
  expect(store.restorationError).toBe('')
  expect(store.hasRestoredSession).toBe(true)
})

it('recovers manually without logging in and deduplicates concurrent retries', async () => {
  fetchMock.mockRejectedValueOnce(new TypeError('Offline'))
  await store.restoreSession()
  const pending = deferred<Response>()
  fetchMock.mockReturnValueOnce(pending.promise)
  const first = store.restoreSession()
  const repeated = store.restoreSession()
  expect(fetchMock).toHaveBeenCalledTimes(2)
  expect(store.isAuthenticated).toBe(false)
  expect(store.isRestoring).toBe(true)
  pending.resolve(response())
  await Promise.all([first, repeated])
  expect(store.isAuthenticated).toBe(true)
  expect(store.user).toEqual(user)
  expect(store.restorationError).toBe('')
  expect(fetchMock).toHaveBeenCalledTimes(2)
  expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
    'https://api.example.test/api/auth/me',
    'https://api.example.test/api/auth/me',
  ])
  expect(new Headers(fetchMock.mock.calls[1]![1]?.headers).get('Authorization')).toBe(
    'Bearer old-token',
  )
})

it('allows repeated failed manual retries and clears the token if a retry returns 401', async () => {
  fetchMock
    .mockRejectedValueOnce(new TypeError('Offline'))
    .mockResolvedValueOnce(response(500))
    .mockResolvedValueOnce(response(401))
  await store.restoreSession()
  await store.restoreSession()
  expect(store.token).toBe('old-token')
  expect(store.isAuthenticated).toBe(false)
  await store.restoreSession()
  expect(store.token).toBeNull()
  expect(store.restorationError).toBe('')
  expect(fetchMock).toHaveBeenCalledTimes(3)
})

it.each(['json', 'text'])('ignores a delayed %s 401 from the previous session', async (kind) => {
  fetchMock.mockResolvedValueOnce(response())
  await store.restoreSession()
  const stale = deferred<Response>()
  fetchMock.mockReturnValueOnce(stale.promise)
  const request = kind === 'json' ? client.api.get('/projects') : client.api.getText('/export')
  const rejected = expect(request).rejects.toMatchObject({ status: 401 })
  fetchMock.mockResolvedValueOnce(response(200, { token: 'new-token', user }))
  await store.login({ username: 'test', pin: '1234' })
  stale.resolve(response(401))
  await rejected
  expect(store.isAuthenticated).toBe(true)
  expect(store.token).toBe('new-token')
  expect(localStorage.getItem('jucans.auth-token')).toBe('new-token')
})

it('does not clear a newer login when an older restoration returns 401', async () => {
  const stale = deferred<Response>()
  fetchMock.mockReturnValueOnce(stale.promise)
  const restoring = store.restoreSession()
  await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
  fetchMock.mockResolvedValueOnce(response(200, { token: 'new-token', user }))
  await store.login({ username: 'test', pin: '1234' })
  stale.resolve(response(401))
  await restoring
  expect(store.isAuthenticated).toBe(true)
  expect(store.token).toBe('new-token')
})

it('does not accept stale user data from a previous restoration', async () => {
  const stale = deferred<Response>()
  fetchMock.mockReturnValueOnce(stale.promise)
  const restoring = store.restoreSession()
  await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
  const newUser = { ...user, id: 2 }
  fetchMock.mockResolvedValueOnce(response(200, { token: 'new-token', user: newUser }))
  await store.login({ username: 'test', pin: '1234' })
  stale.resolve(response())
  await restoring
  expect(store.user).toEqual(newUser)
})

it('retains the original exception internally but never logs secrets', async () => {
  const original = new TypeError('token=old-token pin=1234 secret-query')
  fetchMock.mockRejectedValue(original)
  await expect(
    client.api.post('/auth/login?private=secret-query', { pin: '1234' }, { phase: 'login' }),
  ).rejects.toMatchObject({ status: 0, originalError: original })
  expect(diagnostics).toHaveBeenCalledWith(
    '[API]',
    expect.objectContaining({
      method: 'POST',
      pathname: '/api/auth/login',
      status: 0,
      phase: 'login',
      outcome: 'fetch-rejection',
      exceptionName: 'TypeError',
      durationMs: expect.any(Number),
    }),
  )
  const printed = JSON.stringify(diagnostics.mock.calls)
  for (const secret of ['old-token', '1234', 'secret-query']) expect(printed).not.toContain(secret)
})

it('does not log diagnostics in production', async () => {
  vi.stubEnv('DEV', false)
  fetchMock.mockResolvedValue(response())
  await client.api.get('/projects')
  expect(diagnostics).not.toHaveBeenCalled()
})

it('preserves the token and gates access if verification returns malformed success JSON', async () => {
  fetchMock.mockResolvedValue(response(200, {}))
  await store.restoreSession()
  expect(store.isAuthenticated).toBe(false)
  expect(store.token).toBe('old-token')
  expect(store.restorationError).not.toBe('')
})
it('clears the current verified session on a subsequent genuine 401', async () => {
  fetchMock.mockResolvedValueOnce(response())
  await store.restoreSession()
  fetchMock.mockResolvedValueOnce(response(401))
  await expect(client.api.get('/projects')).rejects.toMatchObject({ status: 401 })
  expect(store.isAuthenticated).toBe(false)
  expect(localStorage.getItem('jucans.auth-token')).toBeNull()
})
