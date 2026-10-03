// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ getRecipe: vi.fn(), getConfig: vi.fn(), listTags: vi.fn() }))
vi.mock('@/services/foodApi', () => ({ foodApi: mocks }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ name: 'recipe-edit', params: { id: '40' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

import AddRecipeView from '@/views/AddRecipeView.vue'

describe('recipe save blockers', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.getConfig.mockResolvedValue({
      units: {
        none: { name: 'Fără unitate', label: '' },
        gram: { name: 'Grame', label: 'g' },
        piece: { name: 'Bucată', label: 'buc.' },
        tablespoon: { name: 'Lingură', label: 'lg.' },
      },
    })
    mocks.listTags.mockResolvedValue({ data: [] })
    mocks.getRecipe.mockResolvedValue({
      data: {
        id: 40,
        name: 'Negresa cu mere',
        description: null,
        url: 'https://www.youtube.com/watch?v=33t8hh69pNI',
        image_url: null,
        tags: [],
        ingredients: [
          {
            id: 109,
            name: 'Zahar vanilat',
            value: '1.000',
            unit: 'plic',
            raw_text: '1 plic zahar vanilat',
          },
          {
            id: 111,
            name: 'Praf de copt',
            value: '1.000',
            unit: 'plic',
            raw_text: '1 plic praf de copt',
          },
          {
            id: 49,
            name: 'Esenta de vanilie',
            value: null,
            unit: 'tablespoon',
            raw_text: 'esenta de vanilie',
          },
          { id: 110, name: 'Esenta de rom', value: null, unit: 'none', raw_text: 'esenta de rom' },
        ],
      },
    })
  })

  it('explains unsupported stored units immediately without changing quantities or raw text', async () => {
    const wrapper = mount(AddRecipeView)
    await flushPromises()
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.recipe-save [role="status"]').text()).toContain(
      'Zahar vanilat: unitatea „plic” nu este acceptată',
    )
    expect(wrapper.find('.recipe-save [role="status"]').text()).toContain(
      'Praf de copt: unitatea „plic” nu este acceptată',
    )
    expect(wrapper.findAll('.ingredient-card--invalid')).toHaveLength(2)
    expect(wrapper.text()).toContain('1 plic zahar vanilat')
    expect(wrapper.find('.ingredient-card input').element).toHaveProperty('value', '1')
  })

  it('clears blockers and enables save when both unsupported units are explicitly corrected', async () => {
    const wrapper = mount(AddRecipeView)
    await flushPromises()
    const selects = wrapper.findAll('.ingredient-card select')
    await selects[0]!.setValue('piece')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    await selects[1]!.setValue('piece')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('.recipe-save [role="status"]').exists()).toBe(false)
    expect(wrapper.findAll('.ingredient-card--invalid')).toHaveLength(0)
  })

  it('explains unavailable unit configuration instead of silently disabling save', async () => {
    mocks.getConfig.mockRejectedValue(new Error('offline'))
    const wrapper = mount(AddRecipeView)
    await flushPromises()
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.recipe-save [role="status"]').text()).toContain(
      'Unitățile nu sunt disponibile',
    )
  })
})
