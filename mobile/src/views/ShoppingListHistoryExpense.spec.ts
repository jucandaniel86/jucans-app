// @vitest-environment happy-dom
import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'
import ShoppingListsView from './ShoppingListsView.vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  getOpen: vi.fn(),
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
vi.mock('vue-router', async (original) => ({
  ...(await original<typeof import('vue-router')>()),
  useRouter: () => ({ push: mocks.push }),
}))
function historical(id: number, registered: boolean, allowed = true) {
  return {
    id,
    name: `List ${id}`,
    status: 'closed',
    visibility: 'shared',
    created_by: 2,
    creator: { id: 2, name: 'Owner', username: 'owner', avatar: null },
    is_creator: false,
    is_shared_with_me: allowed,
    closed_at: '2026-11-05T22:30:00Z',
    items_count: 5,
    recipes_count: 2,
    unchecked_items_count: 0,
    has_expense: registered,
    can_register_expense: allowed && !registered,
  }
}
const receipt = {
  already_registered: false,
  data: {
    shopping_list_id: 20,
    project_id: 9,
    task_id: 50,
    note_id: 900,
    noted_at: '2026-11-06',
    note_deleted: false,
    list_status: 'closed',
    closed_at: '2026-11-05T22:30:00Z',
  },
}
let wrapper: VueWrapper | undefined
beforeEach(() => {
  vi.resetAllMocks()
  localStorage.clear()
  setActivePinia(createPinia())
  mocks.list.mockResolvedValue({
    data: [
      historical(10, true),
      historical(20, false),
      historical(30, false, false),
      historical(40, false),
    ],
    meta: { current_page: 1, last_page: 2, total: 5 },
  })
  mocks.getOpen.mockResolvedValue({ data: [] })
  mocks.registerExpense.mockResolvedValue(structuredClone(receipt))
  mocks.projects.mockResolvedValue({
    data: [{ id: 9, name: 'Expenses', status: 'active' }],
    meta: { current_page: 1, last_page: 1 },
  })
})
afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  vi.restoreAllMocks()
})
async function render() {
  wrapper = mount(ShoppingListsView, {
    attachTo: document.body,
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
  await flushPromises()
  await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
}
function row(id: number) {
  return wrapper!
    .findAll('.shopping-lists__row')
    .find((candidate) => candidate.get('strong').text() === `List ${id}`)!
}
async function open() {
  await render()
  await row(20).get('button').trigger('click')
  await flushPromises()
  await wrapper!.get('[data-expense="amount"] input').setValue('41,15')
  await wrapper!.get('[data-expense="project"] select').setValue('9')
}
async function submit() {
  await wrapper!.get('.shopping-lists__expense-dialog form').trigger('submit')
  await flushPromises()
}
it('renders current-user receipt indicators and hides registration for nonparticipants without per-row reads', async () => {
  await render()
  expect(row(10).text()).toContain('Cost înregistrat')
  expect(row(10).find('button').exists()).toBe(false)
  expect(row(20).get('button').text()).toBe('Adaugă cost')
  expect(row(30).find('button').exists()).toBe(false)
  expect(mocks.list).toHaveBeenCalledExactlyOnceWith(1)
  expect(mocks.noteTypes).not.toHaveBeenCalled()
  expect(mocks.projects).not.toHaveBeenCalled()
})
it('registers with close_list=false and device timezone, then updates only the History row in place', async () => {
  const options = Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...options,
    timeZone: 'Europe/Bucharest',
  })
  await open()
  expect(mocks.projects).toHaveBeenCalledExactlyOnceWith('active', 1, 5, true)
  expect(mocks.noteTypes).not.toHaveBeenCalled()
  await submit()
  expect(mocks.registerExpense).toHaveBeenCalledExactlyOnceWith(20, {
    project_id: 9,
    amount: '41.15',
    close_list: false,
    timezone: 'Europe/Bucharest',
  })
  expect(row(20).text()).toContain('Cost înregistrat')
  expect(row(40).get('button').text()).toBe('Adaugă cost')
  expect(mocks.list).toHaveBeenCalledTimes(1)
  expect(mocks.getOpen).toHaveBeenCalledTimes(1)
  expect(mocks.push).not.toHaveBeenCalled()
  expect(wrapper!.findAll('[role="tab"]')[1]!.attributes('aria-selected')).toBe('true')
  expect(wrapper!.findAll('button').some((button) => button.text() === 'Mai multe liste')).toBe(
    true,
  )
})
it.each([0, 422, 403, 409, 500])(
  'retains values and row state after error %s and retries the same request',
  async (status) => {
    await open()
    mocks.registerExpense.mockRejectedValueOnce(
      new ApiError('Unavailable', status, status === 422 ? { amount: ['Invalid amount'] } : {}),
    )
    await submit()
    expect(row(20).get('button').text()).toBe('Adaugă cost')
    expect((wrapper!.get('[data-expense="amount"] input').element as HTMLInputElement).value).toBe(
      '41,15',
    )
    expect(
      (wrapper!.get('[data-expense="project"] select').element as HTMLSelectElement).value,
    ).toBe('9')
    expect(wrapper!.find('.shopping-lists__expense-dialog [role="alert"]').exists()).toBe(true)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    await submit()
    expect(mocks.registerExpense).toHaveBeenCalledTimes(2)
    expect(mocks.registerExpense.mock.calls[0]).toEqual(mocks.registerExpense.mock.calls[1])
    expect(row(20).text()).toContain('Cost înregistrat')
  },
)
it('blocks double submission and dismissal while saving', async () => {
  await open()
  let resolve!: (value: typeof receipt) => void
  mocks.registerExpense.mockReturnValueOnce(
    new Promise((done) => {
      resolve = done
    }),
  )
  await submit()
  await submit()
  expect(mocks.registerExpense).toHaveBeenCalledTimes(1)
  expect((wrapper!.get('[data-expense="amount"] input').element as HTMLInputElement).disabled).toBe(
    true,
  )
  const event = new Event('cancel', { cancelable: true })
  wrapper!.get('.shopping-lists__expense-dialog').element.dispatchEvent(event)
  await flushPromises()
  expect(wrapper!.find('.shopping-lists__expense-dialog form').exists()).toBe(true)
  resolve(receipt)
  await flushPromises()
  expect(row(20).text()).toContain('Cost înregistrat')
})
it('does not interpret malformed success JSON as a confirmed registration', async () => {
  await open()
  mocks.registerExpense.mockResolvedValueOnce({ data: {} })
  await submit()
  expect(row(20).get('button').text()).toBe('Adaugă cost')
  expect(wrapper!.get('.shopping-lists__expense-dialog [role="alert"]').text()).toContain(
    'nu este confirmat',
  )
  expect(mocks.list).toHaveBeenCalledTimes(1)
})
it('validates through the same positive decimal form used by closing', async () => {
  await open()
  await wrapper!.get('[data-expense="amount"] input').setValue('0')
  await submit()
  expect(mocks.registerExpense).not.toHaveBeenCalled()
  expect(wrapper!.get('[data-expense="amount"]').text()).toContain('sumă pozitivă')
})
