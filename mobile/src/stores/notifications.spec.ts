import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, disposePinia, setActivePinia } from 'pinia'
import { ApiError } from '@/services/api'
import { useNotificationStore } from './notifications'

describe('notifications', () => {
  let pinia: ReturnType<typeof createPinia>
  beforeEach(() => {
    vi.useFakeTimers()
    pinia = createPinia()
    setActivePinia(pinia)
  })
  afterEach(() => {
    disposePinia(pinia)
    vi.useRealTimers()
  })

  it('expires each type at its default duration with independent unique IDs', () => {
    const store = useNotificationStore()
    const ids = [
      store.success('Salvat'),
      store.info('Informație'),
      store.warning('Atenție'),
      store.error('Eroare'),
    ]
    expect(new Set(ids).size).toBe(4)
    vi.advanceTimersByTime(2999)
    expect(store.notifications).toHaveLength(4)
    vi.advanceTimersByTime(1)
    expect(store.notifications.map((item) => item.type)).toEqual(['warning', 'error'])
    vi.advanceTimersByTime(1500)
    expect(store.notifications.map((item) => item.type)).toEqual(['error'])
    vi.advanceTimersByTime(500)
    expect(store.notifications).toEqual([])
  })

  it('deduplicates identical feedback briefly without merging different types or messages', () => {
    const store = useNotificationStore()
    const id = store.success('Salvat')
    expect(store.success('Salvat')).toBe(id)
    store.error('Salvat')
    store.success('Alt produs')
    expect(store.notifications).toHaveLength(3)
    store.dismiss(id)
    expect(store.success('Salvat')).toBe(id)
    vi.advanceTimersByTime(1000)
    expect(store.success('Salvat')).not.toBe(id)
    expect(store.notifications).toHaveLength(3)
  })

  it('supports custom and persistent durations, manual dismissal, and timer cleanup', () => {
    const store = useNotificationStore()
    store.success('Scurt', 250)
    const persistent = store.info('Persistent', 0)
    const error = store.error('Închide manual')
    store.dismiss(error)
    vi.advanceTimersByTime(250)
    expect(store.notifications.map((item) => item.id)).toEqual([persistent])
    store.warning('Temporar')
    expect(vi.getTimerCount()).toBe(1)
    disposePinia(pinia)
    expect(vi.getTimerCount()).toBe(0)
  })
  it('combines API validation messages without repeating the main message', () => {
    const store = useNotificationStore()
    store.apiError(
      new ApiError('Invalid', 422, {
        name: ['Invalid', 'Numele este obligatoriu.'],
        email: ['Email invalid.'],
      }),
    )
    expect(store.notifications[0]?.message).toBe(
      'Invalid\nNumele este obligatoriu.\nEmail invalid.',
    )
    store.apiError(new Error('Internal'))
    expect(store.notifications[1]?.message).toBe('A apărut o eroare. Încearcă din nou.')
    store.apiError(null, 'Personalizat')
    expect(store.notifications[2]?.message).toBe('Personalizat')
    store.apiError(undefined, '')
    expect(store.notifications[3]?.message).toBe('')
  })

  it('resolves queued confirmations independently and cancels pending ones on disposal', async () => {
    const store = useNotificationStore()
    const first = store.confirm({ title: 'Prima', message: 'Continui?' })
    const second = store.confirm({ title: 'A doua', message: 'Continui?' })
    store.resolveConfirmation(true)
    await expect(first).resolves.toBe(true)
    expect(store.confirmations[0]?.title).toBe('A doua')
    store.resolveConfirmation(false)
    await expect(second).resolves.toBe(false)
    const pending = store.confirm({ title: 'Final', message: 'Continui?' })
    disposePinia(pinia)
    await expect(pending).resolves.toBe(false)
  })
})
