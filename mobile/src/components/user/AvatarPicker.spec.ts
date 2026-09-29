// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AvatarPicker from '@/components/user/AvatarPicker.vue'

describe('AvatarPicker', () => {
  it('emits the selected avatar identifier', async () => {
    const wrapper = mount(AvatarPicker, {
      props: { selected: null, saving: false, error: '' },
      global: {
        stubs: { Teleport: true },
      },
    })

    const options = wrapper.findAll('.avatar-picker__option')
    expect(options).toHaveLength(8)

    await options[4]?.trigger('click')

    expect(wrapper.emitted('select')).toEqual([['avatar-05']])
  })
})
