import { describe, expect, it } from 'vitest'

import { useRecipeDraft } from '@/composables/useRecipeDraft'
import type { IngredientReviewDraft } from '@/types/food'

describe('useRecipeDraft', () => {
  it('keeps a candidate resolved as new when a stale field update arrives', () => {
    const draft = useRecipeDraft()
    const pending: IngredientReviewDraft = {
      key: 'ingredient-7',
      sourceStatus: 'candidates',
      resolution: 'pending',
      ingredientId: null,
      name: 'Zahar vanilat',
      value: '1',
      unit: 'piece',
      rawText: '1 plic zahar vanilat',
      candidates: [
        {
          id: 12,
          name: 'Zahar',
          default_unit: 'piece',
        },
      ],
    }

    draft.reviewIngredients.value = [pending]
    draft.chooseNewIngredient(pending.key)
    draft.updateIngredient({
      ...pending,
      name: 'Zahar vanilat',
    })

    expect(draft.reviewIngredients.value[0]).toMatchObject({
      resolution: 'new',
      ingredientId: null,
      name: 'Zahar vanilat',
    })
  })

  it('keeps the canonical name and unit when a stale candidate update arrives', () => {
    const draft = useRecipeDraft()
    const pending: IngredientReviewDraft = {
      key: 'ingredient-8',
      sourceStatus: 'candidates',
      resolution: 'pending',
      ingredientId: null,
      name: 'Rosii',
      value: '3',
      unit: 'piece',
      rawText: '3 rosii',
      candidates: [],
    }

    draft.reviewIngredients.value = [pending]
    draft.chooseCandidate(pending.key, {
      id: 15,
      name: 'Roșii',
      default_unit: 'kilogram',
    })
    draft.updateIngredient({ ...pending, value: '0.5' })

    expect(draft.reviewIngredients.value[0]).toMatchObject({
      resolution: 'existing',
      ingredientId: 15,
      name: 'Roșii',
      value: '0.5',
      unit: 'kilogram',
    })
  })
})
