<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import type { ActiveShoppingList, ShoppingListRecipe } from '@/types/shopping'

const props = defineProps<{ list: ActiveShoppingList; readonly?: boolean }>()
const emit = defineEmits<{ 'open-recipe': [] }>()
const shopping = useActiveShoppingListStore()
const notifications = useNotificationStore()
const titleId = useId()
const selected = ref<ShoppingListRecipe | null>(null)
const dialog = ref<HTMLDialogElement | null>(null)
const container = ref<HTMLElement | null>(null)
const busy = computed(() => shopping.removingRecipeId !== null)
const canRemove = computed(
  () => !props.readonly && props.list.status !== 'closed' && props.list.id === shopping.list?.id,
)
const recipesUnavailable = computed(() => !props.list.recipes && props.list.recipes_count > 0)

async function confirm(recipe: ShoppingListRecipe): Promise<void> {
  if (!canRemove.value || busy.value || shopping.removalBlocked) return
  selected.value = recipe
  await nextTick()
  dialog.value?.showModal()
}
function cancel(): void {
  if (busy.value) return
  dialog.value?.close()
  selected.value = null
}
async function remove(): Promise<void> {
  if (!selected.value || !canRemove.value || busy.value || shopping.removalBlocked) return
  try {
    await shopping.removeRecipe(selected.value.id)
    dialog.value?.close()
    selected.value = null
    notifications.success('Rețeta a fost eliminată din listă.')
    await nextTick()
    container.value?.focus({ preventScroll: true })
  } catch (failure) {
    notifications.error(
      actionErrorMessage(failure, 'Nu am putut elimina rețeta. Încearcă din nou.'),
    )
  }
}
watch(
  () => props.list.id,
  () => {
    dialog.value?.close()
    selected.value = null
  },
)
</script>

<template>
  <div ref="container" class="shopping-recipes" tabindex="-1">
    <p v-if="recipesUnavailable" class="shopping-recipes__state" role="status">
      Nu am putut încărca rețetele acestei liste.
    </p>
    <p v-else-if="!list.recipes?.length" class="shopping-recipes__state">
      Nu sunt rețete în această listă.
    </p>
    <ul v-else>
      <li v-for="recipe in list.recipes" :key="recipe.id" class="shopping-recipes__row">
        <img
          v-if="recipe.image_url"
          :src="recipe.image_url"
          alt=""
          loading="lazy"
          width="64"
          height="64"
        />
        <div class="shopping-recipes__details">
          <strong>{{ recipe.name }}</strong>
          <small v-if="recipe.tags?.length">{{
            recipe.tags.map((tag) => tag.name).join(' · ')
          }}</small>
          <div class="shopping-recipes__actions">
            <RouterLink
              :to="{ name: 'recipe-detail', params: { id: recipe.id } }"
              @click="emit('open-recipe')"
              >Vezi rețeta</RouterLink
            >
            <button
              v-if="canRemove"
              type="button"
              :disabled="busy || shopping.removalBlocked"
              :aria-label="`Elimină: ${recipe.name}`"
              @click="confirm(recipe)"
            >
              Elimină
            </button>
          </div>
        </div>
      </li>
    </ul>
    <Teleport to="body">
      <dialog
        v-if="selected"
        ref="dialog"
        class="shopping-recipes__dialog"
        :aria-labelledby="titleId"
        @cancel.prevent="cancel"
      >
        <form @submit.prevent="remove">
          <h2 :id="titleId">Elimini rețeta din listă?</h2>
          <p class="shopping-recipes__selected">{{ selected.name }}</p>
          <p>
            Ingredientele adăugate doar de această rețetă vor fi eliminate din lista de cumpărături.
            Ingredientele folosite și de alte rețete vor fi recalculate.
          </p>
          <div class="shopping-recipes__confirmation">
            <AppButton variant="secondary" :disabled="busy" @click="cancel">Anulează</AppButton>
            <AppButton
              class="shopping-recipes__destructive"
              type="submit"
              :loading="busy"
              :disabled="shopping.removalBlocked"
              >Elimină rețeta</AppButton
            >
          </div>
        </form>
      </dialog>
    </Teleport>
  </div>
</template>

<style scoped>
.shopping-recipes ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.shopping-recipes__row {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) 0;
  border-bottom: 1px solid var(--color-border);
}
.shopping-recipes__row img {
  flex-shrink: 0;
  width: 64px;
  height: 64px;
  object-fit: cover;
  border-radius: 4px;
}
.shopping-recipes__details {
  flex: 1;
  min-width: 0;
}
.shopping-recipes__details strong {
  display: block;
  font-size: 0.94rem;
  overflow-wrap: anywhere;
}
.shopping-recipes__details small {
  display: block;
  margin-top: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}
.shopping-recipes__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
}
.shopping-recipes__actions a,
.shopping-recipes__actions button {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--color-primary-strong);
  font-size: 0.8rem;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
}
.shopping-recipes__actions button {
  color: var(--color-error);
}
.shopping-recipes__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.shopping-recipes__state {
  margin: 0;
  padding: var(--space-5) 0;
  font-size: 0.88rem;
  color: var(--color-text-muted);
  text-align: center;
}
.shopping-recipes__dialog {
  width: min(calc(100% - 32px), 440px);
  max-height: calc(
    100dvh - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px) - 32px
  );
  overflow-y: auto;
  padding: var(--space-5);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-text);
  background: var(--color-surface);
}
.shopping-recipes__dialog::backdrop {
  background: var(--color-overlay);
}
.shopping-recipes__dialog h2 {
  margin: 0;
  font-size: 1.15rem;
  overflow-wrap: anywhere;
}
.shopping-recipes__dialog p {
  font-size: 0.88rem;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.shopping-recipes__selected {
  font-weight: 700;
}
.shopping-recipes__confirmation {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
.shopping-recipes__destructive {
  background: var(--color-error);
  box-shadow: none;
}
</style>
