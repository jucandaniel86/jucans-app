import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

beforeEach(() => {
  vi.resetModules()
  vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test/api')
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.unstubAllEnvs()
})
describe('Cancellable project list reads', () => {
  it('forwards the signal and encodes the optional context without changing legacy defaults', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValue({ ok: true, status: 200, json: async () => ({ data: [], contexts: {} }) })
    vi.stubGlobal('fetch', fetch)
    const { projectTasksApi } = await import('./projectTasksApi')
    const controller = new AbortController()
    await projectTasksApi.list(
      9,
      { execution_type: 'recurring', include_context: true, occurrence_date: '2026-11-11' },
      false,
      { signal: controller.signal },
    )
    const [url, options] = fetch.mock.calls[0]!
    const query = new URL(String(url)).searchParams
    expect(query.get('include_context')).toBe('1')
    expect(query.get('execution_type')).toBe('recurring')
    expect(query.get('occurrence_date')).toBe('2026-11-11')
    expect(query.get('sort')).toBe('overdue')
    expect(query.get('per_page')).toBe('10')
    expect(options.signal).toBe(controller.signal)
    await projectTasksApi.list(9, {})
    expect(new URL(String(fetch.mock.calls[1]![0])).searchParams.has('include_context')).toBe(false)
  })
  it('cancels a pending transport without starting another request', async () => {
    const fetch = vi.fn(
      (_: string, options: RequestInit) =>
        new Promise((_, reject) => {
          options.signal!.addEventListener('abort', () =>
            reject(new DOMException('Aborted', 'AbortError')),
          )
        }),
    )
    vi.stubGlobal('fetch', fetch)
    const { projectTasksApi } = await import('./projectTasksApi')
    const controller = new AbortController()
    const result = projectTasksApi.list(1, {}, false, { signal: controller.signal })
    const rejected = expect(result).rejects.toMatchObject({ status: 0 })
    controller.abort()
    await rejected
    expect(controller.signal.aborted).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(1)
  })
  it('passes a 429 to the caller without automatic retry', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValue({
        ok: false,
        status: 429,
        json: async () => ({ message: 'Too Many Requests' }),
      })
    vi.stubGlobal('fetch', fetch)
    const { projectTasksApi } = await import('./projectTasksApi')
    await expect(projectTasksApi.list(1, {})).rejects.toMatchObject({ status: 429 })
    expect(fetch).toHaveBeenCalledTimes(1)
  })
})
