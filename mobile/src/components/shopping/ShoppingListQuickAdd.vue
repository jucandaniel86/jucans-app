<script setup lang="ts">
import { nextTick, ref, useId } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import { ApiError } from '@/services/api'

const shopping = useActiveShoppingListStore()
const notifications = useNotificationStore()
const id = useId()
const expanded = ref(false)
const name = ref('')
const quantity = ref('')
const unit = ref('')
const error = ref('')
const nameInput = ref<HTMLInputElement | null>(null)
const action = ref<HTMLButtonElement | null>(null)

async function open(): Promise<void> {
  expanded.value = true
  await nextTick()
  nameInput.value?.focus()
}

async function collapse(): Promise<void> {
  expanded.value = false
  error.value = ''
  await nextTick()
  action.value?.focus()
}

async function save(): Promise<void> {
  if (shopping.addingItem || shopping.closing) return
  error.value = ''
  if (!name.value.trim()) {
    error.value = 'Introdu un produs.'
    nameInput.value?.focus()
    return
  }
  const amount = quantity.value.trim() ? Number(quantity.value.replace(',', '.')) : null
  if (amount !== null && (!Number.isFinite(amount) || amount <= 0)) {
    error.value = 'Cantitatea trebuie să fie mai mare decât zero.'
    return
  }
  try {
    await shopping.addManualItem({
      name: name.value.trim(),
      quantity: amount,
      unit: unit.value.trim() || null,
    })
    name.value = ''
    quantity.value = ''
    unit.value = ''
    await collapse()
    notifications.success('Produsul a fost adăugat.')
  } catch (failure) {
    if (failure instanceof ApiError && failure.status === 422 &&
      Object.keys(failure.errors).some((field) => ['name', 'quantity', 'unit'].includes(field))) {
      error.value = 'Verifică produsul, cantitatea și unitatea.'
      return
    }
    notifications.error(
      actionErrorMessage(failure, 'Nu am putut adăuga produsul. Încearcă din nou.'),
    )
  }
}
</script>

<template>
  <div class="shopping-quick-add">
    <button
      v-if="!expanded"
      ref="action"
      type="button"
      class="shopping-quick-add__action"
      :disabled="shopping.closing || shopping.addingItem"
      :aria-controls="`${id}-editor`"
      :aria-expanded="false"
      @click="open"
    >
      + Adaugă produs
    </button>
    <form v-else :id="`${id}-editor`" :aria-busy="shopping.addingItem" @submit.prevent="save">
      <div class="shopping-quick-add__heading">
        <strong>Adaugă produs</strong>
        <button
          type="button"
          aria-label="Anulează adăugarea"
          title="Anulează adăugarea"
          :disabled="shopping.addingItem"
          @click="collapse"
        >
          ×
        </button>
      </div>
      <fieldset :disabled="shopping.addingItem || shopping.closing">
        <label class="shopping-quick-add__name" :for="`${id}-name`"
          >Produs
          <input
            :id="`${id}-name`"
            ref="nameInput"
            v-model="name"
            name="name"
            required
            autocomplete="off"
          />
        </label>
        <label :for="`${id}-quantity`"
          >Cantitate
          <input
            :id="`${id}-quantity`"
            v-model="quantity"
            name="quantity"
            inputmode="decimal"
            enterkeyhint="next"
          />
        </label>
        <label :for="`${id}-unit`"
          >Unitate
          <input
            :id="`${id}-unit`"
            v-model="unit"
            name="unit"
            autocomplete="off"
            enterkeyhint="done"
          />
        </label>
        <AppButton type="submit" :loading="shopping.addingItem" :disabled="!name.trim()"
          >Salvează</AppButton
        >
      </fieldset>
      <p v-if="error" class="shopping-quick-add__error" role="alert">{{ error }}</p>
    </form>
  </div>
</template>

<style scoped>
.shopping-quick-add {
  flex-shrink: 0;
  border-top: 1px solid var(--color-border);
  background: var(--color-surface);
}
.shopping-quick-add__action {
  width: 100%;
  min-height: 48px;
  padding: var(--space-2) var(--space-4);
  border: 0;
  color: var(--color-primary-strong);
  background: transparent;
  font-weight: 750;
  text-align: left;
  cursor: pointer;
}
form {
  padding: 0 var(--space-4) var(--space-3);
}
.shopping-quick-add__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 44px;
  font-size: 0.88rem;
}
.shopping-quick-add__heading button {
  width: 44px;
  height: 44px;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.5rem;
  cursor: pointer;
}
fieldset {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  align-items: end;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}
label {
  display: grid;
  gap: var(--space-1);
  min-width: 0;
  font-size: 0.8rem;
  font-weight: 650;
}
.shopping-quick-add__name {
  grid-column: 1 / -1;
}
input {
  width: 100%;
  min-width: 0;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: 4px;
  color: var(--color-text);
  background: var(--color-surface);
  font-size: 1rem;
}
input:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 1px;
}
fieldset .app-button {
  grid-column: 1 / -1;
  min-height: 44px;
  border-radius: 4px;
}
.shopping-quick-add__error {
  margin: var(--space-2) 0 0;
  font-size: 0.8rem;
  color: var(--color-error);
}
button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
</style>
