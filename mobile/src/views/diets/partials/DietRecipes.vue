<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppAutocomplete from '@/components/app/AppAutocomplete.vue'
import AppModal from '@/components/app/AppModal.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { dietsApi } from '@/services/dietsApi'
import { foodApi } from '@/services/foodApi'
import { useNotificationStore } from '@/stores/notifications'
import type { DietRecipe, DietRecipePriority } from '@/types/diets'
import type { RecipeSummary } from '@/types/food'

const props = defineProps<{ dietId: number; recipes: DietRecipe[] }>()
const emit = defineEmits<{ reloadList: [items: DietRecipe[]] }>()
const router = useRouter()
const notifications = useNotificationStore()
const associationOpen = ref(false)
const busy = ref(false)
// Only unsaved form values live here; the associated recipes come from the parent.
const priorities = ref<Partial<Record<number, DietRecipePriority>>>({})
const priorityOptions = [
  { value: 'normal', label: 'normal' },
  { value: 'high', label: 'high' },
  { value: 'required', label: 'required' },
]

async function searchRecipes(search: string): Promise<RecipeSummary[]> {
  try {
    const response = await foodApi.listRecipes({ search, sort: 'name', perPage: 50 })
    const existingIds = new Set(props.recipes.map((recipe) => recipe.id))
    return response.data.filter((recipe) => !existingIds.has(recipe.id))
  } catch (error) {
    notifications.apiError(error)
    throw error
  }
}

async function associateRecipe(recipe: RecipeSummary): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    const response = await dietsApi.addRecipe(props.dietId, recipe.id)
    emit('reloadList', response.data)
    associationOpen.value = false
    notifications.success('Rețeta a fost asociată dietei.')
  } catch (error) {
    notifications.apiError(error)
  } finally {
    busy.value = false
  }
}

async function savePriority(recipe: DietRecipe): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    const response = await dietsApi.saveRecipe(
      props.dietId,
      recipe.id,
      priorities.value[recipe.id] ?? recipe.priority,
    )
    emit('reloadList', response.data)
    delete priorities.value[recipe.id]
    notifications.success('Prioritatea rețetei a fost salvată.')
  } catch (error) {
    notifications.apiError(error)
  } finally {
    busy.value = false
  }
}

async function deleteRecipe(recipe: DietRecipe): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    const confirmed = await notifications.confirm({
      title: 'Elimină asocierea rețetei',
      message: `Sigur vrei să elimini rețeta „${recipe.name}” din dietă?`,
      confirmText: 'Elimină',
      cancelText: 'Anulează',
    })
    if (!confirmed) return
    const response = await dietsApi.deleteRecipe(props.dietId, recipe.id)
    emit('reloadList', response.data)
    delete priorities.value[recipe.id]
    notifications.success('Asocierea rețetei a fost eliminată.')
  } catch (error) {
    notifications.apiError(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="diet-recipes">
    <div class="diet-recipes__actions">
      <AppButton :disabled="busy" @click="associationOpen = true">Asociază rețetă</AppButton>
      <AppButton
        variant="secondary"
        :disabled="busy"
        @click="router.push({ name: 'recipe-create', query: { back_to_diet: String(dietId) } })"
      >
        Adaugă rețetă nouă
      </AppButton>
      <span class="diet-recipes__count" role="status">
        {{ recipes.length }} {{ recipes.length === 1 ? 'rețetă' : 'rețete' }}
      </span>
    </div>
    <p v-if="!recipes.length">Nu există rețete asociate dietei.</p>
    <ul v-else class="diet-recipes__list" aria-label="Rețete asociate dietei" tabindex="0">
      <li v-for="recipe in recipes" :key="recipe.id" class="diet-recipes__row">
        <strong>{{ recipe.name }}</strong>
        <div class="diet-recipes__controls">
          <AppSelect
            :model-value="priorities[recipe.id] ?? recipe.priority"
            :label="`Prioritate: ${recipe.name}`"
            :options="priorityOptions"
            :disabled="busy"
            @update:model-value="priorities[recipe.id] = $event as DietRecipePriority"
          />
          <AppButton
            :disabled="busy"
            :aria-label="`Salvează prioritatea: ${recipe.name}`"
            @click="savePriority(recipe)"
          >
            Save
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="busy"
            :aria-label="`Elimină asocierea: ${recipe.name}`"
            @click="deleteRecipe(recipe)"
          >
            Delete
          </AppButton>
        </div>
      </li>
    </ul>
    <AppModal v-model="associationOpen" title="Asociază rețetă">
      <fieldset class="diet-recipes__association" :disabled="busy" :aria-busy="busy">
        <AppAutocomplete
          v-if="associationOpen"
          :search="searchRecipes"
          :get-label="(recipe) => recipe.name"
          placeholder="Caută o rețetă"
          aria-label="Caută o rețetă pentru dietă"
          @select="associateRecipe"
        />
        <p v-if="busy" role="status">Se asociază rețeta…</p>
      </fieldset>
    </AppModal>
  </div>
</template>

<style scoped>
.diet-recipes,
.diet-recipes__list {
  display: grid;
  gap: var(--space-3);
}

.diet-recipes__actions,
.diet-recipes__controls {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: var(--space-2);
}

.diet-recipes__count {
  align-self: center;
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 700;
  white-space: nowrap;
}

.diet-recipes__list {
  max-height: 60dvh;
  overflow-y: auto;
  overscroll-behavior: contain;
  margin: 0;
  padding: var(--space-1);
  list-style: none;
}

.diet-recipes__row {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  overflow-wrap: anywhere;
}

.diet-recipes__controls > :first-child {
  flex: 1 1 150px;
  min-width: 0;
}

.diet-recipes__controls > button {
  min-height: 48px;
  padding: 0 var(--space-3);
}

.diet-recipes__association {
  min-width: 0;
  min-height: 260px;
  margin: 0;
  padding: 0;
  border: 0;
}

:global(.modal-overlay:has(.diet-recipes__association)) {
  z-index: 40;
}
</style>
