// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, disposePinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DietStatus, type Diet } from '@/types/diets'
import { ApiError } from '@/services/api'
import { useNotificationStore } from '@/stores/notifications'
import DietGeneralInfo from './DietGeneralInfo.vue'
import DietsEditView from '../DietsEditView.vue'
import AppImageInput from '@/components/ui/AppImageInput.vue'

const mocks = vi.hoisted(() => ({
  updateDiet: vi.fn<(...args: unknown[]) => Promise<{ data: Diet }>>(),
  getDiet: vi.fn<() => Promise<{ data: Diet }>>(),
  getDailyStructureConfig: vi.fn<() => Promise<unknown>>(),
}))
vi.mock('@/services/dietsApi', () => ({ dietsApi: mocks }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '2' } }),
  useRouter: () => ({ push: vi.fn<() => void>() }),
}))

const diet: Diet = {
  id: 2,
  name: 'Dieta existentă',
  description: '<b>Descriere</b>',
  notes: 'Observații existente',
  thumbnail: 'diets/existing.webp',
  thumbnail_url: 'https://example.test/diets/existing.webp',
  status: DietStatus.DRAFT,
  created_at: null,
  updated_at: null,
  sources: [],
  daily_structure: [],
  ingredients: [],
}

describe('diet general information', () => {
  let pinia: ReturnType<typeof createPinia>
  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.clearAllMocks()
    vi.stubGlobal(
      'URL',
      class extends URL {
        static createObjectURL = vi.fn<() => string>(() => 'blob:diet-preview')
        static revokeObjectURL = vi.fn<(url: string) => void>()
      },
    )
    mocks.updateDiet.mockResolvedValue({ data: { ...diet, name: 'Dieta salvată' } })
    mocks.getDiet.mockResolvedValue({ data: structuredClone(diet) })
    mocks.getDailyStructureConfig.mockResolvedValue({ data: [] })
  })
  afterEach(() => {
    disposePinia(pinia)
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it('resolves relative thumbnails against the backend root, not the frontend or API path', async () => {
    vi.stubEnv('VITE_API_BASE_URL', 'https://backend.example.test/api/')
    const wrapper = mount(DietGeneralInfo, {
      props: { diet: { ...diet, thumbnail_url: null } },
    })
    expect(wrapper.get('img').attributes('src')).toBe(
      'https://backend.example.test/diets/existing.webp',
    )
    await wrapper.setProps({ diet: { ...diet, thumbnail_url: '/diets/updated.webp' } })
    expect(wrapper.get('img').attributes('src')).toBe(
      'https://backend.example.test/diets/updated.webp',
    )
    wrapper.unmount()
  })

  it('populates API values as text and keeps edits local until explicit save', async () => {
    const wrapper = mount(DietGeneralInfo, { props: { diet: structuredClone(diet) } })
    expect(wrapper.get<HTMLInputElement>('input[type="text"]').element.value).toBe(diet.name)
    expect(wrapper.findAll('textarea').map((field) => field.element.value)).toEqual([
      diet.description,
      diet.notes,
    ])
    expect(wrapper.get('img').attributes('src')).toBe(diet.thumbnail_url)
    expect(wrapper.find('b').exists()).toBe(false)
    await wrapper.get('input[type="text"]').setValue('Dieta salvată')
    expect(wrapper.props('diet').name).toBe(diet.name)
    expect(mocks.updateDiet).not.toHaveBeenCalled()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mocks.updateDiet).toHaveBeenCalledWith(2, {
      name: 'Dieta salvată',
      description: diet.description,
      notes: diet.notes,
      thumbnail: null,
      removeThumbnail: false,
    })
    expect(wrapper.emitted('updated')?.[0]?.[0]).toMatchObject({ id: 2, name: 'Dieta salvată' })
    expect(useNotificationStore().notifications.at(-1)?.type).toBe('success')
    wrapper.unmount()
  })

  it('previews a new file, submits it and releases object URLs on removal and unmount', async () => {
    const wrapper = mount(DietGeneralInfo, { props: { diet } })
    const image = new File(['image'], 'diet.png', { type: 'image/png' })
    wrapper.findComponent(AppImageInput).vm.$emit('select', image)
    await flushPromises()
    expect(wrapper.get('img').attributes('src')).toBe('blob:diet-preview')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mocks.updateDiet.mock.calls[0]?.[1]).toMatchObject({
      thumbnail: image,
      removeThumbnail: false,
    })
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:diet-preview')
    wrapper.findComponent(AppImageInput).vm.$emit('select', image)
    wrapper.findComponent(AppImageInput).vm.$emit('remove')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(mocks.updateDiet.mock.calls[1]?.[1]).toMatchObject({
      thumbnail: null,
      removeThumbnail: true,
    })
    wrapper.findComponent(AppImageInput).vm.$emit('select', image)
    wrapper.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(3)
  })

  it('retains input and shows Laravel 422 through apiError while allowing retry', async () => {
    const error = new ApiError('Date invalide.', 422, { name: ['Numele este deja folosit.'] })
    mocks.updateDiet.mockRejectedValueOnce(error)
    const apiError = vi.spyOn(useNotificationStore(), 'apiError')
    const wrapper = mount(DietGeneralInfo, { props: { diet } })
    await wrapper.get('input[type="text"]').setValue('Nume nou')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(apiError).toHaveBeenCalledWith(error, 'Nu am putut salva informațiile dietei.')
    expect(wrapper.get<HTMLInputElement>('input[type="text"]').element.value).toBe('Nume nou')
    expect(wrapper.text()).toContain('Numele este deja folosit.')
    expect(wrapper.emitted('updated')).toBeUndefined()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('updated')).toHaveLength(1)
    wrapper.unmount()
  })

  it('validates required name and disables duplicate submission while saving', async () => {
    const wrapper = mount(DietGeneralInfo, { props: { diet } })
    await wrapper.get('input[type="text"]').setValue(' ')
    await wrapper.get('form').trigger('submit')
    expect(mocks.updateDiet).not.toHaveBeenCalled()
    let resolve!: (response: { data: Diet }) => void
    mocks.updateDiet.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    await wrapper.get('input[type="text"]').setValue('Nume nou')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    await wrapper.get('form').trigger('submit')
    expect(mocks.updateDiet).toHaveBeenCalledTimes(1)
    resolve({ data: diet })
    await flushPromises()
    wrapper.unmount()
  })

  it('updates the parent Diet without replacing Settings changed during a pending save', async () => {
    const wrapper = mount(DietsEditView, {
      global: {
        stubs: {
          DietDailyStructure: true,
          DietSources: true,
          DietIngredients: true,
        },
      },
    })
    await flushPromises()
    const general = wrapper.findComponent(DietGeneralInfo)
    let resolve!: (response: { data: Diet }) => void
    mocks.updateDiet.mockReturnValueOnce(
      new Promise((done) => {
        resolve = done
      }),
    )
    await general.get('input[type="text"]').setValue('Nume nou')
    await general.get('form').trigger('submit')
    const daily = [
      { id: 5, diet_id: 2, position: 1, structure: { id: 3, name: 'Prânz', slug: 'pranz' } },
    ]
    wrapper.findComponent({ name: 'DietDailyStructure' }).vm.$emit('reload-list', daily)
    resolve({ data: { ...diet, name: 'Nume nou' } })
    await flushPromises()
    expect(wrapper.get('h1').text()).toContain('Nume nou')
    expect(general.props('diet').daily_structure).toEqual(daily)
    expect(wrapper.findAll('form')).toHaveLength(1)
    expect(mocks.getDiet).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
