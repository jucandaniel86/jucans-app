// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const foodApiMocks = vi.hoisted(() => ({
  searchIngredients: vi.fn<(query: string) => Promise<unknown>>(),
}))

vi.mock('@/services/foodApi', () => ({
  foodApi: {
    searchIngredients: foodApiMocks.searchIngredients,
  },
}))

import IngredientAssociationSelector from '@/components/recipe/IngredientAssociationSelector.vue'

describe('IngredientAssociationSelector', () => {
  it('searches through the API and emits the selected canonical ingredient', async () => {
    const salt = { id: 4, name: 'Sare', default_unit: 'gram' }
    foodApiMocks.searchIngredients.mockResolvedValue({ data: [salt] })

    const wrapper = mount(IngredientAssociationSelector, {
      props: {
        initialQuery: 'praf de sare',
        saving: false,
        error: '',
      },
    })
    await flushPromises()

    expect(foodApiMocks.searchIngredients).toHaveBeenCalledWith('praf de sare')
    await wrapper.find('.association-selector__results button').trigger('click')
    expect(wrapper.emitted('select')).toEqual([[salt]])
  })
})
