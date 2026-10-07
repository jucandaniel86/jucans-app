// @vitest-environment happy-dom
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  getOpen: vi.fn(),
  getList: vi.fn(),
  push: vi.fn(),
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRouter: () => ({ push: mocks.push }),
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/foodApi', () => ({
  foodApi: { getConfig: vi.fn().mockResolvedValue({ units: {} }) },
}))
import ShoppingListsView from '@/views/ShoppingListsView.vue'

const active = {
  id: 1,
  name: null,
  status: 'open',
  is_creator: true,
  closed_at: null,
  items_count: 12,
  recipes_count: 3,
  unchecked_items_count: 5,
}
const history = {
  id: 2,
  name: null,
  status: 'closed',
  closed_at: '2026-09-28T12:00:00Z',
  items_count: 18,
  recipes_count: 4,
  unchecked_items_count: 0,
}
function render() {
  return mount(ShoppingListsView, { global: { stubs: { RouterLink: RouterLinkStub } } })
}

describe('shopping lists index', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1 } })
    mocks.getOpen.mockReset().mockResolvedValue({ data: [] })
    mocks.getList.mockReset().mockResolvedValue({
      data: {
        ...active,
        items: Array.from({ length: 12 }, (_, index) => ({
          id: index + 1,
          is_checked: index >= 5,
        })),
      },
    })
  })
  it('renders the empty index without creating a list', async () => {
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).toContain('Nu ai liste deschise.')
    await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
    expect(wrapper.text()).toContain('Nu există liste în istoric.')
    await wrapper.findAll('[role="tab"]')[0]!.trigger('click')
    expect(mocks.list).toHaveBeenCalledWith(1)
  })
  it('shows active first, friendly historical dates, counts and concrete detail links', async () => {
    mocks.getOpen.mockResolvedValue({ data: [active] })
    mocks.list.mockResolvedValue({
      data: [active, history],
      meta: { current_page: 1, last_page: 1 },
    })
    const wrapper = render()
    await flushPromises()
    expect(
      wrapper.findAll('[role="tab"]').map((button) =>
        button
          .findAll('span')
          .map((span) => span.text())
          .join(' '),
      ),
    ).toEqual(['🛒 Active (1)', '🕘 Istoric (1)'])
    expect(wrapper.text()).toContain('12 produse · 3 rețete')
    expect(wrapper.text()).toContain('5 de cumpărat')
    expect(wrapper.text()).not.toContain('28 septembrie 2026')
    expect(wrapper.text()).toContain('✓ Curentă')
    expect(wrapper.find('.shopping-lists__select').exists()).toBe(false)
    expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'))).toEqual([
      { name: 'shopping-list-detail', params: { id: 1 } },
    ])
    await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
    expect(wrapper.text()).toContain('28 septembrie 2026')
    expect(wrapper.text()).not.toContain('5 de cumpărat')
    expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'))).toEqual([
      { name: 'shopping-list-detail', params: { id: 2 } },
    ])
    wrapper.unmount()
    const reopened = render()
    await flushPromises()
    expect(reopened.findAll('[role="tab"]')[1]!.attributes('aria-selected')).toBe('true')
    await reopened.findAll('[role="tab"]')[0]!.trigger('click')
  })
  it('loads further history and retains named list titles', async () => {
    mocks.getOpen.mockResolvedValue({ data: [active] })
    mocks.list
      .mockResolvedValueOnce({ data: [active], meta: { current_page: 1, last_page: 2 } })
      .mockResolvedValueOnce({
        data: [{ ...history, name: 'Weekend' }],
        meta: { current_page: 2, last_page: 2 },
      })
    const wrapper = render()
    await flushPromises()
    await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Mai multe liste')!
      .trigger('click')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(2)
    expect(wrapper.text()).toContain('Weekend')
    expect(wrapper.findAll('.shopping-lists__row')).toHaveLength(1)
    await wrapper.findAll('[role="tab"]')[0]!.trigger('click')
  })
  it('allows retry after a load failure', async () => {
    mocks.list.mockRejectedValueOnce(new Error('offline'))
    const wrapper = render()
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Încearcă din nou')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
  it('shows owned, shared-with-me and public lists from the accessible-list index', async () => {
    mocks.getOpen.mockResolvedValue({
      data: [
        { ...active, is_creator: true, visibility: 'private', creator: { name: 'alina' } },
        {
          ...active,
          id: 3,
          is_creator: false,
          is_shared_with_me: true,
          visibility: 'shared',
          creator: { name: 'daniel' },
        },
      ],
    })
    mocks.list.mockResolvedValue({
      data: [
        { ...active, is_creator: true, visibility: 'private', creator: { name: 'alina' } },
        {
          ...active,
          id: 3,
          is_creator: false,
          is_shared_with_me: true,
          visibility: 'shared',
          creator: { name: 'daniel' },
        },
        { ...history, visibility: 'public', is_creator: false, creator: { name: 'daniel' } },
      ],
      meta: { current_page: 1, last_page: 1 },
    })
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).toContain('Creată de tine')
    expect(wrapper.text()).toContain('Partajată cu tine')
    expect(wrapper.text()).toContain('Creată de daniel')
    expect(wrapper.text()).not.toContain('Publică')
    expect(wrapper.findAll('.shopping-lists__row')).toHaveLength(2)
    expect(wrapper.findAll('.shopping-lists__select')).toHaveLength(2)
    await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
    expect(wrapper.text()).toContain('Publică')
    expect(wrapper.findAll('.shopping-lists__row')).toHaveLength(1)
    expect(wrapper.find('.shopping-lists__select').exists()).toBe(false)
    await wrapper.findAll('[role="tab"]')[0]!.trigger('click')
  })
})
