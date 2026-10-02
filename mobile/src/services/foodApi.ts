import { api } from '@/services/api'
import type {
  AdminIngredient,
  AdminIngredientFilters,
  AdminIngredientListResponse,
  AdminIngredientPayload,
  FoodConfig,
  FoodTag,
  FoodTagPayload,
  IngredientResolverResponse,
  IngredientAliasResponse,
  IngredientMergePreview,
  IngredientMergeResponse,
  ExistingIngredient,
  RecipeCreatePayload,
  RecipeCreateResponse,
  RecipeDetailResponse,
  RecipeFilters,
  RecipeIngredientReviewFilters,
  RecipeIngredientReviewListResponse,
  RecipeListResponse,
  RandomRecipeResponse,
  ResourceCollection,
  ResourceItem,
  ShoppingCategory,
} from '@/types/food'

function recipeFormData(payload: RecipeCreatePayload): FormData {
  const formData = new FormData()

  formData.append('name', payload.name)
  formData.append('description', payload.description ?? '')
  formData.append('url', payload.url ?? '')
  formData.append('tags_present', '1')
  formData.append('ingredients_present', '1')
  payload.tags.forEach((tagId, index) => formData.append(`tags[${index}]`, String(tagId)))
  payload.ingredients.forEach((ingredient, index) => {
    Object.entries(ingredient).forEach(([field, value]) => {
      formData.append(`ingredients[${index}][${field}]`, value === null ? '' : String(value))
    })
  })

  return formData
}

function recipeListQuery(filters: RecipeFilters): string {
  const parameters = new URLSearchParams()

  if (filters.search?.trim()) parameters.set('search', filters.search.trim())
  if (filters.tags?.length) parameters.set('tags', filters.tags.join(','))
  if (filters.sort) parameters.set('sort', filters.sort)
  if (filters.page) parameters.set('page', String(filters.page))
  if (filters.perPage) parameters.set('per_page', String(filters.perPage))
  if (filters.random) parameters.set('random', 'true')
  if (filters.exclude) parameters.set('exclude', String(filters.exclude))

  const query = parameters.toString()
  return query ? `?${query}` : ''
}

function adminIngredientListQuery(filters: AdminIngredientFilters): string {
  const parameters = new URLSearchParams()

  if (filters.search?.trim()) parameters.set('search', filters.search.trim())
  if (filters.page) parameters.set('page', String(filters.page))
  if (filters.perPage) parameters.set('per_page', String(filters.perPage))
  if (filters.missingUnit) parameters.set('missing_unit', '1')
  if (filters.missingCategory) parameters.set('missing_category', '1')
  if (filters.isShoppable !== undefined) {
    parameters.set('is_shoppable', filters.isShoppable ? '1' : '0')
  }

  const query = parameters.toString()
  return query ? `?${query}` : ''
}

function recipeIngredientReviewListQuery(filters: RecipeIngredientReviewFilters): string {
  const parameters = new URLSearchParams()

  if (filters.search?.trim()) parameters.set('search', filters.search.trim())
  if (filters.page) parameters.set('page', String(filters.page))
  if (filters.perPage) parameters.set('per_page', String(filters.perPage))

  const query = parameters.toString()
  return query ? `?${query}` : ''
}

export const foodApi = {
  listRecipes(filters: RecipeFilters = {}): Promise<RecipeListResponse> {
    return api.get<RecipeListResponse>(`/recipes${recipeListQuery(filters)}`)
  },

  randomRecipe(tags: number[] = [], exclude?: number): Promise<RandomRecipeResponse> {
    return api.get<RandomRecipeResponse>(
      `/recipes${recipeListQuery({ tags, random: true, exclude })}`,
    )
  },

  getRecipe(recipeId: number): Promise<RecipeDetailResponse> {
    return api.get<RecipeDetailResponse>(`/recipes/${recipeId}`)
  },

  getConfig(): Promise<FoodConfig> {
    return api.get<FoodConfig>('/config/food')
  },

  listTags(): Promise<ResourceCollection<FoodTag>> {
    return api.get<ResourceCollection<FoodTag>>('/food-tags')
  },

  createTag(payload: FoodTagPayload): Promise<ResourceItem<FoodTag>> {
    return api.post<ResourceItem<FoodTag>>('/food-tags', payload)
  },

  resolveIngredients(ingredients: string[]): Promise<IngredientResolverResponse> {
    return api.post<IngredientResolverResponse>('/ingredients/resolve', { ingredients })
  },

  searchIngredients(query: string): Promise<ResourceCollection<ExistingIngredient>> {
    return api.get<ResourceCollection<ExistingIngredient>>(
      `/ingredients/search?query=${encodeURIComponent(query)}`,
    )
  },

  associateIngredient(alias: string, ingredientId: number): Promise<IngredientAliasResponse> {
    return api.post<IngredientAliasResponse>('/ingredients/aliases', {
      alias,
      ingredient_id: ingredientId,
    })
  },

  createRecipe(payload: RecipeCreatePayload, image?: File | null): Promise<RecipeCreateResponse> {
    const formData = recipeFormData(payload)

    if (image) formData.append('image', image)

    return api.post<RecipeCreateResponse>('/recipes', formData)
  },

  updateRecipe(
    recipeId: number,
    payload: RecipeCreatePayload,
    options: { image?: File | null; removeImage?: boolean } = {},
  ): Promise<RecipeDetailResponse> {
    const formData = recipeFormData(payload)
    formData.append('_method', 'PATCH')

    if (options.image) formData.append('image', options.image)
    if (options.removeImage) formData.append('remove_image', '1')

    return api.post<RecipeDetailResponse>(`/recipes/${recipeId}`, formData)
  },

  listAdminIngredients(filters: AdminIngredientFilters = {}): Promise<AdminIngredientListResponse> {
    return api.get<AdminIngredientListResponse>(
      `/admin/ingredients${adminIngredientListQuery(filters)}`,
    )
  },

  updateAdminIngredient(
    ingredientId: number,
    payload: AdminIngredientPayload,
  ): Promise<ResourceItem<AdminIngredient>> {
    return api.patch<ResourceItem<AdminIngredient>>(`/admin/ingredients/${ingredientId}`, payload)
  },

  previewIngredientMerge(
    sourceId: number,
    targetIngredientId: number,
  ): Promise<IngredientMergePreview> {
    return api.post<IngredientMergePreview>(`/admin/ingredients/${sourceId}/merge-preview`, {
      target_ingredient_id: targetIngredientId,
    })
  },

  mergeIngredients(sourceId: number, targetIngredientId: number): Promise<IngredientMergeResponse> {
    return api.post<IngredientMergeResponse>(`/admin/ingredients/${sourceId}/merge`, {
      target_ingredient_id: targetIngredientId,
    })
  },

  listShoppingCategories(): Promise<ResourceCollection<ShoppingCategory>> {
    return api.get<ResourceCollection<ShoppingCategory>>('/admin/shopping-categories')
  },

  listRecipeIngredientReviews(
    filters: RecipeIngredientReviewFilters = {},
  ): Promise<RecipeIngredientReviewListResponse> {
    return api.get<RecipeIngredientReviewListResponse>(
      `/admin/recipe-ingredient-reviews${recipeIngredientReviewListQuery(filters)}`,
    )
  },
}
