import type { ShoppingCategory } from '@/types/food'
import type { ShoppingItem, ShoppingListSummary, ShoppingListVisibility } from '@/types/shopping'

export function shoppingListName(
  list: Pick<ShoppingListSummary, 'name' | 'status' | 'closed_at'>,
): string {
  if (list.name !== null && list.name !== undefined) return list.name
  if (list.status !== 'closed') return 'Lista de cumpărături'
  const date = list.closed_at ? new Date(list.closed_at) : null
  return date && !Number.isNaN(date.getTime())
    ? new Intl.DateTimeFormat('ro-RO', { day: 'numeric', month: 'long', year: 'numeric' }).format(
        date,
      )
    : 'Listă închisă'
}

export function shoppingListSummary(
  list: Pick<ShoppingListSummary, 'items_count' | 'recipes_count'>,
): string {
  const { items_count: items, recipes_count: recipes } = list
  return `${items} ${items === 1 ? 'produs' : 'produse'} · ${recipes} ${recipes === 1 ? 'rețetă' : 'rețete'}`
}

export function shoppingListCreatorLabel(
  list: Pick<ShoppingListSummary, 'is_creator'> & Partial<Pick<ShoppingListSummary, 'creator'>>,
): string {
  if (list.is_creator) return 'Creată de tine'
  return `Creată de ${list.creator?.name ?? 'altcineva'}`
}

export function shoppingListOwnerLabel(
  list: Pick<ShoppingListSummary, 'is_creator'> & Partial<Pick<ShoppingListSummary, 'creator'>>,
): string {
  if (list.is_creator) return 'Lista ta'
  return shoppingListCreatorLabel(list)
}

export function shoppingListVisibilityLabel(
  list: Pick<ShoppingListSummary, 'visibility'> &
    Partial<Pick<ShoppingListSummary, 'is_shared_with_me' | 'creator'>>,
): string {
  const labels: Record<ShoppingListVisibility, string> = {
    private: 'Privată',
    shared: 'Partajată',
    public: 'Publică',
  }
  if (list.visibility !== 'shared') return labels[list.visibility]
  if (list.is_shared_with_me) return 'Partajată cu tine'
  return labels.shared
}

export function groupShoppingItems(items: ShoppingItem[]) {
  const groups = new Map<
    number | null,
    { category: ShoppingCategory | null; items: ShoppingItem[] }
  >()
  for (const item of items) {
    const key = item.shopping_category?.id ?? null
    if (!groups.has(key)) groups.set(key, { category: item.shopping_category, items: [] })
    groups.get(key)!.items.push(item)
  }

  return [...groups.values()]
    .sort((a, b) => {
      if (!a.category) return b.category ? 1 : 0
      if (!b.category) return -1
      return (
        a.category.sort_order - b.category.sort_order ||
        a.category.name.localeCompare(b.category.name, 'ro')
      )
    })
    .map((group) => ({
      ...group,
      items: group.items.sort((a, b) => a.name.localeCompare(b.name, 'ro')),
    }))
}
