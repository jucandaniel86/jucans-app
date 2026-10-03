// @vitest-environment happy-dom

import { mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import AppDrawer from '@/components/app/AppDrawer.vue'
import { MENU_SECTIONS } from '@/config/menu'
import { useAuthStore } from '@/stores/auth'

describe('AppDrawer', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  function mountDrawer() {
    return mount(AppDrawer, {
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
  }

  it('renders public configured routes and disables restricted items for non-admin users', () => {
    useAuthStore().user = { id: 1, username: 'daniel', avatar: null, is_admin: false }

    const wrapper = mountDrawer()
    const configuredItems = MENU_SECTIONS.flatMap((section) => section.items)
    const links = wrapper.findAllComponents(RouterLinkStub)
    const disabledItems = wrapper.findAll('.app-drawer__nav button:disabled')

    expect(links.map((link) => link.props('to'))).toEqual([
      { name: 'home' },
      { name: 'recipes' },
      { name: 'recipe-create' },
      { name: 'shopping-lists' },
    ])
    expect(disabledItems).toHaveLength(
      configuredItems.filter((item) => item.restricted || item.soon || !item.routeName).length,
    )
    expect(wrapper.findAll('.drawer-link__soon')).toHaveLength(
      configuredItems.filter((item) => item.soon).length,
    )
  })

  it('renders restricted configured routes for admin users', () => {
    useAuthStore().user = { id: 1, username: 'daniel', avatar: null, is_admin: true }

    const wrapper = mountDrawer()
    const links = wrapper.findAllComponents(RouterLinkStub)

    expect(links.map((link) => link.props('to'))).toEqual([
      { name: 'home' },
      { name: 'recipes' },
      { name: 'recipe-create' },
      { name: 'shopping-lists' },
      { name: 'admin-ingredients' },
      { name: 'admin-recipe-reviews' },
    ])
  })
})
