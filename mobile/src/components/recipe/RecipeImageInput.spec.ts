// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import RecipeImageInput from '@/components/recipe/RecipeImageInput.vue'

describe('RecipeImageInput', () => {
  it('uses the Jucans fallback and offers selection when empty', () => {
    const wrapper = mount(RecipeImageInput, {
      props: { previewUrl: null, hasImage: false },
    })

    expect(wrapper.find('img').classes()).toContain('recipe-image-input__placeholder')
    expect(wrapper.text()).toContain('Alege imagine')
    expect(wrapper.text()).not.toContain('Șterge imaginea')
  })

  it('offers change and removal actions for an existing image', async () => {
    const wrapper = mount(RecipeImageInput, {
      props: { previewUrl: 'https://example.test/recipe.webp', hasImage: true },
    })

    expect(wrapper.text()).toContain('Schimbă imaginea')
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('remove')).toHaveLength(1)
  })

  it('preserves recipe labels, file selection and disabled controls after extraction', async () => {
    const wrapper = mount(RecipeImageInput, { props: { previewUrl: null, hasImage: false } })
    expect(wrapper.text()).toContain('Imagine rețetă')
    expect(wrapper.get('img').attributes('alt')).toBe('Previzualizare imagine rețetă')
    const file = new File(['image'], 'recipe.png', { type: 'image/png' })
    Object.defineProperty(wrapper.get('input').element, 'files', { value: [file] })
    await wrapper.get('input').trigger('change')
    expect(wrapper.emitted('select')?.[0]).toEqual([file])
    expect(wrapper.get('input').attributes('accept')).toBe('image/jpeg,image/png,image/webp')
    await wrapper.setProps({ hasImage: true, disabled: true })
    expect(wrapper.get('input').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })
})
