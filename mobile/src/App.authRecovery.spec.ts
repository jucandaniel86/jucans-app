// @vitest-environment happy-dom
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import App from './App.vue'
import { useAuthStore } from '@/stores/auth'

let wrapper: VueWrapper | undefined
let fetchMock: ReturnType<typeof vi.fn<typeof fetch>>
beforeEach(() => {
  vi.useFakeTimers()
  localStorage.clear()
  localStorage.setItem('jucans.auth-token', 'saved-token')
  fetchMock = vi.fn<typeof fetch>()
  vi.stubGlobal('fetch', fetchMock)
  vi.spyOn(console, 'debug').mockImplementation(() => {})
})
afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})
async function start() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/projects',
        name: 'projects',
        meta: { requiresAuth: true },
        component: { template: '<div data-protected>Private</div>' },
      },
      {
        path: '/login',
        name: 'login',
        meta: { guestOnly: true },
        component: { template: '<div data-login>Login</div>' },
      },
      { path: '/', name: 'home', component: { template: '<div data-protected>Home</div>' } },
    ],
  })
  await router.push('/projects')
  wrapper = mount(App, {
    global: {
      plugins: [createPinia(), router],
      stubs: { AppNotifications: true, AppConfirmation: true },
    },
  })
  await vi.advanceTimersByTimeAsync(800)
  await flushPromises()
  return router
}
function success() {
  return new Response(
    JSON.stringify({ data: { id: 1, username: 'test', is_admin: false, avatar: null } }),
  )
}
it('gates protected UI during failed verification and pending retry, then recovers in place', async () => {
  fetchMock.mockRejectedValueOnce(new TypeError('Offline'))
  const router = await start()
  expect(wrapper!.find('[data-protected]').exists()).toBe(false)
  expect(wrapper!.find('[role="alert"]').exists()).toBe(true)
  expect(router.currentRoute.value.name).toBe('projects')
  let resolve!: (value: Response) => void
  fetchMock.mockReturnValueOnce(
    new Promise<Response>((done) => {
      resolve = done
    }),
  )
  await wrapper!.get('button').trigger('click')
  await flushPromises()
  expect(wrapper!.find('[data-protected]').exists()).toBe(false)
  expect(fetchMock).toHaveBeenCalledTimes(2)
  resolve(success())
  await flushPromises()
  expect(wrapper!.find('[data-protected]').exists()).toBe(true)
  expect(localStorage.getItem('jucans.auth-token')).toBe('saved-token')
})
it('routes to login when a manual retry proves the token invalid', async () => {
  fetchMock.mockResolvedValueOnce(new Response('{}', { status: 500 }))
  const router = await start()
  expect(wrapper!.find('[data-protected]').exists()).toBe(false)
  fetchMock.mockResolvedValueOnce(new Response('{}', { status: 401 }))
  await wrapper!.get('button').trigger('click')
  await flushPromises()
  expect(router.currentRoute.value.name).toBe('login')
  expect(wrapper!.find('[data-login]').exists()).toBe(true)
  expect(localStorage.getItem('jucans.auth-token')).toBeNull()
})
it('opens login without an API request when no token is stored', async () => {
  localStorage.clear()
  const router = await start()
  expect(router.currentRoute.value.name).toBe('login')
  expect(wrapper!.find('[data-protected]').exists()).toBe(false)
  expect(fetchMock).not.toHaveBeenCalled()
})

it('does not navigate to login when re-verification of an existing session fails temporarily', async () => {
  fetchMock.mockResolvedValueOnce(success())
  const router = await start()
  expect(wrapper!.find('[data-protected]').exists()).toBe(true)
  fetchMock.mockRejectedValueOnce(new TypeError('Offline'))
  await useAuthStore().restoreSession()
  await flushPromises()
  expect(router.currentRoute.value.name).toBe('projects')
  expect(wrapper!.find('[data-protected]').exists()).toBe(false)
  expect(wrapper!.find('[role="alert"]').exists()).toBe(true)
})
