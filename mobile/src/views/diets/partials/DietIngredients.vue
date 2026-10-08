<script setup lang="ts">
import { ref } from 'vue'

import type { AdminIngredient } from '@/types/food'
import { foodApi } from '@/services/foodApi'
import { dietsApi } from '@/services/dietsApi'
import { useNotificationStore } from '@/stores/notifications'
import { type DietIngredient } from '@/types/diets'
//components
import PlusIcon from '@/components/icons/plusIcon.vue'
import IngredientCard from '../components/IngredientCard.vue'
import AppAutocomplete from '@/components/app/AppAutocomplete.vue'

//props
const props = defineProps<{
  dietId: number
  ingredients: DietIngredient[]
}>()

//emits
const emit = defineEmits<{
  reloadList: [items: DietIngredient[]]
}>()

//models
const savingIds = ref<number>(0)

//composables
const notifications = useNotificationStore()

const searchIngredients = async (search: string): Promise<AdminIngredient[]> => {
  const response = await foodApi.listAdminIngredients({ search })
  return response.data
}
const handleIngredientSelected = async (item: AdminIngredient) => {
  try {
    const response = await dietsApi.addIngredient(props.dietId, item.id)
    notifications.success('Ingredientul a fost adaugat cu succes!')
    emit('reloadList', response.data)
  } catch (err) {
    notifications.apiError(err)
  }
}

const handleDelete = async (ingredientId: number) => {
  const confirmed = await notifications.confirm({
    title: 'Șterge ingredientul',
    message: 'Sigur vrei să ștergi acest ingredient?',
    confirmText: 'Șterge',
    cancelText: 'Anulează',
  })

  if (!confirmed) return

  try {
    const response = await dietsApi.deleteIngredient(props.dietId, ingredientId)

    emit('reloadList', response.data)

    notifications.success('Ingredient a fost ștearsă.')
  } catch (err) {
    notifications.apiError(err, 'Nu am putut șterge ingredientul.')
  }
}

const handleSave = async (payload: DietIngredient) => {
  try {
    savingIds.value = payload.id

    const response = await dietsApi.saveIngredient(props.dietId, payload)

    emit('reloadList', response.data)
    notifications.success('Ingredientul a fost modificat cu succes.')
  } catch (err) {
    notifications.apiError(err)
  } finally {
    savingIds.value = 0
  }
}
</script>
<template>
  <div class="diet-ingredients-wrapper">
    <AppAutocomplete
      :search="searchIngredients"
      :get-label="(item) => item.name"
      placeholder="Cauta un ingredient"
      @select="handleIngredientSelected"
    >
      <template #item="{ item }">
        <div class="ingredient-result">
          <strong>{{ item.name }}</strong>
          <span>
            <PlusIcon />
          </span>
        </div>
      </template>
    </AppAutocomplete>
    <div class="diet-ingredients-list__wrapper">
      <IngredientCard
        v-for="ingredient in props.ingredients"
        :key="ingredient.id"
        :ingredient="ingredient"
        :disable-actions="savingIds === ingredient.id"
        @delete="handleDelete"
        @save="handleSave"
      />
    </div>
  </div>
</template>
<style scoped lang="css">
@import url(../styles/diet-ingredients.style.css);
</style>
