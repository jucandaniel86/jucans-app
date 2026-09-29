export interface AuthUser {
  id: number
  username: string
  avatar: string | null
}

export interface LoginCredentials {
  username: string
  pin: string
}

export interface LoginResponse {
  token: string
  user: AuthUser
}

export interface CurrentUserResponse {
  data: AuthUser
}
