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
    expect(wrapper.find('select').attributes('disabled')).toBeDefined()
  })

  it('keeps unit selection editable for a new ingredient', () => {
    const wrapper = mount(IngredientReviewCard, {
      props: {
        item: {
          key: 'gochujang',
          sourceStatus: 'unresolved',
          resolution: 'new',
          ingredientId: null,
          name: 'Gochujang',
          value: '2',
          unit: 'tablespoon',
          rawText: '2 linguri gochujang',
          candidates: [],
        },
        index: 0,
        units: [{ value: 'tablespoon', label: 'Lingură' }],
        associationSaving: false,
        associationError: '',
      },
    })

    expect(wrapper.find('select').attributes('disabled')).toBeUndefined()
  })
})
