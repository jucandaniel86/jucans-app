import { describe, expect, it } from 'vitest'

import { recipeIngredientLabel, recipeSourceLabel } from '@/utils/recipePresentation'

describe('recipe presentation', () => {
  it('uses friendly source labels', () => {
    expect(recipeSourceLabel('https://youtu.be/example')).toBe('▶ YouTube')
    expect(recipeSourceLabel('https://www.facebook.com/example')).toBe('Facebook')
    expect(recipeSourceLabel('https://example.com/recipe')).toBe('🌐 Rețeta originală')
  })

  it('formats structured ingredients and falls back to raw text', () => {
    const units = {
      gram: { name: 'grame', label: 'g', aliases: [] },
    }

    expect(
      recipeIngredientLabel(
        { id: 1, name: 'Piept de pui', value: '500.000', unit: 'gram', raw_text: null },
        units,
      ),
    ).toBe('500 g Piept de pui')
    expect(
      recipeIngredientLabel(
        { id: 2, name: 'Sare', value: null, unit: 'none', raw_text: 'sare după gust' },
        units,
      ),
    ).toBe('sare după gust')
  })
})
