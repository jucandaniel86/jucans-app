// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'
import { createPinia, setActivePinia } from 'pinia'
import { useNotificationStore } from '@/stores/notifications'
import type { ShoppingListSummary } from '@/types/shopping'

const mocks = vi.hoisted(() => ({
  getUsers: vi.fn(),
  getShareableUsers: vi.fn(),
  attachUser: vi.fn(),
  detachUser: vi.fn(),
  setVisibility: vi.fn(),
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
import ShoppingListSharing from '@/components/shopping/ShoppingListSharing.vue'

const daniel = { id: 1, name: 'daniel', username: 'daniel', avatar: null }
const alina = { id: 2, name: 'alina', username: 'alina', avatar: null }
const list: ShoppingListSummary = {
  id: 5,
  name: 'Weekend',
  status: 'open',
  visibility: 'private',
  created_by: 1,
  creator: daniel,
  is_creator: true,
  is_shared_with_me: false,
  closed_at: null,
  created_at: null,
  items_count: 1,
  recipes_count: 1,
  unchecked_items_count: 1,
}
function render(value = list) {
  return mount(ShoppingListSharing, {
    props: { list: structuredClone(value) },
    global: { stubs: { Teleport: true } },
  })
}
async function open(wrapper: ReturnType<typeof render>) {
  await wrapper.get('button').trigger('click')
  await flushPromises()
}

describe('shopping list sharing', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
    mocks.getUsers.mockResolvedValue({ data: [] })
    mocks.getShareableUsers.mockResolvedValue({
      data: [
        { id: 2, name: 'alina' },
        { id: 3, name: 'Other' },
        { id: 999, name: 'Removed account' },
      ],
    })
    mocks.attachUser.mockResolvedValue({ data: alina })
    mocks.setVisibility.mockResolvedValue({ data: { ...list, visibility: 'shared' } })
    mocks.detachUser.mockResolvedValue(undefined)
  })
  it('restricts sharing controls and member requests to the creator', async () => {
    const wrapper = render({ ...list, is_creator: false, is_shared_with_me: true })
    expect(wrapper.find('button').exists()).toBe(false)
    expect(mocks.getUsers).not.toHaveBeenCalled()
  })
  it('attaches an existing account before making a private list shared', async () => {
    const wrapper = render()
    await open(wrapper)
    await wrapper.get('.shopping-sharing__add select').setValue('2')
    await wrapper.get('.shopping-sharing__add').trigger('submit')
    await flushPromises()
    expect(mocks.attachUser).toHaveBeenCalledWith(5, 2)
    expect(mocks.setVisibility).toHaveBeenCalledWith(5, 'shared')
    expect(mocks.attachUser.mock.invocationCallOrder[0]).toBeLessThan(
      mocks.setVisibility.mock.invocationCallOrder[0]!,
    )
    expect(wrapper.emitted('updated')?.[0]?.[0]).toMatchObject({ id: 5, visibility: 'shared' })
    expect(useNotificationStore().notifications.at(-1)?.message).toBe('Lista este partajată cu alina.')
  })
  it('retains a successful attachment and reports a failed visibility change', async () => {
    mocks.setVisibility.mockRejectedValueOnce(new Error('offline'))
    const wrapper = render()
    await open(wrapper)
    await wrapper.get('.shopping-sharing__add select').setValue('2')
    await wrapper.get('.shopping-sharing__add').trigger('submit')
    await flushPromises()
    expect(wrapper.get('.shopping-sharing__members').text()).toContain('alina')
    expect(useNotificationStore().notifications.at(-1)).toMatchObject({ type: 'warning', message: expect.stringContaining('lista este încă privată') })
    expect(wrapper.emitted('updated')).toBeUndefined()
  })
  it('requires a selected account and handles an account deleted after lookup', async () => {
    const wrapper = render()
    await open(wrapper)
    await wrapper.get('.shopping-sharing__add').trigger('submit')
    expect(mocks.attachUser).not.toHaveBeenCalled()
    mocks.attachUser.mockRejectedValueOnce(new ApiError('Invalid', 422))
    await wrapper.get('.shopping-sharing__add select').setValue('999')
    await wrapper.get('.shopping-sharing__add').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Acest cont nu există')
    expect(mocks.setVisibility).not.toHaveBeenCalled()
  })
  it('keeps sharing relationships when visibility changes and does not narrow public access on attachment', async () => {
    mocks.getUsers.mockResolvedValue({ data: [alina] })
    mocks.setVisibility.mockResolvedValue({ data: { ...list, visibility: 'private' } })
    const wrapper = render({ ...list, visibility: 'shared' })
    await open(wrapper)
    await wrapper.get('.shopping-sharing__visibility select').setValue('private')
    await wrapper.get('.shopping-sharing__visibility').trigger('submit')
    await flushPromises()
    expect(wrapper.get('.shopping-sharing__members').text()).toContain('alina')
    expect(mocks.detachUser).not.toHaveBeenCalled()
    await wrapper.setProps({ list: { ...list, visibility: 'public' } })
    mocks.attachUser.mockResolvedValueOnce({ data: { ...alina, id: 3, name: 'Other' } })
    await wrapper.get('.shopping-sharing__add select').setValue('3')
    await wrapper.get('.shopping-sharing__add').trigger('submit')
    await flushPromises()
    expect(mocks.setVisibility).toHaveBeenCalledTimes(1)
    expect(wrapper.findAll('.shopping-sharing__members li')).toHaveLength(2)
  })
  it('confirms removal, retains the member on failure and supports retry', async () => {
    mocks.getUsers.mockResolvedValue({ data: [alina] })
    const wrapper = render({ ...list, visibility: 'shared' })
    await open(wrapper)
    await wrapper.get('.shopping-sharing__members button').trigger('click')
    expect(mocks.detachUser).not.toHaveBeenCalled()
    mocks.detachUser.mockRejectedValueOnce(new Error('offline'))
    await wrapper.get('.shopping-sharing__confirmation .app-button--primary').trigger('click')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Nu am putut elimina')
    expect(wrapper.findAll('.shopping-sharing__members li')).toHaveLength(1)
    await wrapper.get('.shopping-sharing__confirmation .app-button--primary').trigger('click')
    await flushPromises()
    expect(mocks.detachUser).toHaveBeenLastCalledWith(5, 2)
    expect(wrapper.findAll('.shopping-sharing__members li')).toHaveLength(0)
  })
  it('allows retry after a member loading failure', async () => {
    mocks.getUsers.mockRejectedValueOnce(new Error('offline'))
    const wrapper = render()
    await open(wrapper)
    expect(wrapper.find('.shopping-sharing__add select').exists()).toBe(false)
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Încearcă din nou')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('.shopping-sharing__add select').exists()).toBe(true)
  })
  it('reports an authorization denial without claiming the list was shared', async () => {
    mocks.attachUser.mockRejectedValueOnce(new ApiError('Forbidden', 403))
    const wrapper = render()
    await open(wrapper)
    await wrapper.get('.shopping-sharing__add select').setValue('2')
    await wrapper.get('.shopping-sharing__add').trigger('submit')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Doar creatorul')
    expect(wrapper.emitted('updated')).toBeUndefined()
    expect(mocks.setVisibility).not.toHaveBeenCalled()
  })
})
