// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import IngredientReviewCard from '@/components/recipe/IngredientReviewCard.vue'

describe('IngredientReviewCard', () => {
  it('highlights an ingredient with a form-level API error', () => {
    const wrapper = mount(IngredientReviewCard, {
      props: {
        item: {
          key: 'salt',
          sourceStatus: 'matched',
          resolution: 'existing',
          ingredientId: 3,
          name: 'Sare',
          value: '1',
          unit: 'pinch',
          rawText: 'un praf de sare',
          candidates: [],
        },
        index: 13,
        units: [{ value: 'pinch', label: 'Praf' }],
        error: {
          form: 'Ingredientul apare deja în rețetă. Șterge una dintre apariții.',
        },
        associationSaving: false,
        associationError: '',
      },
    })

    expect(wrapper.classes()).toContain('ingredient-card--invalid')
    expect(wrapper.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Ingredientul apare deja în rețetă')
  })
})
