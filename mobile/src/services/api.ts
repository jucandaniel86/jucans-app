type ValidationErrors = Record<string, string[]>

interface ErrorPayload {
  message?: string
  errors?: ValidationErrors
}

interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown
}

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: ValidationErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL?.replace(/\/$/, '')

let accessToken: string | null = null
let unauthorizedHandler: (() => void | Promise<void>) | null = null

export function setApiAccessToken(token: string | null): void {
  accessToken = token
}

export function setUnauthorizedHandler(handler: () => void | Promise<void>): void {
  unauthorizedHandler = handler
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  if (!apiBaseUrl) {
    throw new ApiError('Adresa API nu este configurată.', 0)
  }

  const headers = new Headers(options.headers)
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData
  const requestBody: BodyInit | undefined =
    options.body === undefined
      ? undefined
      : isFormData
        ? (options.body as FormData)
        : JSON.stringify(options.body)
  headers.set('Accept', 'application/json')

  if (options.body !== undefined && !isFormData) {
    headers.set('Content-Type', 'application/json')
  }

  if (accessToken) {
    headers.set('Authorization', `Bearer ${accessToken}`)
  }

  let response: Response

  try {
    response = await fetch(`${apiBaseUrl}${path}`, {
      ...options,
      headers,
      body: requestBody,
    })
  } catch {
    throw new ApiError('Nu ne-am putut conecta la server. Încearcă din nou.', 0)
  }

  if (response.status === 401) {
    await unauthorizedHandler?.()
  }

  if (response.status === 204) {
    return undefined as T
  }

  const payload = (await response.json().catch(() => ({}))) as ErrorPayload & T

  if (!response.ok) {
    throw new ApiError(
      payload.message ?? 'A apărut o problemă. Încearcă din nou.',
      response.status,
      payload.errors,
    )
  }

  return payload
}

async function requestText(path: string): Promise<{ text: string; status: number }> {
  if (!apiBaseUrl) {
    throw new ApiError('Adresa API nu este configurată.', 0)
  }

  const headers = new Headers()
  headers.set('Accept', 'text/plain')
  if (accessToken) {
    headers.set('Authorization', `Bearer ${accessToken}`)
  }

  let response: Response

  try {
    response = await fetch(`${apiBaseUrl}${path}`, { headers })
  } catch {
    throw new ApiError('Nu ne-am putut conecta la server. Încearcă din nou.', 0)
  }

  if (response.status === 401) {
    await unauthorizedHandler?.()
  }

  if (response.status === 204) {
    return { text: '', status: 204 }
  }

  const text = await response.text()
  if (!response.ok) {
    throw new ApiError(text || 'A apărut o problemă. Încearcă din nou.', response.status)
  }

  return { text, status: response.status }
}

export const api = {
  get<T>(path: string): Promise<T> {
    return request<T>(path)
  },

  post<T>(path: string, body?: unknown): Promise<T> {
    return request<T>(path, { method: 'POST', body })
  },

  patch<T>(path: string, body?: unknown): Promise<T> {
    return request<T>(path, { method: 'PATCH', body })
  },

  delete<T>(path: string): Promise<T> {
    return request<T>(path, { method: 'DELETE' })
  },

  getText(path: string): Promise<{ text: string; status: number }> {
    return requestText(path)
  },
}
