// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import IngredientReviewCard from '@/components/recipe/IngredientReviewCard.vue'

describe('IngredientReviewCard', () => {
  it('hides the numeric quantity for to taste and restores it for measured units', async () => {
    const item = {
      key: 'salt',
      sourceStatus: 'matched' as const,
      resolution: 'existing' as const,
      ingredientId: 3,
      name: 'Sare',
      value: '2',
      unit: 'to_taste',
      rawText: 'sare după gust',
      candidates: [],
    }
    const wrapper = mount(IngredientReviewCard, {
      props: {
        item,
        index: 0,
        units: [
          { value: 'to_taste', label: 'După gust' },
          { value: 'gram', label: 'Grame' },
        ],
        associationSaving: false,
        associationError: '',
      },
    })
    expect(wrapper.find('input').exists()).toBe(false)
    expect(wrapper.find('select').element.value).toBe('to_taste')
    await wrapper.setProps({ item: { ...item, unit: 'gram' } })
    expect(wrapper.find('input').element.value).toBe('2')
  })

  it('highlights an ingredient with a form-level API error and keeps its unit editable', async () => {
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
        units: [
          { value: 'pinch', label: 'Praf' },
          { value: 'gram', label: 'Gram' },
        ],
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
    expect(wrapper.find('select').attributes('disabled')).toBeUndefined()

    await wrapper.find('select').setValue('gram')

    expect(wrapper.emitted('update')?.[0]?.[0]).toMatchObject({
      resolution: 'existing',
      ingredientId: 3,
      unit: 'gram',
    })
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
