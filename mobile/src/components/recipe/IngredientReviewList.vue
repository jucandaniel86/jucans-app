<script setup lang="ts">
import type { IngredientDraftError } from '@/composables/recipeDraft'
import IngredientReviewCard from '@/components/recipe/IngredientReviewCard.vue'
import type { SelectOption } from '@/components/ui/AppSelect.vue'
import type { ExistingIngredient, IngredientReviewDraft } from '@/types/food'

defineProps<{
  ingredients: IngredientReviewDraft[]
  units: SelectOption[]
  errors: Record<string, IngredientDraftError>
  associatingKey: string | null
  associationErrors: Record<string, string>
}>()

defineEmits<{
  update: [item: IngredientReviewDraft]
  chooseCandidate: [key: string, candidate: ExistingIngredient]
  chooseNew: [key: string]
  remove: [key: string]
  add: []
  associate: [key: string, ingredient: ExistingIngredient]
}>()
</script>

<template>
  <div class="review-list">
    <IngredientReviewCard
      v-for="(ingredient, index) in ingredients"
      :key="ingredient.key"
      :item="ingredient"
      :index="index"
      :units="units"
      :error="errors[ingredient.key]"
      :association-saving="associatingKey !== null"
      :association-error="associationErrors[ingredient.key] ?? ''"
      @update="$emit('update', $event)"
      @choose-candidate="$emit('chooseCandidate', ingredient.key, $event)"
      @choose-new="$emit('chooseNew', ingredient.key)"
      @remove="$emit('remove', ingredient.key)"
      @associate="$emit('associate', ingredient.key, $event)"
    />

    <button class="review-list__add" type="button" @click="$emit('add')">
      <span aria-hidden="true">+</span> Adaugă ingredient
    </button>
  </div>
</template>

<style scoped>
.review-list {
  display: grid;
  gap: var(--space-3);
}

.review-list__add {
  min-height: 50px;
  border: 1.5px dashed var(--color-primary);
  border-radius: var(--radius-md);
  color: var(--color-primary-strong);
  font-weight: 780;
  background: var(--color-primary-soft);
  cursor: pointer;
  transition: transform var(--transition-fast);
}

.review-list__add:active {
  transform: scale(0.98);
}
</style>
