import type {
  ExistingIngredient,
  IngredientResolverResult,
  IngredientReviewDraft,
  RecipeIngredientDetail,
  RecipeCreatePayload,
  RecipeDraft,
} from '@/types/food'

export interface IngredientDraftError {
  choice?: string
  name?: string
  value?: string
  unit?: string
  form?: string
}

export interface RecipeDraftValidation {
  valid: boolean
  name: string
  url: string
  ingredients: Record<string, IngredientDraftError>
}

export function mapIngredientApiErrors(
  errors: Record<string, string[]>,
  ingredients: IngredientReviewDraft[],
): Record<string, IngredientDraftError> {
  const mapped: Record<string, IngredientDraftError> = {}

  for (const field of Object.keys(errors)) {
    const match = field.match(/^ingredients\.(\d+)(?:\.(ingredient_id|name|value|unit))?$/)
    const ingredient = match ? ingredients[Number(match[1])] : undefined

    if (!ingredient) continue

    const errorField = match?.[2]
    const message =
      errorField === 'name'
        ? 'Verifică numele ingredientului.'
        : errorField === 'value'
          ? 'Verifică valoarea ingredientului.'
          : errorField === 'unit'
            ? 'Verifică unitatea ingredientului.'
            : errorField === 'ingredient_id'
              ? 'Alege din nou ingredientul.'
              : 'Ingredientul apare deja în rețetă. Șterge una dintre apariții.'

    mapped[ingredient.key] = {
      ...mapped[ingredient.key],
      [errorField === 'ingredient_id' ? 'choice' : (errorField ?? 'form')]: message,
    }
  }

  return mapped
}

let draftSequence = 0

function draftKey(): string {
  draftSequence += 1
  return `ingredient-${draftSequence}`
}

function displayName(value: string): string {
  const trimmed = value.trim()

  return trimmed ? trimmed.charAt(0).toLocaleUpperCase('ro-RO') + trimmed.slice(1) : ''
}

function valueInput(value: number | null): string {
  return value === null ? '' : String(value)
}

function preferredUnit(result: IngredientResolverResult): string {
  return result.unit ?? 'none'
}

export function mapResolverResults(results: IngredientResolverResult[]): IngredientReviewDraft[] {
  return results.map((result) => {
    const matchedIngredient = result.status === 'matched' ? result.ingredient : null

    return {
      key: draftKey(),
      sourceStatus: result.status,
      resolution: matchedIngredient
        ? 'existing'
        : result.status === 'candidates'
          ? 'pending'
          : 'new',
      ingredientId: matchedIngredient?.id ?? null,
      name: matchedIngredient?.name ?? displayName(result.parsed.ingredient_text),
      value: valueInput(result.parsed.value),
      unit: preferredUnit(result),
      rawText: result.raw_text,
      candidates: result.candidates,
    }
  })
}

export function createManualIngredient(): IngredientReviewDraft {
  return {
    key: draftKey(),
    sourceStatus: 'manual',
    resolution: 'new',
    ingredientId: null,
    name: '',
    value: '',
    unit: 'none',
    rawText: '',
    candidates: [],
  }
}

export function mapRecipeIngredients(
  ingredients: RecipeIngredientDetail[],
): IngredientReviewDraft[] {
  return ingredients.map((ingredient) => ({
    key: draftKey(),
    sourceStatus: 'matched',
    resolution: 'existing',
    ingredientId: ingredient.id,
    name: ingredient.name,
    value: ingredient.value ?? '',
    unit: ingredient.default_unit ?? 'none',
    rawText: ingredient.raw_text ?? '',
    candidates: [],
  }))
}

export function selectExistingCandidate(
  draft: IngredientReviewDraft,
  candidate: ExistingIngredient,
): IngredientReviewDraft {
  return {
    ...draft,
    resolution: 'existing',
    ingredientId: candidate.id,
    name: candidate.name,
    unit: candidate.default_unit ?? 'none',
  }
}

export function selectNewCandidate(draft: IngredientReviewDraft): IngredientReviewDraft {
  return {
    ...draft,
    resolution: 'new',
    ingredientId: null,
  }
}

export function associateExistingIngredient(
  draft: IngredientReviewDraft,
  ingredient: ExistingIngredient,
): IngredientReviewDraft {
  return {
    ...draft,
    sourceStatus: 'matched',
    resolution: 'existing',
    ingredientId: ingredient.id,
    name: ingredient.name,
    unit: ingredient.default_unit ?? 'none',
  }
}

export function parseIngredientValue(value: string): number | null {
  const normalized = value.trim().replace(',', '.')

  return normalized === '' ? null : Number(normalized)
}

function isHttpUrl(value: string): boolean {
  if (!value.trim()) {
    return true
  }

  try {
    const url = new URL(value)
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

export function validateRecipeDraft(
  recipe: RecipeDraft,
  ingredients: IngredientReviewDraft[],
  validUnits: string[],
): RecipeDraftValidation {
  const errors: RecipeDraftValidation = {
    valid: true,
    name: '',
    url: '',
    ingredients: {},
  }

  if (!recipe.name.trim()) {
    errors.name = 'Numele rețetei este obligatoriu.'
  }

  if (!isHttpUrl(recipe.url)) {
    errors.url = 'Folosește un link HTTP sau HTTPS valid.'
  }

  for (const ingredient of ingredients) {
    const ingredientError: IngredientDraftError = {}
    const numericValue = parseIngredientValue(ingredient.value)

    if (ingredient.resolution === 'pending') {
      ingredientError.choice = 'Alege ingredientul potrivit sau marchează-l ca nou.'
    }

    if (ingredient.resolution === 'new' && !ingredient.name.trim()) {
      ingredientError.name = 'Numele ingredientului este obligatoriu.'
    }

    if (
      numericValue !== null &&
      (!Number.isFinite(numericValue) || numericValue < 0 || numericValue > 999999999.999)
    ) {
      ingredientError.value = 'Introdu o cantitate numerică validă.'
    }

    if (!validUnits.includes(ingredient.unit)) {
      ingredientError.unit = 'Alege o unitate validă.'
    }

    if (Object.keys(ingredientError).length > 0) {
      errors.ingredients[ingredient.key] = ingredientError
    }
  }

  errors.valid = !errors.name && !errors.url && Object.keys(errors.ingredients).length === 0
  return errors
}

export function buildRecipePayload(
  recipe: RecipeDraft,
  ingredients: IngredientReviewDraft[],
): RecipeCreatePayload {
  return {
    name: recipe.name.trim(),
    description: recipe.description.trim() || null,
    url: recipe.url.trim() || null,
    tags: recipe.tagIds,
    ingredients: ingredients.map((ingredient) => {
      if (ingredient.resolution === 'pending') {
        throw new Error('Candidate ingredient must be resolved before saving.')
      }

      const shared = {
        value: parseIngredientValue(ingredient.value),
        unit: ingredient.unit,
        raw_text: ingredient.rawText || null,
      }

      if (ingredient.resolution === 'existing' && ingredient.ingredientId !== null) {
        return {
          ingredient_id: ingredient.ingredientId,
          ...shared,
        }
      }

      return {
        name: ingredient.name.trim(),
        default_unit: ingredient.unit === 'none' ? null : ingredient.unit,
        ...shared,
      }
    }),
  }
}
