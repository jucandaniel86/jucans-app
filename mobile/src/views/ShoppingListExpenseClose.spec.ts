// @vitest-environment happy-dom
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import type { ActiveShoppingList } from '@/types/shopping'
import ShoppingListView from './ShoppingListView.vue'

const mocks = vi.hoisted(() => ({
  getOpen: vi.fn(),
  getList: vi.fn(),
  closeList: vi.fn(),
  registerExpense: vi.fn(),
  projects: vi.fn(),
  noteTypes: vi.fn(),
  push: vi.fn(),
}))
vi.mock('@/services/shoppingApi', () => ({ shoppingApi: mocks }))
vi.mock('@/services/projectsApi', () => ({ projectsApi: { list: mocks.projects } }))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: { noteTypes: mocks.noteTypes },
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: mocks.push }),
  RouterLink: { template: '<a><slot /></a>' },
}))
const own: ActiveShoppingList = {
  id: 10,
  name: 'Shopping',
  status: 'open',
  visibility: 'private',
  created_by: 1,
  creator: { id: 1, name: 'Owner', username: 'owner', avatar: null },
  is_creator: true,
  is_shared_with_me: false,
  closed_at: null,
  created_at: null,
  items_count: 0,
  unchecked_items_count: 0,
  recipes_count: 0,
  items: [],
  recipes: [],
}
const receipt = {
  already_registered: false,
  data: {
    shopping_list_id: 10,
    project_id: 1,
    task_id: 50,
    note_id: 90,
    noted_at: '2026-11-16',
    note_deleted: false,
    list_status: 'closed',
    closed_at: '2026-11-16T12:00:00Z',
  },
}
let wrapper: VueWrapper | undefined
beforeEach(() => {
  vi.resetAllMocks()
  localStorage.clear()
  setActivePinia(createPinia())
  useAuthStore().user = { id: 1, username: 'owner', avatar: null, is_admin: false }
  mocks.getOpen.mockResolvedValue({ data: [structuredClone(own)] })
  mocks.getList.mockResolvedValue({ data: structuredClone(own) })
  mocks.closeList.mockResolvedValue({ data: { ...own, status: 'closed' } })
  mocks.registerExpense.mockResolvedValue(structuredClone(receipt))
  mocks.projects.mockResolvedValue({
    data: [{ id: 1, name: 'Expenses', status: 'active' }],
    meta: { current_page: 1, last_page: 1 },
  })
})
afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  vi.restoreAllMocks()
})
async function open(withCost = false) {
  wrapper = mount(ShoppingListView, { attachTo: document.body })
  await flushPromises()
  await wrapper.get('.shopping-list__close').trigger('click')
  if (withCost) {
    await wrapper.get('.shopping-list__cost-option input').setValue(true)
    await flushPromises()
  }
}
async function values(amount = '41,15') {
  await wrapper!.get('[data-expense="amount"] input').setValue(amount)
  await wrapper!.get('[data-expense="project"] select').setValue('1')
}
async function submit() {
  await wrapper!.get('.shopping-list__dialog form').trigger('submit')
  await flushPromises()
}

it('preserves close-without-cost and performs no Project discovery until requested', async () => {
  await open()
  expect(mocks.projects).not.toHaveBeenCalled()
  await submit()
  expect(mocks.closeList).toHaveBeenCalledExactlyOnceWith(10)
  expect(mocks.registerExpense).not.toHaveBeenCalled()
  expect(mocks.push).toHaveBeenCalledWith({ name: 'shopping-lists' })
})
it('offers only active accessible Projects with configured Shopping Note Types and submits exact decimals', async () => {
  const options = Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...options,
    timeZone: 'Europe/Bucharest',
  })
  await open(true)
  const select = wrapper!.get('[data-expense="project"] select').element as HTMLSelectElement
  expect(Array.from(select.options).map((option) => option.value)).toEqual(['', '1'])
  expect(mocks.projects).toHaveBeenCalledExactlyOnceWith('active', 1, 5, true)
  expect(mocks.noteTypes).not.toHaveBeenCalled()
  await values()
  await submit()
  expect(mocks.registerExpense).toHaveBeenCalledExactlyOnceWith(10, {
    project_id: 1,
    amount: '41.15',
    close_list: true,
    timezone: 'Europe/Bucharest',
  })
  expect(mocks.closeList).not.toHaveBeenCalled()
  const shopping = useActiveShoppingListStore()
  expect(shopping.list).toBeNull()
  expect(shopping.openLists).toEqual([])
  expect(localStorage.getItem('jucans.current-shopping-list.1')).toBeNull()
  expect(mocks.push).toHaveBeenCalledExactlyOnceWith({ name: 'shopping-lists' })
})
it.each(['0', '-1', '41.1234567', 'invalid'])(
  'rejects non-positive or invalid amount %s without submitting',
  async (amount) => {
    await open(true)
    await values(amount)
    await submit()
    expect(mocks.registerExpense).not.toHaveBeenCalled()
    expect(wrapper!.get('[data-expense="amount"]').text()).toContain('sumă pozitivă')
  },
)
it('requires a configured Project', async () => {
  await open(true)
  await wrapper!.get('[data-expense="amount"] input').setValue('1')
  await submit()
  expect(mocks.registerExpense).not.toHaveBeenCalled()
  expect(wrapper!.get('[data-expense="project"]').text()).toContain('Alege un proiect configurat')
})
it('prevents duplicate submit while the request is pending', async () => {
  await open(true)
  await values()
  let resolve!: (value: typeof receipt) => void
  mocks.registerExpense.mockReturnValueOnce(
    new Promise((done) => {
      resolve = done
    }),
  )
  await submit()
  expect(useActiveShoppingListStore().closing).toBe(true)
  expect(wrapper!.get('[data-expense="amount"] input').element.matches(':disabled')).toBe(true)
  await submit()
  expect(mocks.registerExpense).toHaveBeenCalledTimes(1)
  const event = new Event('cancel', { cancelable: true })
  wrapper!.get('.shopping-list__dialog').element.dispatchEvent(event)
  expect(event.defaultPrevented).toBe(true)
  resolve(receipt)
  await flushPromises()
  expect(mocks.push).toHaveBeenCalledTimes(1)
})
it.each([0, 422, 403, 409, 500])(
  'keeps the form and current list after status %s and supports manual retry',
  async (status) => {
    await open(true)
    await values('41.150000')
    mocks.registerExpense.mockRejectedValueOnce(
      new ApiError(
        status === 0
          ? 'Nu ne-am putut conecta la server. Încearcă din nou.'
          : 'Expense unavailable',
        status,
        status === 422 ? { amount: ['Amount rejected'] } : {},
      ),
    )
    await submit()
    expect((wrapper!.get('[data-expense="amount"] input').element as HTMLInputElement).value).toBe(
      '41.150000',
    )
    expect(
      (wrapper!.get('[data-expense="project"] select').element as HTMLSelectElement).value,
    ).toBe('1')
    expect(useActiveShoppingListStore().list?.id).toBe(10)
    expect(useActiveShoppingListStore().closing).toBe(false)
    expect(mocks.push).not.toHaveBeenCalled()
    expect(mocks.closeList).not.toHaveBeenCalled()
    expect(wrapper!.find('.shopping-list__dialog [role="alert"]').exists()).toBe(true)
    await submit()
    expect(mocks.registerExpense).toHaveBeenCalledTimes(2)
    expect(mocks.registerExpense.mock.calls[0]).toEqual(mocks.registerExpense.mock.calls[1])
    expect(mocks.push).toHaveBeenCalledTimes(1)
  },
)
it('rejects an incomplete success response instead of assuming cost registration succeeded', async () => {
  await open(true)
  await values()
  mocks.registerExpense.mockResolvedValueOnce({ data: {} })
  await submit()
  expect(mocks.push).not.toHaveBeenCalled()
  expect(useActiveShoppingListStore().list?.id).toBe(10)
  expect(wrapper!.get('.shopping-list__dialog [role="alert"]').text()).toContain(
    'nu este confirmat',
  )
})
it('loads additional Project pages only on explicit request', async () => {
  mocks.projects.mockResolvedValueOnce({
    data: [{ id: 2, name: 'Other Shopping project', status: 'active' }],
    meta: { current_page: 1, last_page: 2 },
  })
  mocks.projects.mockResolvedValueOnce({
    data: [{ id: 1, name: 'Expenses', status: 'active' }],
    meta: { current_page: 2, last_page: 2 },
  })
  await open(true)
  expect(mocks.projects).toHaveBeenCalledTimes(1)
  const more = wrapper!.findAll('button').find((button) => button.text() === 'Mai multe proiecte')!
  await more.trigger('click')
  await flushPromises()
  expect(mocks.projects).toHaveBeenLastCalledWith('active', 2, 5, true)
  await values()
  await submit()
  expect(mocks.registerExpense).toHaveBeenCalledTimes(1)
})
it('preserves entered amount and offers retry after Project discovery fails', async () => {
  mocks.projects.mockRejectedValueOnce(new ApiError('Offline', 0))
  await open(true)
  await wrapper!.get('[data-expense="amount"] input').setValue('125.50')
  expect(mocks.projects).toHaveBeenCalledTimes(1)
  const retry = wrapper!
    .findAll('button')
    .find((button) => button.text() === 'Reîncearcă proiectele')!
  await retry.trigger('click')
  await flushPromises()
  expect((wrapper!.get('[data-expense="amount"] input').element as HTMLInputElement).value).toBe(
    '125.50',
  )
  expect(mocks.projects).toHaveBeenCalledTimes(2)
})

it('keeps one Project read across reactive updates and repeated cost toggles', async () => {
  await open(true)
  await values()
  for (let i = 0; i < 3; i++) {
    await wrapper!.get('.shopping-list__cost-option input').setValue(false)
    await wrapper!.get('.shopping-list__cost-option input').setValue(true)
    await flushPromises()
  }
  expect(mocks.projects).toHaveBeenCalledExactlyOnceWith('active', 1, 5, true)
  expect(mocks.noteTypes).not.toHaveBeenCalled()
})
it('shows an accurate empty state when the filtered API has no eligible projects', async () => {
  mocks.projects.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1 } })
  await open(true)
  expect(wrapper!.text()).toContain(
    'Nu există proiecte active accesibile configurate pentru Shopping.',
  )
  expect(wrapper!.findAll('button').some((button) => button.text() === 'Mai multe proiecte')).toBe(
    false,
  )
  expect(mocks.projects).toHaveBeenCalledTimes(1)
  expect(mocks.noteTypes).not.toHaveBeenCalled()
})
it('does not duplicate an in-flight project request on toggles or rerenders', async () => {
  let resolve!: (response: unknown) => void
  mocks.projects.mockReturnValueOnce(
    new Promise((done) => {
      resolve = done
    }),
  )
  await open(true)
  await wrapper!.get('.shopping-list__cost-option input').setValue(false)
  await wrapper!.get('.shopping-list__cost-option input').setValue(true)
  await wrapper!.get('[data-expense="amount"] input').setValue('123')
  expect(mocks.projects).toHaveBeenCalledTimes(1)
  resolve({
    data: [1, 2, 3, 4, 5].map((id) => ({ id, name: `Shopping ${id}`, status: 'active' })),
    meta: { current_page: 1, last_page: 1 },
  })
  await flushPromises()
  expect(wrapper!.findAll('[data-expense="project"] option')).toHaveLength(6)
  expect(mocks.projects).toHaveBeenCalledTimes(1)
  expect(mocks.noteTypes).not.toHaveBeenCalled()
})
