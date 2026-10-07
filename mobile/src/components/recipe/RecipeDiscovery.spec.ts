// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import RecipeSearch from './RecipeSearch.vue'
import RecipesView from '@/views/RecipesView.vue'
import type { RecipeFilters } from '@/types/food'

const mocks = vi.hoisted(() => ({
  listRecipes: vi.fn<(filters?: RecipeFilters) => Promise<unknown>>(),
  listTags: vi.fn<() => Promise<unknown>>(),
}))
vi.mock('@/services/foodApi', () => ({ foodApi: mocks }))

const recipe = {
  id: 12,
  name: 'Pui rapid',
  image_url: null,
  tags: [],
  creator: { username: 'daniel' },
}
const response = { data: [recipe], meta: { total: 8, current_page: 1, last_page: 2 } }

async function render(component: typeof RecipeSearch | typeof RecipesView, path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/recipes', name: 'recipes', component: RecipesView },
      { path: '/recipes/create', name: 'recipe-create', component: { template: '<div />' } },
      { path: '/recipes/:id', name: 'recipe-detail', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  const wrapper = mount(component, { global: { plugins: [router], stubs: { RecipeCard: true } } })
  await flushPromises()
  return { wrapper, router }
}

describe('Recipe discovery', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    mocks.listRecipes.mockResolvedValue(response)
    mocks.listTags.mockResolvedValue({
      data: [
        { id: 1, name: 'Rapid', emoji: '⚡' },
        { id: 2, name: 'La cuptor', emoji: '🔥' },
      ],
    })
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('restores query filters, combines tags and search, and preserves filtered pagination', async () => {
    const { wrapper, router } = await render(RecipesView, '/recipes?q=pui&tags=1')
    expect(mocks.listRecipes).toHaveBeenLastCalledWith({ search: 'pui', tags: [1], page: 1 })
    const chips = wrapper.findAll('.recipes-page__filters button')
    expect(chips[0]!.attributes('aria-pressed')).toBe('false')
    await chips[2]!.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ q: 'pui', tags: '1,2' })
    expect(mocks.listRecipes).toHaveBeenLastCalledWith({ search: 'pui', tags: [1, 2], page: 1 })
    expect(wrapper.find('.recipes-page__count').text()).toContain('8 rețete')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Încarcă mai multe')!
      .trigger('click')
    await flushPromises()
    expect(mocks.listRecipes).toHaveBeenLastCalledWith({ search: 'pui', tags: [1, 2], page: 2 })
    await chips[1]!.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.tags).toBe('2')
    wrapper.unmount()
  })

  it('debounces index search and resets an empty filtered result', async () => {
    const { wrapper, router } = await render(RecipesView, '/recipes?tags=1')
    mocks.listRecipes.mockResolvedValue({
      data: [],
      meta: { total: 0, current_page: 1, last_page: 1 },
    })
    await wrapper.find('input').setValue('cartofi')
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ q: 'cartofi', tags: '1' })
    expect(wrapper.text()).toContain('Nicio rețetă nu corespunde filtrelor.')
    await wrapper.find('.recipes-state button').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({})
    expect(mocks.listRecipes).toHaveBeenLastCalledWith({ search: undefined, tags: [], page: 1 })
    wrapper.unmount()
  })

  it('debounces autocomplete, skips short queries and navigates directly to a result', async () => {
    const { wrapper, router } = await render(RecipeSearch, '/recipes/1')
    await wrapper.find('input').setValue('p')
    await vi.advanceTimersByTimeAsync(300)
    expect(mocks.listRecipes).not.toHaveBeenCalled()
    await wrapper.find('input').setValue('pu')
    await wrapper.find('input').setValue('pui')
    await vi.advanceTimersByTimeAsync(300)
    expect(mocks.listRecipes).toHaveBeenCalledExactlyOnceWith({ search: 'pui', perPage: 5 })
    expect(wrapper.find('[role="option"]').text()).toBe('Pui rapid')
    await wrapper.find('[role="option"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('12')
    expect(wrapper.find('input').element.value).toBe('')
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('carries the search query to the index via Enter and view all', async () => {
    const { wrapper, router } = await render(RecipeSearch, '/recipes/1')
    await wrapper.find('input').setValue('cartofi')
    await wrapper.find('input').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(router.currentRoute.value.query.q).toBe('cartofi')
    await router.push('/recipes/1')
    await wrapper.find('input').setValue('pui')
    await vi.advanceTimersByTimeAsync(300)
    await wrapper.find('.recipe-search__all').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.q).toBe('pui')
    wrapper.unmount()
  })

  it('supports keyboard selection and reports empty results', async () => {
    const { wrapper, router } = await render(RecipeSearch, '/recipes/1')
    mocks.listRecipes.mockResolvedValueOnce({ data: [] })
    await wrapper.find('input').setValue('nimic')
    await vi.advanceTimersByTimeAsync(300)
    expect(wrapper.text()).toContain('Nicio rețetă găsită.')
    await wrapper.find('input').setValue('pui')
    await vi.advanceTimersByTimeAsync(300)
    await wrapper.find('input').trigger('keydown', { key: 'ArrowUp' })
    expect(wrapper.find('[role="option"]').attributes('aria-selected')).toBe('true')
    await wrapper.find('input').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('12')
    wrapper.unmount()
  })

  it('ignores stale autocomplete responses after clearing and closes on outside tap', async () => {
    let resolve!: (value: unknown) => void
    mocks.listRecipes.mockReturnValue(
      new Promise((done) => {
        resolve = done
      }),
    )
    const { wrapper } = await render(RecipeSearch, '/recipes/1')
    await wrapper.find('input').setValue('pui')
    await vi.advanceTimersByTimeAsync(300)
    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    await flushPromises()
    expect(wrapper.find('.recipe-search__dropdown').exists()).toBe(false)
    await wrapper.find('input').trigger('focus')
    await wrapper.find('[aria-label="Șterge căutarea"]').trigger('click')
    resolve(response)
    await flushPromises()
    expect(wrapper.find('.recipe-search__dropdown').exists()).toBe(false)
    wrapper.unmount()
  })
})
