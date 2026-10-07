import { ref } from 'vue'

import { ApiError } from '@/services/api'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'

export function useAddRecipeToShoppingList(recipeId: () => number | null) {
  const shopping = useActiveShoppingListStore()
  const notifications = useNotificationStore()
  const adding = ref(false)

  async function addToList(): Promise<void> {
    const id = recipeId()
    if (id === null || adding.value) return
    adding.value = true

    try {
      const response = await shopping.addRecipe(id)
      if (response.data.already_present) notifications.info('Rețeta este deja în listă')
      else notifications.success('Adăugat în lista de cumpărături')
    } catch (error) {
      if (
        error instanceof ApiError &&
        error.status === 422 &&
        Object.keys(error.errors).some((key) => key.startsWith('recipe_ingredients.'))
      ) {
        const reviewNames = [
          ...new Set(
            Object.values(error.errors)
              .flat()
              .flatMap((message) => {
                const name = message.match(/^Ingredient \d+ \((.+)\)/)?.[1]
                return name ? [name] : []
              }),
          ),
        ]
        notifications.warning(
          [
            'Rețeta nu poate fi adăugată încă. Unele ingrediente necesită verificare.',
            reviewNames.join(' · '),
          ]
            .filter(Boolean)
            .join(' '),
        )
      } else {
        notifications.error(
          actionErrorMessage(error, 'Nu am putut adăuga rețeta. Încearcă din nou.'),
        )
      }
    } finally {
      adding.value = false
    }
  }

  return { adding, addToList }
}
