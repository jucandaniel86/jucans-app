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
  if (ingredient.unit === 'to_taste')
    return `${ingredient.name} ${quantityLabel(null, ingredient.unit, units)}`
  if (ingredient.value === null) return ingredient.raw_text || ingredient.name

  return [quantityLabel(ingredient.value, ingredient.unit, units), ingredient.name]
    .filter(Boolean)
    .join(' ')
}

export function quantityLabel(
  quantity: string | null,
  storedUnit: string | null,
  units: Record<string, FoodUnit>,
): string {
  if (storedUnit === 'to_taste') return units.to_taste?.label || 'după gust'
  if (quantity === null) return ''

  const numericValue = Number(quantity)
  const value = Number.isFinite(numericValue)
    ? new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 3 }).format(numericValue)
    : quantity
  const unit = storedUnit ? units[storedUnit] : undefined
  const unitLabel = unit?.label || (storedUnit === 'none' ? '' : unit?.name || storedUnit || '')

  return [value, unitLabel].filter(Boolean).join(' ')
}
