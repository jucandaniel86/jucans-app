export type MenuRouteName =
  | 'home'
  | 'recipes'
  | 'recipe-create'
  | 'shopping-list'
  | 'shopping-lists'
  | 'admin-ingredients'
  | 'admin-recipe-reviews'

export type MenuMarkerClass =
  'drawer-link__mark--turquoise' | 'drawer-link__mark--pink' | 'drawer-link__mark--yellow'

export interface MenuItem {
  id: string
  label: string
  routeName: MenuRouteName | null
  markerClass: MenuMarkerClass
  restricted: boolean
  soon: boolean
}

export interface MenuSection {
  id: string
  label: string | null
  dividerBefore: boolean
  items: MenuItem[]
}

export const MENU_SECTIONS: MenuSection[] = [
  {
    id: 'main',
    label: null,
    dividerBefore: false,
    items: [
      {
        id: 'home',
        label: 'Acasa',
        routeName: 'home',
        markerClass: 'drawer-link__mark--turquoise',
        restricted: false,
        soon: false,
      },
    ],
  },
  {
    id: 'food',
    label: 'Mâncare',
    dividerBefore: false,
    items: [
      {
        id: 'recipes',
        label: 'Rețete',
        routeName: 'recipes',
        markerClass: 'drawer-link__mark--pink',
        restricted: false,
        soon: false,
      },
      {
        id: 'recipe-create',
        label: 'Adaugă rețetă',
        routeName: 'recipe-create',
        markerClass: 'drawer-link__mark--yellow',
        restricted: false,
        soon: false,
      },
    ],
  },
  {
    id: 'shopping',
    label: null,
    dividerBefore: false,
    items: [
      {
        id: 'shopping',
        label: 'Liste de cumpărături',
        routeName: 'shopping-lists',
        markerClass: 'drawer-link__mark--turquoise',
        restricted: false,
        soon: false,
      },
    ],
  },
  {
    id: 'planning',
    label: null,
    dividerBefore: true,
    items: [
      {
        id: 'schedule',
        label: 'Program',
        routeName: null,
        markerClass: 'drawer-link__mark--yellow',
        restricted: false,
        soon: true,
      },
      {
        id: 'tasks',
        label: 'Taskuri',
        routeName: null,
        markerClass: 'drawer-link__mark--pink',
        restricted: false,
        soon: true,
      },
    ],
  },
  {
    id: 'administration',
    label: 'Administration',
    dividerBefore: true,
    items: [
      {
        id: 'admin-ingredients',
        label: 'Ingrediente',
        routeName: 'admin-ingredients',
        markerClass: 'drawer-link__mark--turquoise',
        restricted: true,
        soon: false,
      },
      {
        id: 'admin-recipe-reviews',
        label: 'Verificare rețete',
        routeName: 'admin-recipe-reviews',
        markerClass: 'drawer-link__mark--yellow',
        restricted: true,
        soon: false,
      },
    ],
  },
]
