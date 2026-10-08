import { DietSourceType } from '@/types/diets'

export const dietSourceTypes: Record<DietSourceType, { icon: string; label: string }> = {
  [DietSourceType.WEBSITE]: {
    icon: '🌐',
    label: 'Website',
  },

  [DietSourceType.YOUTUBE]: {
    icon: '▶️',
    label: 'YouTube',
  },

  [DietSourceType.ARTICLE]: {
    icon: '📄',
    label: 'Articol',
  },

  [DietSourceType.BOOK]: {
    icon: '📚',
    label: 'Carte',
  },

  [DietSourceType.OTHER]: {
    icon: '📎',
    label: 'Altele',
  },
}
