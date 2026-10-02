// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  listAdminIngredients: vi.fn<() => Promise<unknown>>(),
  previewIngredientMerge: vi.fn<() => Promise<unknown>>(),
  mergeIngredients: vi.fn<() => Promise<unknown>>(),
}))

vi.mock('@/services/foodApi', () => ({
  foodApi: mocks,
}))

import IngredientMergeSheet from '@/components/admin/IngredientMergeSheet.vue'

const source = {
  id: 9,
  name: 'Roșie',
  default_unit: 'piece',
  is_shoppable: true,
  shopping_category: { id: 3, name: 'Legume', emoji: '🥕', sort_order: 1 },
  aliases: [],
}

const target = {
  id: 12,
  name: 'Roșii',
  default_unit: 'kilogram',
  is_shoppable: true,
  shopping_category: { id: 3, name: 'Legume', emoji: '🥕', sort_order: 1 },
  aliases: [],
}

const allowedPreview = {
  source: { id: 9, name: 'Roșie', default_unit: 'piece' },
  target: { id: 12, name: 'Roșii', default_unit: 'kilogram' },
  impact: {
    recipe_count: 3,
    recipe_ingredient_count: 4,
    alias_count: 2,
    needs_review_count: 3,
  },
  unit_mismatch: true,
  conflicts: [],
  alias_name_collision: {
    has_collision: false,
    canonical_ingredient: null,
    existing_alias: null,
  },
  can_merge: true,
}

function mountSheet() {
  return mount(IngredientMergeSheet, {
    props: {
      source,
      units: {
        piece: { name: 'Bucată', label: 'buc', aliases: [] },
        kilogram: { name: 'Kilogram', label: 'kg', aliases: [] },
      },
    },
    global: { stubs: { teleport: true } },
  })
}

async function searchAndSelectTarget(wrapper: ReturnType<typeof mountSheet>) {
  await wrapper.get('input[type="search"]').setValue('ros')
  vi.advanceTimersByTime(250)
  await flushPromises()
  await wrapper.get('[role="option"]').trigger('click')
  await flushPromises()
}

describe('IngredientMergeSheet', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.listAdminIngredients.mockReset().mockResolvedValue({ data: [source, target] })
    mocks.previewIngredientMerge.mockReset().mockResolvedValue(allowedPreview)
    mocks.mergeIngredients.mockReset().mockResolvedValue({
      target,
      merged_source: { id: source.id, name: source.name },
      result: { recipe_ingredient_count: 4, alias_count: 3, needs_review_count: 3 },
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('debounces canonical search and excludes the source from target results', async () => {
    const wrapper = mountSheet()

    await wrapper.get('input[type="search"]').setValue(' ros ')
    vi.advanceTimersByTime(249)
    expect(mocks.listAdminIngredients).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    await flushPromises()

    expect(mocks.listAdminIngredients).toHaveBeenCalledWith({
      search: 'ros',
      page: 1,
      perPage: 20,
    })
    expect(wrapper.findAll('[role="option"]')).toHaveLength(1)
    expect(wrapper.get('[role="option"]').text()).toContain('Roșii')
    expect(wrapper.get('[role="option"]').text()).toContain('kg · 🥕 Legume')
  })

  it('renders server preview impact and unit warning', async () => {
    const wrapper = mountSheet()
    await searchAndSelectTarget(wrapper)

    expect(mocks.previewIngredientMerge).toHaveBeenCalledWith(9, 12)
    expect(wrapper.text()).toContain('Roșie')
    expect(wrapper.text()).toContain('Roșii')
    expect(wrapper.text()).toContain('Rețete afectate')
    expect(wrapper.text()).toContain('Referințe în rețete')
    expect(wrapper.text()).toContain('Unitățile diferă: buc → kg')
    expect(wrapper.text()).toContain('Cantitățile nu vor fi convertite automat.')
    expect(wrapper.get('.merge-sheet__confirm').attributes('disabled')).toBeUndefined()
  })

  it('shows recipe and alias conflicts and disables merge', async () => {
    mocks.previewIngredientMerge.mockResolvedValue({
      ...allowedPreview,
      conflicts: [{ recipe_id: 55, recipe_name: 'Salată de vară' }],
      alias_name_collision: {
        has_collision: true,
        canonical_ingredient: null,
        existing_alias: {
          id: 4,
          alias: 'Roșie',
          ingredient_id: 20,
          ingredient_name: 'Tomată',
        },
      },
      can_merge: false,
    })
    const wrapper = mountSheet()
    await searchAndSelectTarget(wrapper)

    expect(wrapper.text()).toContain('Combinarea nu poate fi făcută încă.')
    expect(wrapper.text()).toContain('Salată de vară')
    expect(wrapper.text()).toContain('Numele sursei există deja ca alias pentru Tomată.')
    expect(wrapper.get('.merge-sheet__confirm').attributes('disabled')).toBeDefined()
    await wrapper.get('.merge-sheet__confirm').trigger('click')
    expect(mocks.mergeIngredients).not.toHaveBeenCalled()
  })

  it('emits a successful merge and prevents duplicate submissions', async () => {
    let resolveMerge: ((value: unknown) => void) | undefined
    mocks.mergeIngredients.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveMerge = resolve
        }),
    )
    const wrapper = mountSheet()
    await searchAndSelectTarget(wrapper)

    const confirm = wrapper.get('.merge-sheet__confirm')
    await confirm.trigger('click')
    await confirm.trigger('click')

    expect(mocks.mergeIngredients).toHaveBeenCalledTimes(1)
    expect(wrapper.get('.merge-sheet__confirm').attributes('aria-busy')).toBe('true')
    expect(wrapper.get('.merge-sheet__confirm').attributes('disabled')).toBeDefined()

    resolveMerge?.({
      target,
      merged_source: { id: source.id, name: source.name },
      result: { recipe_ingredient_count: 4, alias_count: 3, needs_review_count: 3 },
    })
    await flushPromises()

    expect(wrapper.emitted('merged')).toHaveLength(1)
  })
})
