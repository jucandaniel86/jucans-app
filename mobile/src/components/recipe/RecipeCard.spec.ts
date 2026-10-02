// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const routerMocks = vi.hoisted(() => ({
  push: vi.fn<(location: unknown) => void>(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => routerMocks,
}))

import RecipeCard from '@/components/recipe/RecipeCard.vue'
import { useAuthStore } from '@/stores/auth'
import type { RecipeSummary } from '@/types/food'

const recipe: RecipeSummary = {
  id: 12,
  name: 'Ciorbă rădăuțeană',
  description: null,
  image: null,
  image_url: null,
  url: 'https://youtube.com/watch?v=test',
  creator: { id: 1, username: 'daniel', avatar: null },
  tags: [
    { id: 1, name: 'Ciorbă', normalized_name: 'ciorba', emoji: '🍲' },
    { id: 2, name: 'Pui', normalized_name: 'pui', emoji: '🐔' },
    { id: 3, name: 'Rapid', normalized_name: 'rapid', emoji: '⚡' },
    { id: 4, name: 'Favorit', normalized_name: 'favorit', emoji: null },
  ],
  created_at: '2026-09-29T10:00:00Z',
  updated_at: '2026-09-29T10:00:00Z',
}

describe('RecipeCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    routerMocks.push.mockReset()
  })

  it('shows the fallback, three tags and the remaining count', () => {
    const wrapper = mount(RecipeCard, { props: { recipe } })

    expect(wrapper.find('img').classes()).toContain('recipe-card__image--placeholder')
    expect(wrapper.findAll('.recipe-card__tags span')).toHaveLength(4)
    expect(wrapper.text()).toContain('+1')
    expect(wrapper.text()).toContain('▶ YouTube')
  })

  it('opens the recipe while the source link remains independent', async () => {
    const wrapper = mount(RecipeCard, { props: { recipe } })

    await wrapper.trigger('click')
    expect(routerMocks.push).toHaveBeenCalledWith({
      name: 'recipe-detail',
      params: { id: 12 },
    })

    routerMocks.push.mockReset()
    const sourceLink = wrapper.find('a')
    sourceLink.element.addEventListener('click', (event) => event.preventDefault(), { once: true })
    await sourceLink.trigger('click')
    expect(routerMocks.push).not.toHaveBeenCalled()
  })

  it('opens edit directly without triggering card navigation', async () => {
    useAuthStore().user = { id: 1, username: 'daniel', avatar: null, is_admin: true }

    const wrapper = mount(RecipeCard, { props: { recipe } })

    await wrapper.find('button').trigger('click')

    expect(routerMocks.push).toHaveBeenCalledTimes(1)
    expect(routerMocks.push).toHaveBeenCalledWith({
      name: 'recipe-edit',
      params: { id: 12 },
    })
  })
})
