import { DietIngredientStatus } from '@/types/diets'

export const dietIngredientStatus: Record<DietIngredientStatus, { value: string; label: string }> =
  {
    [DietIngredientStatus.ALLOWED]: {
      value: DietIngredientStatus.ALLOWED,
      label: 'Permis',
    },
    [DietIngredientStatus.CONDITIONAL]: {
      value: DietIngredientStatus.ALLOWED,
      label: 'Conditional',
    },
    [DietIngredientStatus.EXCLUDED]: {
      value: DietIngredientStatus.ALLOWED,
      label: 'Exclus',
    },
    [DietIngredientStatus.PREFERRED]: {
      value: DietIngredientStatus.ALLOWED,
      label: 'Preferat',
    },
    [DietIngredientStatus.REQUIRED]: {
      value: DietIngredientStatus.ALLOWED,
      label: 'Necesar',
    },
  }
