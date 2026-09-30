// @vitest-environment happy-dom

import { mount, RouterLinkStub } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AppDrawer from '@/components/app/AppDrawer.vue'
import { MENU_SECTIONS } from '@/config/menu'

describe('AppDrawer', () => {
  it('renders configured routes and disables unavailable menu items', () => {
    const wrapper = mount(AppDrawer, {
      props: {
        open: true,
        username: 'daniel',
        avatar: null,
        loggingOut: false,
        updatingAvatar: false,
        avatarError: '',
      },
      global: {
        stubs: {
          RouterLink: RouterLinkStub,
        },
      },
    })

    const configuredItems = MENU_SECTIONS.flatMap((section) => section.items)
    const links = wrapper.findAllComponents(RouterLinkStub)
    const disabledItems = wrapper.findAll('.app-drawer__nav button:disabled')

    expect(links.map((link) => link.props('to'))).toEqual([
      { name: 'home' },
      { name: 'recipes' },
      { name: 'recipe-create' },
    ])
    expect(disabledItems).toHaveLength(
      configuredItems.filter((item) => item.restricted || item.soon || !item.routeName).length,
    )
    expect(wrapper.findAll('.drawer-link__soon')).toHaveLength(
      configuredItems.filter((item) => item.soon).length,
    )
  })
})
