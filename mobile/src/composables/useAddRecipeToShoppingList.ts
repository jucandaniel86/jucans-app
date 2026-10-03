import { ref } from 'vue'

import { ApiError } from '@/services/api'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'

export function useAddRecipeToShoppingList(recipeId: () => number | null) {
  const shopping = useActiveShoppingListStore()
  const adding = ref(false)
  const shoppingMessage = ref('')
  const shoppingError = ref('')
  const reviewNames = ref<string[]>([])

  async function addToList(): Promise<void> {
    const id = recipeId()
    if (id === null || adding.value) return
    adding.value = true
    shoppingMessage.value = ''
    shoppingError.value = ''
    reviewNames.value = []

    try {
      const response = await shopping.addRecipe(id)
      shoppingMessage.value = response.data.already_present
        ? 'Rețeta este deja în listă'
        : 'Adăugat în lista de cumpărături'
    } catch (error) {
      if (
        error instanceof ApiError &&
        error.status === 422 &&
        Object.keys(error.errors).some((key) => key.startsWith('recipe_ingredients.'))
      ) {
        shoppingError.value =
          'Rețeta nu poate fi adăugată încă. Unele ingrediente necesită verificare.'
        reviewNames.value = [
          ...new Set(
            Object.values(error.errors)
              .flat()
              .flatMap((message) => {
                const name = message.match(/^Ingredient \d+ \((.+)\)/)?.[1]
                return name ? [name] : []
              }),
          ),
        ]
      } else {
        shoppingError.value =
          error instanceof ApiError ? error.message : 'Nu am putut adăuga rețeta. Încearcă din nou.'
      }
    } finally {
      adding.value = false
    }
  }

  return { adding, shoppingMessage, shoppingError, reviewNames, addToList }
}
