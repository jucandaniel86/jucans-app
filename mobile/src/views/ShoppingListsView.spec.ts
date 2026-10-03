// @vitest-environment happy-dom
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
const mocks = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
import ShoppingListsView from '@/views/ShoppingListsView.vue'

const active = {
  id: 1,
  name: null,
  status: 'open',
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
  beforeEach(() =>
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1 } }),
  )
  it('renders the empty index without creating a list', async () => {
    const wrapper = render()
    await flushPromises()
    expect(wrapper.text()).toContain('Nu ai încă liste de cumpărături.')
    expect(mocks.list).toHaveBeenCalledWith(1)
  })
  it('shows active first, friendly historical dates, counts and concrete detail links', async () => {
    mocks.list.mockResolvedValue({
      data: [active, history],
      meta: { current_page: 1, last_page: 1 },
    })
    const wrapper = render()
    await flushPromises()
    expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['Listă activă', 'Istoric'])
    expect(wrapper.text()).toContain('12 produse · 3 rețete')
    expect(wrapper.text()).toContain('5 de cumpărat')
    expect(wrapper.text()).toContain('28 septembrie 2026')
    expect(wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'))).toEqual([
      { name: 'shopping-list-detail', params: { id: 1 } },
      { name: 'shopping-list-detail', params: { id: 2 } },
    ])
  })
  it('loads further history and retains named list titles', async () => {
    mocks.list
      .mockResolvedValueOnce({ data: [active], meta: { current_page: 1, last_page: 2 } })
      .mockResolvedValueOnce({
        data: [{ ...history, name: 'Weekend' }],
        meta: { current_page: 2, last_page: 2 },
      })
    const wrapper = render()
    await flushPromises()
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(2)
    expect(wrapper.text()).toContain('Weekend')
    expect(wrapper.findAll('.shopping-lists__row')).toHaveLength(2)
  })
  it('allows retry after a load failure', async () => {
    mocks.list.mockRejectedValueOnce(new Error('offline'))
    const wrapper = render()
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
})
