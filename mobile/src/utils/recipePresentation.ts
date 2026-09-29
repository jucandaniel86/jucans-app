import type { FoodUnit, RecipeIngredientDetail } from '@/types/food'

export function recipeSourceLabel(url: string): string {
  try {
    const hostname = new URL(url).hostname.replace(/^www\./, '')

    if (hostname === 'youtu.be' || hostname.endsWith('youtube.com')) return '▶ YouTube'
    if (hostname.endsWith('facebook.com')) return 'Facebook'
  } catch {
    // The API validates URLs; retain a safe label for legacy data.
  }

  return '🌐 Rețeta originală'
}

export function recipeIngredientLabel(
  ingredient: RecipeIngredientDetail,
  units: Record<string, FoodUnit>,
): string {
  if (ingredient.value === null) return ingredient.raw_text || ingredient.name

  const numericValue = Number(ingredient.value)
  const value = Number.isFinite(numericValue)
    ? new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 3 }).format(numericValue)
    : ingredient.value
  const unit = ingredient.unit ? units[ingredient.unit] : undefined
  const unitLabel = unit?.label || (ingredient.unit === 'none' ? '' : unit?.name || '')

  return [value, unitLabel, ingredient.name].filter(Boolean).join(' ')
}
