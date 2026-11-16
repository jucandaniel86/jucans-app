type ValidationErrors = Record<string, string[]>

interface ErrorPayload {
  message?: string
  errors?: ValidationErrors
}

interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown
  phase?: 'restore' | 'login' | 'logout'
}

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: ValidationErrors = {},
    public readonly originalError?: unknown,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL?.replace(/\/$/, '')

let accessToken: string | null = null
let tokenGeneration = 0
let unauthorizedHandler: (() => void | Promise<void>) | null = null

export function setApiAccessToken(token: string | null): void {
  accessToken = token
  tokenGeneration++
}

export function setUnauthorizedHandler(handler: () => void | Promise<void>): void {
  unauthorizedHandler = handler
}

async function fetchResponse(
  path: string,
  options: RequestInit,
  phase?: RequestOptions['phase'],
): Promise<Response> {
  const generation = tokenGeneration
  const authenticated = accessToken !== null
  const started = performance.now()
  function diagnostic(status: number, failure?: unknown): void {
    if (!import.meta.env.DEV) return
    const rawName = failure instanceof Error ? failure.name : 'Error'
    const exceptionName = [
      'Error',
      'TypeError',
      'AbortError',
      'NetworkError',
      'TimeoutError',
    ].includes(rawName)
      ? rawName
      : 'Error'
    let pathname = '[invalid API URL]'
    try {
      pathname = new URL(`${apiBaseUrl}${path}`).pathname
    } catch {
      /* Diagnostics must not mask the original failure. */
    }
    // Never log the raw exception message: it can contain URLs or sensitive data.
    console.debug('[API]', {
      method: options.method ?? 'GET',
      pathname,
      phase: phase ?? 'request',
      status,
      outcome: failure === undefined ? 'http-response' : 'fetch-rejection',
      ...(failure === undefined
        ? {}
        : {
            exceptionName,
            message:
              exceptionName === 'AbortError'
                ? 'Request cancelled.'
                : 'Fetch failed; inspect Web Inspector for transport details.',
          }),
      durationMs: Math.round(performance.now() - started),
    })
  }
  let response: Response
  try {
    response = await fetch(`${apiBaseUrl}${path}`, options)
  } catch (failure) {
    diagnostic(0, failure)
    throw new ApiError('Nu ne-am putut conecta la server. Încearcă din nou.', 0, {}, failure)
  }
  diagnostic(response.status)
  // An old request must never invalidate a newer login (even with the same token).
  if (response.status === 401 && authenticated && generation === tokenGeneration)
    await unauthorizedHandler?.()
  return response
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

  const { body: _body, phase, ...fetchOptions } = options
  const response = await fetchResponse(
    path,
    {
      ...fetchOptions,
      headers,
      body: requestBody,
    },
    phase,
  )

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

  const response = await fetchResponse(path, { headers })

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
  get<T>(path: string, options: Pick<RequestOptions, 'signal' | 'phase'> = {}): Promise<T> {
    return request<T>(path, options)
  },

  post<T>(path: string, body?: unknown, options: Pick<RequestOptions, 'phase'> = {}): Promise<T> {
    return request<T>(path, { ...options, method: 'POST', body })
  },

  patch<T>(path: string, body?: unknown): Promise<T> {
    return request<T>(path, { method: 'PATCH', body })
  },

  delete<T>(path: string, body?: unknown): Promise<T> {
    return request<T>(path, { method: 'DELETE', body })
  },

  getText(path: string): Promise<{ text: string; status: number }> {
    return requestText(path)
  },
}
