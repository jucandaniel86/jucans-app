import { describe, expect, it } from 'vitest'

import {
  buildRecipePayload,
  associateExistingIngredient,
  mapIngredientApiErrors,
  mapResolverResults,
  mapRecipeIngredients,
  selectExistingCandidate,
  selectNewCandidate,
  validateRecipeDraft,
} from '@/composables/recipeDraft'
import type {
  ExistingIngredient,
  IngredientResolverResult,
  IngredientReviewDraft,
  RecipeDraft,
} from '@/types/food'

const recipe: RecipeDraft = {
  name: 'Supă de test',
  description: '',
  url: '',
  tagIds: [2],
}

function resolverResult(
  overrides: Partial<IngredientResolverResult> = {},
): IngredientResolverResult {
  return {
    raw_text: '2 patrunjel',
    parsed: {
      value: 2,
      unit: null,
      ingredient_text: 'patrunjel',
      normalized_text: 'patrunjel',
    },
    status: 'unresolved',
    ingredient: null,
    unit: null,
    candidates: [],
    ...overrides,
  }
}

describe('recipe ingredient draft mapping', () => {
  it('maps recipe detail ingredients back into editable existing drafts', () => {
    const [draft] = mapRecipeIngredients([
      {
        id: 17,
        name: 'Pătrunjel',
        value: '1.500',
        unit: 'tablespoon',
        raw_text: 'o legătură și jumătate de pătrunjel',
      },
    ])

    expect(draft).toMatchObject({
      sourceStatus: 'matched',
      resolution: 'existing',
      ingredientId: 17,
      name: 'Pătrunjel',
      value: '1.5',
      unit: 'tablespoon',
      rawText: 'o legătură și jumătate de pătrunjel',
    })
  })

  it('removes fixed decimal padding from values shown in edit inputs', () => {
    const cases: Array<[string | null, string]> = [
      ['1.000', 'O bucată'],
      ['0.500', 'Jumătate'],
      ['50.000', 'Cincizeci'],
      ['1.250', 'Unu și un sfert'],
      [null, 'Fără cantitate'],
    ]
    const values = mapRecipeIngredients(
      cases.map(([value, name], index) => ({
        id: index + 1,
        name,
        value,
        unit: 'none',
        raw_text: null,
      })),
    ).map((ingredient) => ingredient.value)

    expect(values).toEqual(['1', '0.5', '50', '1.25', ''])
  })

  it('maps a matched ingredient with the resolver effective unit instead of the parsed unit', () => {
    const parsley: ExistingIngredient = {
      id: 17,
      name: 'Pătrunjel',
      default_unit: 'bunch',
    }

    const [draft] = mapResolverResults([
      resolverResult({
        status: 'matched',
        ingredient: parsley,
        unit: 'bunch',
        parsed: {
          value: 2,
          unit: 'tablespoon',
          ingredient_text: 'patrunjel',
          normalized_text: 'patrunjel',
        },
      }),
    ])

    expect(draft).toMatchObject({
      resolution: 'existing',
      ingredientId: 17,
      name: 'Pătrunjel',
      value: '2',
      unit: 'bunch',
      rawText: '2 patrunjel',
    })
  })

  it('keeps candidate results pending until the user chooses', () => {
    const pepper: ExistingIngredient = {
      id: 21,
      name: 'Ardei gras',
      default_unit: 'piece',
    }
    const pending = mapResolverResults([
      resolverResult({
        raw_text: '1 ardei',
        parsed: {
          value: 1,
          unit: 'tablespoon',
          ingredient_text: 'ardei',
          normalized_text: 'ardei',
        },
        unit: 'tablespoon',
        status: 'candidates',
        candidates: [pepper],
      }),
    ])[0]!

    expect(pending.resolution).toBe('pending')

    const selected = selectExistingCandidate(pending, pepper)
    expect(selected).toMatchObject({
      resolution: 'existing',
      ingredientId: 21,
      name: 'Ardei gras',
      unit: 'piece',
    })

    const newIngredient = selectNewCandidate(pending)
    expect(newIngredient).toMatchObject({ resolution: 'new', ingredientId: null, name: 'Ardei' })
  })

  it('maps unresolved ingredients to editable new ingredient drafts', () => {
    const [draft] = mapResolverResults([
      resolverResult({
        raw_text: '2 linguri gochujang',
        parsed: {
          value: 2,
          unit: 'tablespoon',
          ingredient_text: 'gochujang',
          normalized_text: 'gochujang',
        },
        unit: 'tablespoon',
      }),
    ])

    expect(draft).toMatchObject({
      resolution: 'new',
      name: 'Gochujang',
      value: '2',
      unit: 'tablespoon',
      rawText: '2 linguri gochujang',
    })
  })

  it('associates an unresolved draft without losing parsed or raw values', () => {
    const [draft] = mapResolverResults([
      resolverResult({
        raw_text: '2 linguri frunze de patrunjel',
        parsed: {
          value: 2,
          unit: 'tablespoon',
          ingredient_text: 'frunze de patrunjel',
          normalized_text: 'frunze de patrunjel',
        },
        unit: 'tablespoon',
      }),
    ])
    const associated = associateExistingIngredient(draft!, {
      id: 17,
      name: 'Pătrunjel',
      default_unit: 'bunch',
    })

    expect(associated).toMatchObject({
      sourceStatus: 'matched',
      resolution: 'existing',
      ingredientId: 17,
      name: 'Pătrunjel',
      value: '2',
      unit: 'bunch',
      rawText: '2 linguri frunze de patrunjel',
    })
  })
})

describe('recipe payload and validation', () => {
  it('builds mixed existing and new ingredient payloads while preserving raw text', () => {
    const ingredients: IngredientReviewDraft[] = [
      {
        key: 'existing',
        sourceStatus: 'matched',
        resolution: 'existing',
        ingredientId: 17,
        name: 'Pătrunjel',
        value: '1',
        unit: 'bunch',
        rawText: 'o legatura patrunjel',
        candidates: [],
      },
      {
        key: 'new',
        sourceStatus: 'unresolved',
        resolution: 'new',
        ingredientId: null,
        name: 'Gochujang',
        value: '2',
        unit: 'tablespoon',
        rawText: '2 linguri gochujang',
        candidates: [],
      },
      {
        key: 'salt',
        sourceStatus: 'unresolved',
        resolution: 'new',
        ingredientId: null,
        name: 'Sare',
        value: '',
        unit: 'none',
        rawText: 'sare dupa gust',
        candidates: [],
      },
    ]

    expect(buildRecipePayload(recipe, ingredients).ingredients).toEqual([
      {
        ingredient_id: 17,
        value: 1,
        unit: 'bunch',
        raw_text: 'o legatura patrunjel',
      },
      {
        name: 'Gochujang',
        default_unit: 'tablespoon',
        value: 2,
        unit: 'tablespoon',
        raw_text: '2 linguri gochujang',
      },
      {
        name: 'Sare',
        default_unit: null,
        value: null,
        unit: 'none',
        raw_text: 'sare dupa gust',
      },
    ])
  })

  it('blocks unresolved candidates and invalid quantities but permits null quantities', () => {
    const pending: IngredientReviewDraft = {
      key: 'pending',
      sourceStatus: 'candidates',
      resolution: 'pending',
      ingredientId: null,
      name: 'Ardei',
      value: 'mult',
      unit: 'piece',
      rawText: 'ardei',
      candidates: [],
    }
    const salt: IngredientReviewDraft = {
      key: 'salt',
      sourceStatus: 'unresolved',
      resolution: 'new',
      ingredientId: null,
      name: 'Sare',
      value: '',
      unit: 'none',
      rawText: 'sare',
      candidates: [],
    }

    const invalid = validateRecipeDraft(recipe, [pending], ['none', 'piece'])
    expect(invalid.valid).toBe(false)
    expect(invalid.ingredients.pending?.choice).toBeTruthy()
    expect(invalid.ingredients.pending?.value).toBeTruthy()

    const valid = validateRecipeDraft(recipe, [salt], ['none', 'piece'])
    expect(valid.valid).toBe(true)
    expect(buildRecipePayload(recipe, [salt]).ingredients[0]).toMatchObject({ value: null })
  })

  it('maps indexed API ingredient errors to the matching draft card', () => {
    const ingredients = Array.from({ length: 14 }, (_, index): IngredientReviewDraft => ({
      key: `ingredient-${index}`,
      sourceStatus: 'matched',
      resolution: 'existing',
      ingredientId: index + 1,
      name: `Ingredient ${index + 1}`,
      value: '',
      unit: 'none',
      rawText: '',
      candidates: [],
    }))

    expect(
      mapIngredientApiErrors(
        {
          'ingredients.13': ['Each ingredient may only appear once in a recipe.'],
        },
        ingredients,
      ),
    ).toEqual({
      'ingredient-13': {
        form: 'Ingredientul apare deja în rețetă. Șterge una dintre apariții.',
      },
    })
  })
})
