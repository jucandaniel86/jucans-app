import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { api, setApiAccessToken, setUnauthorizedHandler } from '@/services/api'
import { tokenStorage } from '@/services/tokenStorage'
import type { AuthUser, CurrentUserResponse, LoginCredentials, LoginResponse } from '@/types/auth'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const token = ref<string | null>(null)
  const isRestoring = ref(true)
  const hasRestoredSession = ref(false)
  const isLoggingIn = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value && user.value))

  async function clearSession(): Promise<void> {
    token.value = null
    user.value = null
    setApiAccessToken(null)
    await tokenStorage.remove()
  }

  setUnauthorizedHandler(clearSession)

  async function fetchCurrentUser(): Promise<AuthUser> {
    const response = await api.get<CurrentUserResponse>('/auth/me')
    user.value = response.data

    return response.data
  }

  async function updateAvatar(avatar: string): Promise<AuthUser> {
    const response = await api.patch<CurrentUserResponse>('/auth/avatar', { avatar })
    user.value = response.data

    return response.data
  }

  async function login(credentials: LoginCredentials): Promise<void> {
    isLoggingIn.value = true

    try {
      const response = await api.post<LoginResponse>('/auth/login', credentials)
      token.value = response.token
      user.value = response.user
      setApiAccessToken(response.token)
      await tokenStorage.set(response.token)
    } catch (error) {
      await clearSession()
      throw error
    } finally {
      isLoggingIn.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      if (token.value) {
        await api.post<void>('/auth/logout')
      }
    } finally {
      await clearSession()
    }
  }

  async function restoreSession(): Promise<void> {
    isRestoring.value = true

    try {
      const storedToken = await tokenStorage.get()

      if (!storedToken) {
        return
      }

      token.value = storedToken
      setApiAccessToken(storedToken)
      await fetchCurrentUser()
    } catch {
      await clearSession()
    } finally {
      isRestoring.value = false
      hasRestoredSession.value = true
    }
  }

  return {
    user,
    token,
    isRestoring,
    hasRestoredSession,
    isLoggingIn,
    isAuthenticated,
    login,
    logout,
    restoreSession,
    fetchCurrentUser,
    updateAvatar,
  }
})
