const AUTH_TOKEN_KEY = 'jucans.auth-token'

export interface TokenStorage {
  get(): Promise<string | null>
  set(token: string): Promise<void>
  remove(): Promise<void>
}

export const tokenStorage: TokenStorage = {
  async get() {
    try {
      return window.localStorage.getItem(AUTH_TOKEN_KEY)
    } catch {
      return null
    }
  },

  async set(token) {
    try {
      window.localStorage.setItem(AUTH_TOKEN_KEY, token)
    } catch {
      // The in-memory session remains usable when platform storage is unavailable.
    }
  },

  async remove() {
    try {
      window.localStorage.removeItem(AUTH_TOKEN_KEY)
    } catch {
      // Nothing else is required after the in-memory token has been cleared.
    }
  },
}
