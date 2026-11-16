import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { api, ApiError, setApiAccessToken, setUnauthorizedHandler } from '@/services/api'
import { tokenStorage } from '@/services/tokenStorage'
import type { AuthUser, CurrentUserResponse, LoginCredentials, LoginResponse } from '@/types/auth'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const token = ref<string | null>(null)
  const isRestoring = ref(true)
  const hasRestoredSession = ref(false)
  const isLoggingIn = ref(false)
  const restorationError = ref('')
  let sessionGeneration = 0
  let pendingRestore: Promise<void> | null = null

  const isAuthenticated = computed(() =>
    Boolean(token.value && user.value && !isRestoring.value && !restorationError.value),
  )

  async function clearSession(): Promise<void> {
    sessionGeneration++
    restorationError.value = ''
    token.value = null
    user.value = null
    setApiAccessToken(null)
    await tokenStorage.remove()
  }

  setUnauthorizedHandler(clearSession)

  async function fetchCurrentUser(): Promise<AuthUser> {
    const generation = sessionGeneration
    const response = await api.get<CurrentUserResponse>('/auth/me', { phase: 'restore' })
    if (!response.data || !Number.isSafeInteger(response.data.id))
      throw new ApiError('Nu am putut verifica sesiunea.', 502)
    if (generation === sessionGeneration) user.value = response.data

    return response.data
  }

  async function updateAvatar(avatar: string): Promise<AuthUser> {
    const response = await api.patch<CurrentUserResponse>('/auth/avatar', { avatar })
    user.value = response.data

    return response.data
  }

  async function login(credentials: LoginCredentials): Promise<void> {
    isLoggingIn.value = true
    const generation = ++sessionGeneration
    restorationError.value = ''

    try {
      const response = await api.post<LoginResponse>('/auth/login', credentials, { phase: 'login' })
      if (generation !== sessionGeneration) return
      token.value = response.token
      user.value = response.user
      setApiAccessToken(response.token)
      await tokenStorage.set(response.token)
    } catch (error) {
      if (generation === sessionGeneration) await clearSession()
      throw error
    } finally {
      isLoggingIn.value = false
    }
  }

  async function logout(): Promise<void> {
    const generation = sessionGeneration
    try {
      if (token.value) {
        await api.post<void>('/auth/logout', undefined, { phase: 'logout' })
      }
    } finally {
      if (generation === sessionGeneration) await clearSession()
    }
  }

  function restoreSession(): Promise<void> {
    if (pendingRestore) return pendingRestore
    const generation = ++sessionGeneration
    isRestoring.value = true
    restorationError.value = ''
    user.value = null
    const operation = (async () => {
      try {
        const storedToken = token.value ?? (await tokenStorage.get())
        if (generation !== sessionGeneration || !storedToken) return
        token.value = storedToken
        setApiAccessToken(storedToken)
        await fetchCurrentUser()
      } catch (failure) {
        if (generation !== sessionGeneration) return
        if (failure instanceof ApiError && failure.status === 401) await clearSession()
        else {
          user.value = null
          restorationError.value =
            'Nu am putut verifica sesiunea. Verifică conexiunea și încearcă din nou.'
        }
      }
    })()
    pendingRestore = operation.finally(() => {
      pendingRestore = null
      isRestoring.value = false
      hasRestoredSession.value = true
    })
    return pendingRestore
  }

  return {
    user,
    token,
    isRestoring,
    hasRestoredSession,
    isLoggingIn,
    restorationError,
    isAuthenticated,
    login,
    logout,
    restoreSession,
    fetchCurrentUser,
    updateAvatar,
  }
})
