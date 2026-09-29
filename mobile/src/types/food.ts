export interface FoodTag {
  id: number
  name: string
  normalized_name: string
  emoji: string | null
  recipe_count?: number
}

export interface FoodTagPayload {
  name: string
  emoji: string | null
}

export interface FoodUnit {
  name: string
  label: string
  aliases: string[]
}

export interface FoodConfig {
  units: Record<string, FoodUnit>
}

export interface ExistingIngredient {
  id: number
  name: string
  default_unit: string | null
}

export interface IngredientAliasResponse {
  data: {
    id: number
    alias: string
    normalized_alias: string
    ingredient: ExistingIngredient
  }
}

export type ResolverStatus = 'matched' | 'candidates' | 'unresolved'

export interface ResolverParsedIngredient {
  value: number | null
  unit: string | null
  ingredient_text: string
  normalized_text: string
}

export interface IngredientResolverResult {
  raw_text: string
  parsed: ResolverParsedIngredient
  status: ResolverStatus
  ingredient: ExistingIngredient | null
  candidates: ExistingIngredient[]
}

export interface IngredientResolverResponse {
  data: IngredientResolverResult[]
}

export type IngredientResolution = 'existing' | 'new' | 'pending'

export interface IngredientReviewDraft {
  key: string
  sourceStatus: ResolverStatus | 'manual'
  resolution: IngredientResolution
  ingredientId: number | null
  name: string
  value: string
  unit: string
  rawText: string
  candidates: ExistingIngredient[]
}

export interface RecipeDraft {
  name: string
  description: string
  url: string
  tagIds: number[]
}

export interface ExistingRecipeIngredientPayload {
  ingredient_id: number
  value: number | null
  unit: string
  raw_text: string | null
}

export interface NewRecipeIngredientPayload {
  name: string
  default_unit: string | null
  value: number | null
  unit: string
  raw_text: string | null
}

export type RecipeIngredientPayload = ExistingRecipeIngredientPayload | NewRecipeIngredientPayload

export interface RecipeCreatePayload {
  name: string
  description: string | null
  url: string | null
  tags: number[]
  ingredients: RecipeIngredientPayload[]
}

export interface CreatedRecipe {
  id: number
  name: string
}

export interface RecipeCreateResponse {
  data: CreatedRecipe
}

export interface RecipeIngredientDetail {
  id: number
  name: string
  value: string | null
  unit: string | null
  raw_text: string | null
}

export interface RecipeCreator {
  id: number
  username: string
  avatar: string | null
}

export interface RecipeSummary {
  id: number
  name: string
  description: string | null
  image: string | null
  image_url: string | null
  url: string | null
  creator: RecipeCreator
  tags: FoodTag[]
  created_at: string | null
  updated_at: string | null
}

export interface RecipeDetail extends RecipeSummary {
  ingredients: RecipeIngredientDetail[]
}

export interface RecipeFilters {
  search?: string
  tags?: number[]
  sort?: 'newest' | 'name'
  page?: number
  perPage?: number
  random?: boolean
  exclude?: number
}

export interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  links: PaginationLink[]
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface RecipeListResponse {
  data: RecipeSummary[]
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: PaginationMeta
}

export interface RecipeDetailResponse {
  data: RecipeDetail
}

export interface RandomRecipeResponse {
  data: RecipeSummary | null
}

export interface ResourceCollection<T> {
  data: T[]
}

export interface ResourceItem<T> {
  data: T
}
