// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import UserAvatar from '@/components/user/UserAvatar.vue'

describe('UserAvatar', () => {
  it('renders the username initial when no avatar is selected', () => {
    const wrapper = mount(UserAvatar, {
      props: { avatar: null, username: 'daniel' },
    })

    expect(wrapper.classes()).toContain('user-avatar--fallback')
    expect(wrapper.text()).toBe('D')
    expect(wrapper.attributes('data-avatar-id')).toBeUndefined()
  })

  it('maps a known avatar to its sprite position without distortion', () => {
    const wrapper = mount(UserAvatar, {
      props: { avatar: 'avatar-02', username: 'daniel' },
    })

    expect(wrapper.attributes('data-avatar-id')).toBe('avatar-02')
    expect(wrapper.attributes('style')).toContain('background-position: 33.33333333333333% top')
    expect(wrapper.attributes('style')).toContain('background-size: 400% auto')
  })
})
