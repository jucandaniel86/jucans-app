import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, disposePinia, setActivePinia } from 'pinia'
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
    const ids = [store.success('Salvat'), store.info('Informație'), store.warning('Atenție'), store.error('Eroare')]
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
})
