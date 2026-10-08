<script setup lang="ts">
import { computed, reactive } from 'vue'
import { dietIngredientStatus } from '@/config/dietIngredientStatus'
import { DietIngredientStatus, type DietIngredient } from '@/types/diets'

//components
import AppSelect from '@/components/ui/AppSelect.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppButton from '@/components/ui/AppButton.vue'
import TrashIcon from '@/components/icons/trashIcon.vue'
import SaveIcon from '@/components/icons/saveIcon.vue'

const props = defineProps<{
  ingredient: DietIngredient
  disableActions: boolean
}>()

const emit = defineEmits<{
  save: [source: DietIngredient]
  delete: [sourceId: number]
}>()

const ingredientPayload = reactive<{ status: string; notes: string; id: number }>({
  status: props.ingredient.status,
  notes: props.ingredient.notes || '',
  id: props.ingredient.id,
})

const statusLabel = computed(
  () => dietIngredientStatus[ingredientPayload.status as DietIngredientStatus].label,
)
const statusOptions = Object.entries(dietIngredientStatus).map(([value, item]) => ({
  value,
  label: item.label,
}))
</script>
<template>
  <div class="ingredient-card">
    <div class="ingredient-card__title">
      <span>{{ props.ingredient.name }}</span>
      <span class="ingredient-card__status" :class="`diet-status--${ingredientPayload.status}`">{{
        statusLabel
      }}</span>
    </div>
    <div class="ingredient-card__content">
      <AppSelect v-model="ingredientPayload.status" label="Statut" :options="statusOptions" />
      <AppInput v-model="ingredientPayload.notes" label="Observatii" />
    </div>
    <div class="ingredient-card__actions">
      <AppButton
        type="button"
        class="ingredient-action__btn"
        @click="emit('save', ingredientPayload)"
        aria-label="Editează ingredientul"
        :loading="props.disableActions"
        app-button__label
      >
        <SaveIcon />
        Salveaza
      </AppButton>

      <AppButton
        type="button"
        class="ingredient-action__btn delete"
        aria-label="Șterge ingredientul"
        @click="emit('delete', props.ingredient.id)"
        :disabled="props.disableActions"
      >
        <TrashIcon />
      </AppButton>
    </div>
  </div>
</template>
<style scoped lang="css">
@import url(../styles/ingredient-card.style.css);
</style>
