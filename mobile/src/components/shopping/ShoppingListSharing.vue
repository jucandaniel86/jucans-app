<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import UserAvatar from '@/components/user/UserAvatar.vue'
import { ApiError } from '@/services/api'
import { shoppingApi } from '@/services/shoppingApi'
import { useNotificationStore } from '@/stores/notifications'
import { actionErrorMessage } from '@/utils/actionError'
import type {
  ShoppingListSummary,
  ShoppingListUser,
  ShoppingListVisibility,
} from '@/types/shopping'

const props = defineProps<{ list: ShoppingListSummary; disabled?: boolean; compact?: boolean }>()
const emit = defineEmits<{ updated: [list: ShoppingListSummary]; busy: [value: boolean] }>()
const notifications = useNotificationStore()
const dialog = ref<HTMLDialogElement | null>(null)
const titleId = useId()
const visibilityId = useId()
const visibility = ref<ShoppingListVisibility>(props.list.visibility)
const members = ref<ShoppingListUser[]>([])
const loading = ref(false)
const loaded = ref(false)
const busy = ref(false)
const userId = ref('')
const userSelectId = useId()
const users = ref<{ id: number; name: string }[]>([])
const availableUsers = computed(() =>
  users.value.filter((user) => !members.value.some((member) => member.id === user.id)),
)
const error = ref('')
const userError = ref('')
const removing = ref<ShoppingListUser | null>(null)
let alive = true
let sequence = 0
onBeforeUnmount(() => {
  alive = false
  sequence++
})

function message(failure: unknown, fallback: string): string {
  if (failure instanceof ApiError && failure.status === 403)
    return 'Doar creatorul listei poate gestiona partajarea.'
  if (failure instanceof ApiError && failure.status === 404)
    return 'Lista sau utilizatorul nu mai este disponibil.'
  return actionErrorMessage(failure, fallback)
}
async function loadMembers(): Promise<void> {
  const request = ++sequence
  loading.value = true
  loaded.value = false
  error.value = ''
  try {
    const [response, accounts] = await Promise.all([
      shoppingApi.getUsers(props.list.id),
      shoppingApi.getShareableUsers(),
    ])
    if (!alive || request !== sequence) return
    members.value = response.data.filter((member) => member.id !== props.list.created_by)
    users.value = accounts.data
    loaded.value = true
  } catch (failure) {
    if (alive && request === sequence)
      error.value = message(failure, 'Nu am putut încărca persoanele. Încearcă din nou.')
  } finally {
    if (alive && request === sequence) loading.value = false
  }
}
function open(): void {
  if (!props.list.is_creator || props.disabled) return
  visibility.value = props.list.visibility
  userId.value = ''
  userError.value = ''
  removing.value = null
  dialog.value?.showModal()
  void loadMembers()
}
function close(): void {
  if (busy.value) return
  sequence++
  dialog.value?.close()
}
function setBusy(value: boolean): void {
  busy.value = value
  if (alive) emit('busy', value)
}
async function saveVisibility(): Promise<void> {
  if (busy.value || !props.list.is_creator || visibility.value === props.list.visibility) return
  setBusy(true)
  error.value = ''
  try {
    const response = await shoppingApi.setVisibility(props.list.id, visibility.value)
    if (!alive) return
    emit('updated', response.data)
    visibility.value = response.data.visibility
    notifications.success('Vizibilitatea a fost salvată.')
  } catch (failure) {
    if (alive)
      notifications.error(message(failure, 'Nu am putut salva vizibilitatea. Încearcă din nou.'))
  } finally {
    setBusy(false)
  }
}
async function share(): Promise<void> {
  if (busy.value || !loaded.value || !props.list.is_creator) return
  userError.value = ''
  error.value = ''
  const id = Number(userId.value)
  if (!availableUsers.value.some((user) => user.id === id)) {
    userError.value = 'Alege un cont Jucans.'
    return
  }
  if (id === props.list.created_by) {
    userError.value = 'Lista îți aparține deja.'
    return
  }
  setBusy(true)
  let attached = false
  try {
    const response = await shoppingApi.attachUser(props.list.id, id)
    if (!alive) return
    if (!members.value.some((member) => member.id === response.data.id))
      members.value.push(response.data)
    attached = true
    userId.value = ''
    if (props.list.visibility === 'private') {
      const updated = await shoppingApi.setVisibility(props.list.id, 'shared')
      if (!alive) return
      emit('updated', updated.data)
      visibility.value = updated.data.visibility
    }
    notifications.success(`Lista este partajată cu ${response.data.name}.`)
  } catch (failure) {
    if (!alive) return
    if (attached)
      notifications.warning(
        'Persoana a fost adăugată, dar lista este încă privată. Salvează vizibilitatea „Partajată” pentru a-i acorda acces.',
      )
    else if (failure instanceof ApiError && failure.status === 422)
      userError.value = 'Acest cont nu există sau nu poate fi adăugat.'
    else notifications.error(message(failure, 'Nu am putut partaja lista. Încearcă din nou.'))
  } finally {
    setBusy(false)
  }
}
async function remove(): Promise<void> {
  if (!removing.value || busy.value || !props.list.is_creator) return
  setBusy(true)
  error.value = ''
  try {
    await shoppingApi.detachUser(props.list.id, removing.value.id)
    if (!alive) return
    members.value = members.value.filter((member) => member.id !== removing.value!.id)
    removing.value = null
    notifications.success('Persoana a fost eliminată.')
  } catch (failure) {
    if (alive)
      notifications.error(message(failure, 'Nu am putut elimina persoana. Încearcă din nou.'))
  } finally {
    setBusy(false)
  }
}
function confirmRemoval(member: ShoppingListUser): void {
  removing.value = member
  error.value = ''
}
</script>

<template>
  <div v-if="list.is_creator" class="shopping-sharing">
    <button
      v-if="compact"
      class="shopping-sharing__compact"
      type="button"
      :disabled="disabled"
      aria-label="Partajare listă"
      title="Partajare listă"
      @click="open"
    >
      <span aria-hidden="true">↗</span>
    </button>
    <AppButton v-else variant="secondary" :disabled="disabled" @click="open">Partajare</AppButton>
    <Teleport to="body">
      <dialog
        ref="dialog"
        class="shopping-sharing__dialog"
        :aria-labelledby="titleId"
        @cancel.prevent="close"
      >
        <h2 :id="titleId">Partajarea listei</h2>
        <form class="shopping-sharing__visibility" @submit.prevent="saveVisibility">
          <label :for="visibilityId">Vizibilitate</label>
          <select :id="visibilityId" v-model="visibility" :disabled="busy">
            <option value="private">Privată — doar tu</option>
            <option value="shared">Partajată — persoanele adăugate</option>
            <option value="public">Publică — toți utilizatorii Jucans</option>
          </select>
          <AppButton
            type="submit"
            variant="secondary"
            :disabled="busy || visibility === list.visibility"
            >Salvează vizibilitatea</AppButton
          >
        </form>
        <p v-if="list.visibility === 'private'">
          Persoanele adăugate nu au acces cât timp lista este privată.
        </p>
        <p v-else-if="list.visibility === 'public'">
          Toți utilizatorii Jucans au acces, inclusiv cei care nu sunt adăugați mai jos.
        </p>
        <h3>Persoane adăugate</h3>
        <p v-if="loading" role="status">Se încarcă persoanele…</p>
        <template v-else-if="loaded">
          <p v-if="!members.length">Nicio persoană adăugată.</p>
          <ul v-else class="shopping-sharing__members">
            <li v-for="member in members" :key="member.id">
              <UserAvatar :username="member.username" :avatar="member.avatar" size="small" />
              <strong>{{ member.name }}</strong>
              <button
                type="button"
                :disabled="busy"
                :aria-label="`Elimină accesul pentru ${member.name}`"
                @click="confirmRemoval(member)"
              >
                Elimină
              </button>
            </li>
          </ul>
          <div v-if="removing" class="shopping-sharing__confirmation">
            <p>Elimini {{ removing.name }} din persoanele adăugate?</p>
            <p v-if="list.visibility === 'public'">
              Contul va avea în continuare acces cât timp lista este publică.
            </p>
            <AppButton variant="secondary" :disabled="busy" @click="removing = null"
              >Anulează</AppButton
            >
            <AppButton :loading="busy" @click="remove">Elimină persoana</AppButton>
          </div>
          <form v-else class="shopping-sharing__add" @submit.prevent="share">
            <template v-if="availableUsers.length">
              <label :for="userSelectId">Partajează cu</label>
              <select
                :id="userSelectId"
                v-model="userId"
                :disabled="busy"
                :aria-invalid="Boolean(userError)"
              >
                <option disabled value="">Alege o persoană</option>
                <option v-for="user in availableUsers" :key="user.id" :value="String(user.id)">
                  {{ user.name }}
                </option>
              </select>
            </template>
            <p v-else>Nu mai sunt persoane de adăugat.</p>
            <p v-if="userError" role="alert" class="shopping-sharing__error">{{ userError }}</p>
            <AppButton type="submit" :loading="busy" :disabled="!userId.trim()"
              >Partajează lista</AppButton
            >
          </form>
        </template>
        <p v-if="error" role="alert" class="shopping-sharing__error">{{ error }}</p>
        <AppButton v-if="!loading && !loaded" variant="secondary" @click="loadMembers"
          >Încearcă din nou</AppButton
        >
        <AppButton
          class="shopping-sharing__done"
          variant="secondary"
          :disabled="busy"
          @click="close"
          >Gata</AppButton
        >
      </dialog>
    </Teleport>
  </div>
</template>

<style scoped>
.shopping-sharing__compact {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-primary-strong);
  background: var(--color-surface);
  font-size: 1rem;
  font-weight: 800;
  cursor: pointer;
}
.shopping-sharing__compact:disabled {
  cursor: not-allowed;
  opacity: 0.58;
}
.shopping-sharing__dialog {
  width: min(calc(100% - 32px), 440px);
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  padding: var(--space-5);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-text);
  background: var(--color-surface);
}
.shopping-sharing__dialog::backdrop {
  background: var(--color-overlay);
}
.shopping-sharing__dialog h2 {
  margin: 0 0 var(--space-4);
  font-size: 1.2rem;
}
.shopping-sharing__dialog h3 {
  margin: var(--space-5) 0 var(--space-2);
  font-size: 0.95rem;
}
.shopping-sharing__dialog p {
  font-size: 0.88rem;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.shopping-sharing__visibility,
.shopping-sharing__add {
  display: grid;
  gap: var(--space-3);
}
.shopping-sharing__visibility label {
  font-size: 0.9rem;
  font-weight: 700;
}
.shopping-sharing__visibility select,
.shopping-sharing__add select {
  width: 100%;
  min-height: 48px;
  padding: var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: 4px;
  color: var(--color-text);
  background: var(--color-surface);
  font-size: 0.85rem;
}
.shopping-sharing__members {
  list-style: none;
  margin: 0 0 var(--space-4);
  padding: 0;
}
.shopping-sharing__members li {
  display: flex;
  gap: var(--space-2);
  align-items: center;
  min-height: 48px;
  border-bottom: 1px solid var(--color-border);
}
.shopping-sharing__members strong {
  flex: 1;
  min-width: 0;
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.shopping-sharing__members button {
  min-height: 44px;
  border: 0;
  color: var(--color-error);
  background: transparent;
  cursor: pointer;
}
.shopping-sharing__members button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.shopping-sharing__confirmation {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.shopping-sharing__confirmation p {
  width: 100%;
}
.shopping-sharing__error {
  color: var(--color-error);
}
.shopping-sharing__done {
  width: 100%;
  margin-top: var(--space-4);
}
</style>
