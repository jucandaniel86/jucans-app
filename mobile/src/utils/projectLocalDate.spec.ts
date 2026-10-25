import { expect, it, vi } from 'vitest'
import { today, shiftDate } from '@/utils/projectTasks'

it('formats Romanian calendar dates correctly across both DST changes without UTC date shifts', () => {
  vi.useFakeTimers()
  try {
    for (const [timestamp, expected] of [
      ['2026-03-28T22:30:00Z', '2026-03-29'],
      ['2026-03-29T21:30:00Z', '2026-03-30'],
      ['2026-10-24T21:30:00Z', '2026-10-25'],
      ['2026-10-25T22:30:00Z', '2026-10-26'],
    ]) {
      vi.setSystemTime(new Date(timestamp!))
      expect(today('Europe/Bucharest')).toBe(expected)
    }
    expect(shiftDate('2026-03-29', 1)).toBe('2026-03-30')
    expect(shiftDate('2026-10-25', 1)).toBe('2026-10-26')
  } finally {
    vi.useRealTimers()
  }
})
