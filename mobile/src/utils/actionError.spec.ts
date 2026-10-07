import { describe, expect, it } from 'vitest'
import { ApiError } from '@/services/api'
import { actionErrorMessage } from './actionError'

describe('action feedback errors', () => {
  it('preserves useful API wording and existing local connection feedback', () => {
    expect(actionErrorMessage(new ApiError('Lista este închisă.', 422), 'Fallback')).toBe('Lista este închisă.')
    expect(actionErrorMessage(new ApiError('Nu ne-am putut conecta la server. Încearcă din nou.', 0), 'Fallback')).toContain('Nu ne-am putut conecta')
  })
  it('does not show server internals or arbitrary exception messages', () => {
    for (const failure of [
      new Error('Shopping list changed'),
      new ApiError('SQLSTATE[HY000]: table missing', 422),
      new ApiError('App\\Services\\ShoppingListService exception', 403),
      new ApiError('Internal Server Error', 500),
      new ApiError('<html>HTTP 502</html>', 502),
      new ApiError('Adresa API nu este configurată.', 0),
    ]) expect(actionErrorMessage(failure, 'Încearcă din nou.')).toBe('Încearcă din nou.')
  })
})
