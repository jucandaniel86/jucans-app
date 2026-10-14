// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, disposePinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DietStatus, type Diet } from '@/types/diets'
import { useNotificationStore } from '@/stores/notifications'
import AddDraftDietView from './partials/AddDraftDiet.view.vue'
import DietsListView from './DietsListView.vue'

const mocks = vi.hoisted(() => ({ getDiets: vi.fn(), createDraftDiet: vi.fn() }))
vi.mock('@/services/dietsApi', () => ({ dietsApi: mocks }))

function diet(id: number, status: DietStatus): Diet {
  return {
    id,
    status,
    name: `Dieta ${id}`,
    description: '<p>Prima &amp; a doua</p><p>Descriere</p>',
    notes: null,
    thumbnail: null,
    created_at: null,
    updated_at: null,
    sources: [],
    ingredients: [],
    daily_structure: [],
  }
}

describe('Diets list', () => {
  let pinia: ReturnType<typeof createPinia>
  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.clearAllMocks()
    vi.stubEnv('VITE_API_BASE_URL', 'https://backend.example.test/api/')
    mocks.getDiets.mockResolvedValue({
      data: [
        { ...diet(1, DietStatus.ACTIVE), thumbnail: 'diets/one.webp' },
        diet(2, DietStatus.COMPLETED),
        diet(3, DietStatus.DRAFT),
      ],
    })
  })
  afterEach(() => {
    disposePinia(pinia)
    vi.unstubAllEnvs()
  })

  async function setup() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/diets', component: DietsListView },
        { path: '/diets/:id', name: 'diet-edit', component: { template: '<div />' } },
      ],
    })
    await router.push('/diets')
    const wrapper = mount(DietsListView, {
      global: {
        plugins: [pinia, router],
        stubs: { AppModal: { template: '<div><slot /></div>' } },
      },
    })
    return { wrapper, router }
  }

  it('loads cards with plain descriptions, backend image URLs, placeholders and edit links', async () => {
    const { wrapper, router } = await setup()
    await flushPromises()
    expect(wrapper.findAll('.diet-card')).toHaveLength(3)
    expect(wrapper.get('.diet-card__description').text()).toBe('Prima & a doua Descriere')
    expect(wrapper.find('.diet-card__description p').exists()).toBe(false)
    expect(wrapper.get('img').attributes('src')).toBe('https://backend.example.test/diets/one.webp')
    expect(wrapper.findAll('.diet-card__image--placeholder')).toHaveLength(2)
    expect(wrapper.findAll('.diet-card__status').map((badge) => badge.text())).toEqual([
      'Activă',
      'Finalizată',
      'Draft',
    ])
    await wrapper.get('.diet-card__link').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/diets/1')
    wrapper.unmount()
  })

  it('filters all statuses locally without fetching again', async () => {
    const { wrapper } = await setup()
    await flushPromises()
    const buttons = wrapper.findAll('.diets-page__filters button')
    for (const [index, name] of [
      [1, 'Dieta 1'],
      [2, 'Dieta 2'],
      [3, 'Dieta 3'],
    ] as const) {
      await buttons[index]!.trigger('click')
      expect(wrapper.findAll('.diet-card')).toHaveLength(1)
      expect(wrapper.get('.diet-card h2').text()).toBe(name)
      expect(buttons[index]!.attributes('aria-pressed')).toBe('true')
    }
    await buttons[0]!.trigger('click')
    expect(wrapper.findAll('.diet-card')).toHaveLength(3)
    expect(mocks.getDiets).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('distinguishes loading, an empty collection and a filter with no results', async () => {
    let resolve!: (value: { data: Diet[] }) => void
    mocks.getDiets.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    const { wrapper } = await setup()
    expect(wrapper.text()).toContain('Se încarcă dietele')
    expect(wrapper.text()).not.toContain('Nu există diete')
    resolve({ data: [] })
    await flushPromises()
    expect(wrapper.text()).toContain('Nu există diete încă.')
    wrapper.unmount()
    mocks.getDiets.mockResolvedValueOnce({ data: [diet(1, DietStatus.ACTIVE)] })
    const second = await setup()
    await flushPromises()
    await second.wrapper.findAll('.diets-page__filters button')[3]!.trigger('click')
    expect(second.wrapper.text()).toContain('Nu există diete pentru filtrul selectat.')
    second.wrapper.unmount()
  })

  it('notifies API errors and allows retry instead of showing an empty list', async () => {
    const error = new Error('Internal failure')
    mocks.getDiets.mockRejectedValueOnce(error)
    const apiError = vi.spyOn(useNotificationStore(), 'apiError')
    const { wrapper } = await setup()
    await flushPromises()
    expect(apiError).toHaveBeenCalledWith(error, 'Nu am putut încărca dietele. Încearcă din nou.')
    expect(wrapper.text()).not.toContain('Internal failure')
    expect(wrapper.text()).not.toContain('Nu există diete încă.')
    await wrapper.get('.diets-page__state button').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('.diet-card')).toHaveLength(3)
    wrapper.unmount()
  })

  it('preserves Draft creation and navigation to the editor', async () => {
    const created = diet(9, DietStatus.DRAFT)
    mocks.createDraftDiet.mockResolvedValueOnce({ data: created })
    const { wrapper, router } = await setup()
    await wrapper.get('.diets-page__add').trigger('click')
    wrapper.findComponent(AddDraftDietView).vm.$emit('onSave', { name: created.name })
    await flushPromises()
    expect(mocks.createDraftDiet).toHaveBeenCalledWith({ name: created.name })
    expect(router.currentRoute.value.path).toBe('/diets/9')
    expect(useNotificationStore().notifications.at(-1)?.type).toBe('success')
    wrapper.unmount()
  })
})
