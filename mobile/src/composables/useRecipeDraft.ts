import { computed, onScopeDispose, reactive, ref } from 'vue'

import {
  buildRecipePayload,
  associateExistingIngredient,
  createManualIngredient,
  mapRecipeIngredients,
  mapResolverResults,
  mapIngredientApiErrors,
  selectExistingCandidate,
  selectNewCandidate,
  validateRecipeDraft,
  type IngredientDraftError,
} from '@/composables/recipeDraft'
import { ApiError } from '@/services/api'
import { foodApi } from '@/services/foodApi'
import type {
  CreatedRecipe,
  ExistingIngredient,
  FoodTag,
  FoodTagPayload,
  FoodUnit,
  IngredientReviewDraft,
  RecipeDetail,
  RecipeDraft,
} from '@/types/food'

function friendlyRequestError(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.status === 0) {
    return error.message
  }

  if (error instanceof ApiError && error.status === 422) {
    return 'Verifică informațiile introduse și încearcă din nou.'
  }

  return fallback
}

export function useRecipeDraft() {
  const recipe = reactive<RecipeDraft>({
    name: '',
    description: '',
    url: '',
    tagIds: [],
  })
  const imageFile = ref<File | null>(null)
  const imagePreviewUrl = ref<string | null>(null)
  const existingImageUrl = ref<string | null>(null)
  const imageRemovalRequested = ref(false)
  const rawIngredients = ref('')
  const reviewIngredients = ref<IngredientReviewDraft[]>([])
  const tags = ref<FoodTag[]>([])
  const units = ref<Record<string, FoodUnit>>({})
  const loadingOptions = ref(true)
  const processingIngredients = ref(false)
  const creatingTag = ref(false)
  const saving = ref(false)
  const optionsError = ref('')
  const resolverError = ref('')
  const tagError = ref('')
  const saveError = ref('')
  const nameError = ref('')
  const urlError = ref('')
  const imageError = ref('')
  const ingredientErrors = ref<Record<string, IngredientDraftError>>({})
  const associationErrors = ref<Record<string, string>>({})
  const associatingKey = ref<string | null>(null)
  const savedRecipe = ref<CreatedRecipe | null>(null)
  let localImageUrl: string | null = null

  function revokeImagePreview(): void {
    if (localImageUrl) URL.revokeObjectURL(localImageUrl)
    localImageUrl = null
  }

  function selectImage(file: File): void {
    const supportedTypes = ['image/jpeg', 'image/png', 'image/webp']

    if (!supportedTypes.includes(file.type)) {
      imageError.value = 'Alege o imagine JPG, PNG sau WebP.'
      return
    }

    if (file.size > 8 * 1024 * 1024) {
      imageError.value = 'Imaginea poate avea cel mult 8 MB.'
      return
    }

    revokeImagePreview()
    imageFile.value = file
    localImageUrl = URL.createObjectURL(file)
    imagePreviewUrl.value = localImageUrl
    imageRemovalRequested.value = false
    imageError.value = ''
  }

  function removeImage(): void {
    revokeImagePreview()
    imageFile.value = null
    imagePreviewUrl.value = null
    imageRemovalRequested.value = existingImageUrl.value !== null
    imageError.value = ''
  }

  function hydrateRecipe(existingRecipe: RecipeDetail): void {
    revokeImagePreview()
    Object.assign(recipe, {
      name: existingRecipe.name,
      description: existingRecipe.description ?? '',
      url: existingRecipe.url ?? '',
      tagIds: existingRecipe.tags.map((tag) => tag.id),
    })
    reviewIngredients.value = mapRecipeIngredients(existingRecipe.ingredients)
    imageFile.value = null
    existingImageUrl.value = existingRecipe.image_url
    imagePreviewUrl.value = existingRecipe.image_url
    imageRemovalRequested.value = false
    imageError.value = ''
    ingredientErrors.value = {}
    associationErrors.value = {}
    saveError.value = ''
  }

  onScopeDispose(revokeImagePreview)

  const unitOptions = computed(() =>
    Object.entries(units.value).map(([key, unit]) => ({
      value: key,
      label: unit.label ? `${unit.name} (${unit.label})` : unit.name,
    })),
  )
  const hasImage = computed(() => imagePreviewUrl.value !== null)

  async function loadOptions(): Promise<void> {
    loadingOptions.value = true
    optionsError.value = ''

    try {
      const [config, tagResponse] = await Promise.all([foodApi.getConfig(), foodApi.listTags()])
      units.value = config.units
      tags.value = tagResponse.data
    } catch (error) {
      optionsError.value = friendlyRequestError(
        error,
        'Nu am putut încărca tag-urile și unitățile. Încearcă din nou.',
      )
    } finally {
      loadingOptions.value = false
    }
  }

  async function processIngredients(): Promise<void> {
    const lines = rawIngredients.value
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean)

    resolverError.value = ''

    if (lines.length === 0) {
      resolverError.value = 'Lipește sau scrie cel puțin un ingredient.'
      return
    }

    processingIngredients.value = true

    try {
      const response = await foodApi.resolveIngredients(lines)
      reviewIngredients.value = mapResolverResults(response.data)
      ingredientErrors.value = {}
      associationErrors.value = {}
    } catch (error) {
      resolverError.value = friendlyRequestError(
        error,
        'Nu am putut procesa ingredientele. Textul tău a rămas neschimbat.',
      )
    } finally {
      processingIngredients.value = false
    }
  }

  async function createTag(payload: FoodTagPayload): Promise<boolean> {
    creatingTag.value = true
    tagError.value = ''

    try {
      const response = await foodApi.createTag({
        name: payload.name.trim(),
        emoji: payload.emoji,
      })
      const existingIndex = tags.value.findIndex((tag) => tag.id === response.data.id)

      if (existingIndex === -1) {
        tags.value = [...tags.value, response.data].sort((left, right) =>
          left.name.localeCompare(right.name, 'ro-RO'),
        )
      }

      if (!recipe.tagIds.includes(response.data.id)) {
        recipe.tagIds.push(response.data.id)
      }

      return true
    } catch (error) {
      tagError.value = friendlyRequestError(error, 'Nu am putut salva tag-ul.')
      return false
    } finally {
      creatingTag.value = false
    }
  }

  function toggleTag(tagId: number): void {
    const index = recipe.tagIds.indexOf(tagId)

    if (index === -1) {
      recipe.tagIds.push(tagId)
    } else {
      recipe.tagIds.splice(index, 1)
    }
  }

  function updateIngredient(updated: IngredientReviewDraft): void {
    const index = reviewIngredients.value.findIndex((ingredient) => ingredient.key === updated.key)

    if (index === -1) return

    const current = reviewIngredients.value[index]
    if (!current) return

    reviewIngredients.value[index] = {
      ...current,
      ...updated,
      resolution:
        current.resolution !== 'pending' && updated.resolution === 'pending'
          ? current.resolution
          : updated.resolution,
      ingredientId:
        current.resolution !== 'pending' && updated.resolution === 'pending'
          ? current.ingredientId
          : updated.ingredientId,
      name:
        current.resolution === 'existing' && updated.resolution === 'pending'
          ? current.name
          : updated.name,
      unit:
        current.resolution === 'existing' && updated.resolution === 'pending'
          ? current.unit
          : updated.unit,
    }
    delete ingredientErrors.value[updated.key]
    delete associationErrors.value[updated.key]
  }

  function chooseCandidate(key: string, candidate: ExistingIngredient): void {
    const draft = reviewIngredients.value.find((ingredient) => ingredient.key === key)
    if (draft) updateIngredient(selectExistingCandidate(draft, candidate))
  }

  function chooseNewIngredient(key: string): void {
    const draft = reviewIngredients.value.find((ingredient) => ingredient.key === key)
    if (draft) updateIngredient(selectNewCandidate(draft))
  }

  async function associateIngredient(key: string, ingredient: ExistingIngredient): Promise<void> {
    const index = reviewIngredients.value.findIndex((item) => item.key === key)
    const draft = reviewIngredients.value[index]

    if (!draft || associatingKey.value) return

    associatingKey.value = key
    delete associationErrors.value[key]

    try {
      const response = await foodApi.associateIngredient(draft.name.trim(), ingredient.id)
      reviewIngredients.value[index] = associateExistingIngredient(draft, response.data.ingredient)
      delete ingredientErrors.value[key]
    } catch {
      associationErrors.value[key] =
        'Nu am putut salva asocierea. Verifică ingredientul și încearcă din nou.'
    } finally {
      associatingKey.value = null
    }
  }

  function addIngredient(): void {
    reviewIngredients.value.push(createManualIngredient())
  }

  function removeIngredient(key: string): void {
    reviewIngredients.value = reviewIngredients.value.filter((ingredient) => ingredient.key !== key)
    delete ingredientErrors.value[key]
    delete associationErrors.value[key]
  }

  async function save(recipeId: number | null = null): Promise<boolean> {
    saveError.value = ''
    const validation = validateRecipeDraft(
      recipe,
      reviewIngredients.value,
      Object.keys(units.value),
    )

    nameError.value = validation.name
    urlError.value = validation.url
    ingredientErrors.value = validation.ingredients

    if (!validation.valid || imageError.value) {
      saveError.value = 'Mai sunt câteva lucruri de verificat înainte de salvare.'
      return false
    }

    saving.value = true

    try {
      const payload = buildRecipePayload(recipe, reviewIngredients.value)
      const response = recipeId
        ? await foodApi.updateRecipe(recipeId, payload, {
            image: imageFile.value,
            removeImage: imageRemovalRequested.value,
          })
        : await foodApi.createRecipe(payload, imageFile.value)
      savedRecipe.value = response.data
      return true
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        const apiIngredientErrors = mapIngredientApiErrors(error.errors, reviewIngredients.value)

        if (Object.keys(apiIngredientErrors).length > 0) {
          ingredientErrors.value = {
            ...ingredientErrors.value,
            ...apiIngredientErrors,
          }
          saveError.value = 'Verifică ingredientele marcate cu roșu.'
          return false
        }
      }

      saveError.value = friendlyRequestError(
        error,
        'Nu am putut salva rețeta. Toate informațiile tale sunt încă aici.',
      )
      return false
    } finally {
      saving.value = false
    }
  }

  function startAnotherRecipe(): void {
    Object.assign(recipe, {
      name: '',
      description: '',
      url: '',
      tagIds: [],
    })
    revokeImagePreview()
    imageFile.value = null
    imagePreviewUrl.value = null
    existingImageUrl.value = null
    imageRemovalRequested.value = false
    rawIngredients.value = ''
    reviewIngredients.value = []
    savedRecipe.value = null
    saveError.value = ''
    nameError.value = ''
    urlError.value = ''
    imageError.value = ''
    ingredientErrors.value = {}
    associationErrors.value = {}
    associatingKey.value = null
  }

  return {
    recipe,
    imageFile,
    imagePreviewUrl,
    hasImage,
    imageRemovalRequested,
    rawIngredients,
    reviewIngredients,
    tags,
    units,
    unitOptions,
    loadingOptions,
    processingIngredients,
    creatingTag,
    saving,
    optionsError,
    resolverError,
    tagError,
    saveError,
    nameError,
    urlError,
    imageError,
    ingredientErrors,
    associationErrors,
    associatingKey,
    savedRecipe,
    loadOptions,
    processIngredients,
    createTag,
    toggleTag,
    updateIngredient,
    chooseCandidate,
    chooseNewIngredient,
    associateIngredient,
    addIngredient,
    removeIngredient,
    save,
    startAnotherRecipe,
    selectImage,
    removeImage,
    hydrateRecipe,
  }
}
