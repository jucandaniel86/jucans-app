<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import type { FoodUnit } from '@/types/food'
import type { ShoppingItem } from '@/types/shopping'
import { quantityLabel } from '@/utils/recipePresentation'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import { ApiError } from '@/services/api'

const props = defineProps<{
  item: ShoppingItem
  units: Record<string, FoodUnit>
  readonly?: boolean
}>()
const shopping = useActiveShoppingListStore()
const notifications = useNotificationStore()
const editing = ref(false)
const confirmingDelete = ref(false)
const name = ref(props.item.name)
const quantity = ref(props.item.quantity ?? '')
const unit = ref(props.item.unit ?? '')
const formError = ref('')
const manual = computed(() => props.item.ingredient_id === null && !props.item.sources?.length)
const busy = computed(() => Boolean(shopping.pendingItemEdits[props.item.id]))
const canEdit = computed(
  () => !props.readonly && !shopping.closing && shopping.removingRecipeId === null,
)
const recipeQuantityLabel = computed(() =>
  quantityLabel(props.item.calculated_quantity, props.item.unit, props.units),
)

function resetForm(): void {
  name.value = props.item.name
  quantity.value = props.item.quantity ?? ''
  unit.value = props.item.unit ?? ''
  formError.value = ''
  confirmingDelete.value = false
}

function parseQuantity(): number | null {
  const value = quantity.value.trim().replace(',', '.')
  if (value === '') return null

  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : Number.NaN
}

function openEditor(): void {
  resetForm()
  editing.value = true
}

function closeEditor(): void {
  editing.value = false
  resetForm()
}

async function changeChecked(event: Event): Promise<void> {
  if (props.readonly) return
  const input = event.target as HTMLInputElement
  const hadFocus = document.activeElement === input
  await shopping.setChecked(props.item.id, input.checked)
  await nextTick()
  if (hadFocus && document.activeElement === document.body) {
    document.getElementById(`shopping-item-${props.item.id}`)?.focus()
  }
}

async function saveEdit(): Promise<void> {
  formError.value = ''
  const parsedQuantity = parseQuantity()
  if (Number.isNaN(parsedQuantity)) {
    formError.value = 'Cantitatea trebuie să fie numerică.'
    return
  }
  if (manual.value && name.value.trim() === '') {
    formError.value = 'Produsul este obligatoriu.'
    return
  }
  try {
    if (manual.value) {
      await shopping.updateItem(props.item.id, {
        name: name.value.trim(),
        quantity: parsedQuantity,
        unit: unit.value.trim() || null,
      })
    } else {
      await shopping.updateItem(props.item.id, { quantity: parsedQuantity })
    }
    closeEditor()
    notifications.success('Produsul a fost salvat.')
  } catch (failure) {
    if (failure instanceof ApiError && failure.status === 422 &&
      Object.keys(failure.errors).some((field) => ['name', 'quantity', 'unit'].includes(field))) {
      formError.value = 'Verifică produsul, cantitatea și unitatea.'
      return
    }
    notifications.error(
      actionErrorMessage(failure, 'Nu am putut salva produsul. Încearcă din nou.'),
    )
  }
}

async function resetOverride(): Promise<void> {
  try {
    await shopping.updateItem(props.item.id, { reset_quantity: true })
    closeEditor()
    notifications.success('Cantitatea rețetelor a fost restabilită.')
  } catch (failure) {
    notifications.error(
      actionErrorMessage(failure, 'Nu am putut salva produsul. Încearcă din nou.'),
    )
  }
}

async function deleteItem(): Promise<void> {
  try {
    await shopping.deleteItem(props.item.id)
    closeEditor()
    notifications.success('Produsul a fost șters.')
  } catch (failure) {
    notifications.error(
      actionErrorMessage(failure, 'Nu am putut șterge produsul. Încearcă din nou.'),
    )
  }
}

watch(() => props.item.id, closeEditor)
</script>

<template>
  <li class="shopping-item" :class="{ 'shopping-item--checked': item.is_checked }">
    <div class="shopping-item__line">
      <label
        class="shopping-item__control"
        :aria-busy="shopping.pendingChecks[item.id] !== undefined"
      >
        <input
          :id="`shopping-item-${item.id}`"
          type="checkbox"
          :checked="item.is_checked"
          :disabled="
            readonly ||
            shopping.closing ||
            shopping.removingRecipeId !== null ||
            shopping.pendingChecks[item.id] !== undefined
          "
          :aria-label="`${item.is_checked ? 'Marchează ca necumpărat' : 'Marchează ca cumpărat'}: ${item.name}`"
          @change="changeChecked"
        />
        <strong>{{ item.name }}</strong>
        <span
          v-if="item.quantity !== null || item.unit === 'to_taste'"
          class="shopping-list__quantity"
          >{{ quantityLabel(item.quantity, item.unit, units) }}</span
        >
        <small v-if="item.sources?.length" class="shopping-list__sources"
          >{{ item.sources.length }} {{ item.sources.length === 1 ? 'rețetă' : 'rețete' }}</small
        >
      </label>
      <button
        v-if="canEdit"
        class="shopping-item__edit"
        type="button"
        :aria-expanded="editing"
        :aria-label="`Editează ${item.name}`"
        :disabled="busy"
        @click="editing ? closeEditor() : openEditor()"
      />
    </div>
    <form v-if="editing" class="shopping-item__editor" @submit.prevent="saveEdit">
      <template v-if="manual">
        <AppInput v-model="name" label="Produs" :error="formError" :disabled="busy" />
        <div class="shopping-item__fields">
          <AppInput v-model="quantity" label="Cantitate" inputmode="decimal" :disabled="busy" />
          <AppInput v-model="unit" label="Unitate" :disabled="busy" />
        </div>
      </template>
      <template v-else>
        <div class="shopping-item__fields">
          <AppInput
            v-model="quantity"
            label="Cantitate"
            inputmode="decimal"
            :error="formError"
            :disabled="busy"
          />
          <p class="shopping-item__readonly-unit">
            <span>Unitate</span>
            <strong>{{ item.unit || 'fără unitate' }}</strong>
          </p>
        </div>
        <p v-if="item.quantity_overridden" class="shopping-item__recipe-quantity">
          Rețete: {{ recipeQuantityLabel }}
        </p>
      </template>
      <div v-if="confirmingDelete" class="shopping-item__confirm" role="alert">
        <h3>Ștergi produsul?</h3>
        <p>Produsul va fi eliminat din lista de cumpărături.</p>
        <div>
          <AppButton variant="secondary" :disabled="busy" @click="confirmingDelete = false"
            >Anulează</AppButton
          >
          <AppButton :loading="busy" @click="deleteItem">Șterge</AppButton>
        </div>
      </div>
      <div v-else class="shopping-item__editor-actions">
        <button
          v-if="!manual && item.quantity_overridden"
          class="shopping-item__reset"
          type="button"
          :disabled="busy"
          @click="resetOverride"
        >
          Folosește cantitatea rețetelor
        </button>
        <button
          v-if="manual"
          class="shopping-item__delete"
          type="button"
          :disabled="busy"
          @click="confirmingDelete = true"
        >
          Șterge produs
        </button>
        <span />
        <AppButton variant="secondary" :disabled="busy" @click="closeEditor">Anulează</AppButton>
        <AppButton type="submit" :loading="busy">Salvează</AppButton>
      </div>
    </form>
  </li>
</template>

<style scoped>
.shopping-item + .shopping-item {
  border-top: 1px solid var(--color-border);
}
.shopping-item__line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 36px;
  align-items: center;
  gap: var(--space-1);
}
.shopping-item__control {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) minmax(0, auto);
  align-items: center;
  gap: var(--space-2);
  min-height: 52px;
  padding: var(--space-2) 0;
  cursor: pointer;
}
.shopping-item__control input {
  margin: 0;
  width: 22px;
  height: 22px;
  accent-color: var(--color-primary-strong);
  cursor: pointer;
}
.shopping-item__control[aria-busy='true'] {
  cursor: wait;
}
.shopping-item__control strong {
  font-size: 0.96rem;
  font-weight: 650;
  overflow-wrap: anywhere;
}
.shopping-item__edit {
  width: 36px;
  height: 36px;
  border: 1px solid transparent;
  border-radius: 8px;
  color: var(--color-text-muted);
  background: transparent;
  font-size: 1.2rem;
  line-height: 1;
  cursor: pointer;
}
.shopping-item__edit::before {
  content: '⋯';
}
.shopping-item__edit:hover,
.shopping-item__edit[aria-expanded='true'] {
  border-color: var(--color-border);
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
}
.shopping-item--checked strong {
  font-weight: 500;
  text-decoration: line-through;
  color: var(--color-text-muted);
}
.shopping-item--checked .shopping-list__quantity {
  color: var(--color-text-muted);
}
.shopping-list__quantity {
  text-align: right;
  font-size: 0.92rem;
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
.shopping-list__sources {
  grid-column: 2 / -1;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
.shopping-item__editor {
  display: grid;
  gap: var(--space-3);
  margin: 0 0 var(--space-3) 32px;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: color-mix(in srgb, var(--color-primary-soft) 45%, var(--color-surface));
}
.shopping-item__fields {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(96px, 0.55fr);
  gap: var(--space-2);
}
.shopping-item__readonly-unit {
  display: grid;
  align-content: start;
  gap: var(--space-2);
  margin: 0;
}
.shopping-item__readonly-unit span {
  padding-left: var(--space-1);
  font-size: 0.9rem;
  font-weight: 720;
}
.shopping-item__readonly-unit strong {
  min-height: 54px;
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-text-muted);
  background: var(--color-surface);
}
.shopping-item__recipe-quantity {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}
.shopping-item__editor-actions {
  display: grid;
  grid-template-columns: auto auto 1fr auto auto;
  align-items: center;
  gap: var(--space-2);
}
.shopping-item__delete,
.shopping-item__reset {
  min-height: 44px;
  border: 0;
  padding: 0 var(--space-1);
  background: transparent;
  font-weight: 750;
  cursor: pointer;
}
.shopping-item__delete {
  color: var(--color-error);
}
.shopping-item__reset {
  color: var(--color-primary-strong);
}
.shopping-item__confirm {
  display: grid;
  gap: var(--space-2);
}
.shopping-item__confirm h3,
.shopping-item__confirm p {
  margin: 0;
}
.shopping-item__confirm h3 {
  font-size: 1rem;
}
.shopping-item__confirm p {
  color: var(--color-text-muted);
  font-size: 0.88rem;
}
.shopping-item__confirm div {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}

@media (max-width: 480px) {
  .shopping-item__fields,
  .shopping-item__editor-actions {
    grid-template-columns: 1fr;
  }
  .shopping-item__editor-actions span {
    display: none;
  }
}
</style>
