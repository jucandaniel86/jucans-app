// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import router from '@/router'

describe('shopping list route modes', () => {
  it.each([
    ['home', 'floating'],
    ['recipes', 'header'],
    ['recipe-random', 'header'],
    ['recipe-detail', 'header'],
    ['recipe-create', 'header'],
    ['recipe-edit', 'header'],
    ['diets-lists', 'header'],
    ['diet-edit', 'header'],
    ['shopping-lists', 'header'],
    ['shopping-list', 'hidden'],
    ['shopping-list-detail', 'hidden'],
    ['admin-ingredients', 'hidden'],
    ['admin-recipe-reviews', 'hidden'],
    ['login', 'hidden'],
  ])('%s resolves to %s', (name, mode) => {
    const route = router.resolve({ name, params: { id: '1' } })
    expect(route.meta.shoppingList).toBe(mode)
  })
})
