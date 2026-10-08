import { DietStatus, type Diet, type DietSource } from '@/types/diets'
import { reactive } from 'vue'

export function useDiet() {
  const diet = reactive<Diet>({
    id: 0,
    name: '',
    description: null,
    notes: null,
    thumbnail: null,
    status: DietStatus.DRAFT,
    created_at: null,
    updated_at: null,
    sources: [],
    daily_structure: [],
  })

  const activeTabs = [
    { name: 'daily-structure', label: 'Structură zilnică' },
    { name: 'ingredients', label: 'Ingrediente' },
    { name: 'sources', label: 'Surse' },
  ]
  const defaultTab = 'daily-structure'

  function hydrateDiet(existingDiet: Diet): void {
    Object.assign(diet, {
      id: existingDiet.id,
      name: existingDiet.name,
      description: existingDiet.description ?? '',
      notes: existingDiet.notes ?? '',
      thumbnail: existingDiet.thumbnail ?? '',
      status: existingDiet.status,
      created_at: existingDiet.created_at,
      updated_at: existingDiet.updated_at,
      sources: existingDiet.sources,
      daily_structure: existingDiet.daily_structure,
    })
  }

  return {
    diet,
    defaultTab,
    activeTabs,
    hydrateDiet,
  }
}
