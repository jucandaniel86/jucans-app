<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import ProjectNoteTypeFields from './ProjectNoteTypeFields.vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { Project } from '@/types/projects'
import type {
  ProjectNoteType,
  UpdateProjectNoteTypePayload,
} from '@/types/projectNoteConfiguration'

const props = defineProps<{ project: Project; noteType?: ProjectNoteType }>()
const emit = defineEmits<{
  saved: [noteType: ProjectNoteType]
  cancel: []
  uncertain: []
  definitionsChanged: []
  associationsChanged: []
}>()
const auth = useAuthStore()
const initial = props.noteType
const associations = ref<InstanceType<typeof ProjectNoteTypeFields> | null>(null)
const id = useId()
const dialog = ref<HTMLDialogElement | null>(null)
const formElement = ref<HTMLFormElement | null>(null)
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const busy = ref(false)
const complete = ref(false)
const denied = ref(false)
const unknownCreate = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const form = reactive({
  name: initial?.name ?? '',
  description: initial?.description ?? '',
  template: initial?.template ?? '',
})
const baseline = JSON.stringify(form)
const dirty = computed(
  () =>
    !complete.value &&
    (JSON.stringify(form) !== baseline || unknownCreate.value || associations.value?.unsettled),
)
const permitted = computed(
  () =>
    !denied.value &&
    props.project.status === 'active' &&
    props.project.created_by === auth.user?.id &&
    (!initial || initial.project_id === props.project.id),
)
async function focusError(): Promise<void> {
  await nextTick()
  const target = formElement.value?.querySelector<HTMLElement>(
    '[aria-invalid="true"], [role="alert"]',
  )
  target?.focus()
  target?.scrollIntoView({ block: 'nearest' })
}
async function save(): Promise<void> {
  if (
    busy.value ||
    associations.value?.blocking ||
    associations.value?.unsettled ||
    complete.value ||
    !permitted.value ||
    unknownCreate.value
  )
    return
  errors.value = {}
  error.value = ''
  if (!form.name.trim()) errors.value.name = ['Completează numele tipului.']
  else if (form.name.trim().length > 255) errors.value.name = ['Maximum 255 de caractere.']
  if (form.description.length > 10000) errors.value.description = ['Maximum 10.000 de caractere.']
  if (form.template.length > 100000) errors.value.template = ['Maximum 100.000 de caractere.']
  if (Object.keys(errors.value).length) {
    await focusError()
    return
  }
  busy.value = true
  try {
    // Preserve template whitespace and only PATCH intended metadata changes.
    const data = {
      name: form.name.trim(),
      description: form.description || null,
      template: form.template || null,
    }
    const patch = initial
      ? (Object.fromEntries(
          Object.entries(data).filter(
            ([key, value]) => value !== initial[key as keyof ProjectNoteType],
          ),
        ) as UpdateProjectNoteTypePayload)
      : data
    const response = initial
      ? await projectNoteConfigurationApi.updateNoteType(props.project.id, initial.id, patch)
      : await projectNoteConfigurationApi.createNoteType(props.project.id, data)
    complete.value = true
    emit('saved', response.data)
  } catch (failure) {
    if (failure instanceof ApiError) {
      errors.value = failure.errors
      denied.value = failure.status === 403
      error.value =
        failure.status === 403
          ? 'Nu ai permisiunea să modifici tipurile acestui proiect.'
          : failure.message
    } else error.value = 'Nu am putut salva tipul de notă. Încearcă din nou.'
    if (
      !initial &&
      (!(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500)
    ) {
      unknownCreate.value = true
      error.value =
        'Crearea nu este confirmată. Închide și verifică lista înainte de a crea alt tip.'
      emit('uncertain')
    }
    await focusError()
  } finally {
    busy.value = false
  }
}
async function mayLeave(): Promise<boolean> {
  if (busy.value || associations.value?.blocking) return false
  if (!dirty.value) return true
  return (
    (await confirmation.value?.ask({
      title: 'Renunți la modificări?',
      message: unknownCreate.value
        ? 'Crearea nu este confirmată. Verifică lista înainte de a crea alt tip.'
        : 'Datele nesalvate și ordinea în așteptare vor fi abandonate. Asocierile deja salvate și câmpurile create se păstrează.',
      cancelText: 'Continuă editarea',
      confirmText: 'Renunță la modificări',
    })) ?? false
  )
}
async function close(): Promise<void> {
  if (await mayLeave()) emit('cancel')
}
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate(async () => {
  const allowed = await mayLeave()
  if (allowed) emit('cancel')
  return allowed
})
function beforeUnload(event: BeforeUnloadEvent): void {
  if (busy.value || associations.value?.blocking || dirty.value) {
    event.preventDefault()
    event.returnValue = ''
  }
}
const previousFocus = document.activeElement as HTMLElement | null
const scrollY = window.scrollY
const previousBody = {
  position: document.body.style.position,
  top: document.body.style.top,
  width: document.body.style.width,
}
let focusTimer: ReturnType<typeof setTimeout> | undefined
function revealInput(): void {
  const target = document.activeElement
  if (
    target instanceof HTMLElement &&
    dialog.value?.contains(target) &&
    target.matches('input, textarea')
  )
    target.scrollIntoView({ block: 'nearest' })
}
function updateViewport(): void {
  const viewport = window.visualViewport
  if (viewport && viewport.scale === 1) {
    dialog.value?.style.setProperty('--note-type-viewport-height', `${viewport.height}px`)
    dialog.value?.style.setProperty('--note-type-viewport-top', `${viewport.offsetTop}px`)
  } else {
    dialog.value?.style.removeProperty('--note-type-viewport-height')
    dialog.value?.style.removeProperty('--note-type-viewport-top')
  }
  void nextTick(revealInput)
}
function focusInput(): void {
  clearTimeout(focusTimer)
  focusTimer = setTimeout(revealInput, 250)
}
onMounted(() => {
  document.body.style.position = 'fixed'
  document.body.style.top = `-${scrollY}px`
  document.body.style.width = '100%'
  dialog.value?.showModal()
  updateViewport()
  window.visualViewport?.addEventListener('resize', updateViewport)
  window.visualViewport?.addEventListener('scroll', updateViewport)
  window.addEventListener('beforeunload', beforeUnload)
})
onBeforeUnmount(() => {
  clearTimeout(focusTimer)
  window.visualViewport?.removeEventListener('resize', updateViewport)
  window.visualViewport?.removeEventListener('scroll', updateViewport)
  window.removeEventListener('beforeunload', beforeUnload)
  dialog.value?.close()
  Object.assign(document.body.style, previousBody)
  window.scrollTo(0, scrollY)
  void nextTick(() => {
    if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true })
  })
})
</script>
<template>
  <Teleport to="body">
    <dialog
      ref="dialog"
      class="note-type-editor"
      :aria-labelledby="`${id}-title`"
      @cancel.prevent="close"
      @click.self="close"
      @focusin="focusInput"
    >
      <header class="note-type-editor__header">
        <h2 :id="`${id}-title`">{{ initial ? 'Editează tipul de notă' : 'Adaugă tip de notă' }}</h2>
        <button
          type="button"
          autofocus
          aria-label="Închide editorul de tip"
          :disabled="busy || associations?.blocking"
          @click="close"
        >
          ×
        </button>
      </header>
      <form
        :id="`${id}-form`"
        ref="formElement"
        class="note-type-editor__body"
        novalidate
        @submit.prevent="save"
      >
        <p v-if="error" role="alert" tabindex="-1">{{ error }}</p>
        <fieldset :disabled="busy || associations?.blocking || !permitted || unknownCreate">
          <AppInput
            v-model="form.name"
            label="Nume *"
            :maxlength="255"
            :error="errors.name?.[0]"
            data-field="name"
          />
          <AppTextarea
            v-model="form.description"
            label="Descriere (opțional)"
            :rows="2"
            :maxlength="10000"
            :error="errors.description?.[0]"
            data-field="description"
          />
          <AppTextarea
            v-model="form.template"
            label="Șablon text (opțional)"
            :rows="5"
            :maxlength="100000"
            :error="errors.template?.[0]"
            data-field="template"
          />
        </fieldset>
        <ProjectNoteTypeFields
          v-if="initial"
          ref="associations"
          :project="project"
          :note-type="initial"
          :disabled="busy || denied"
          @definitions-changed="emit('definitionsChanged')"
          @changed="emit('associationsChanged')"
        />
        <p v-else class="note-type-editor__fields-hint">
          Salvează tipul de notă, apoi deschide-l pentru a asocia câmpuri.
        </p>
      </form>
      <footer class="note-type-editor__footer">
        <AppButton
          type="submit"
          :form="`${id}-form`"
          :loading="busy"
          :disabled="
            !permitted || unknownCreate || associations?.blocking || associations?.unsettled
          "
          >Salvează tipul</AppButton
        >
      </footer>
      <ProgressNoteConfirmDialog ref="confirmation" />
    </dialog>
  </Teleport>
</template>
<style scoped>
.note-type-editor {
  position: fixed;
  left: 0;
  right: 0;
  bottom: auto;
  top: calc(var(--note-type-viewport-top, 0px) + var(--note-type-viewport-height, 100dvh) * 0.5);
  transform: translateY(-50%);
  box-sizing: border-box;
  width: min(560px, calc(100% - 16px));
  height: auto;
  max-width: none;
  max-height: min(
    90dvh,
    calc(
      var(--note-type-viewport-height, 100dvh) - 16px - env(safe-area-inset-top, 0px) -
        env(safe-area-inset-bottom, 0px)
    )
  );
  margin: 0 auto;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  text-align: left;
}
.note-type-editor[open] {
  display: flex;
  flex-direction: column;
}
.note-type-editor::backdrop,
.note-type-editor :deep(.note-editor-confirm::backdrop) {
  background: var(--color-overlay);
}
.note-type-editor__header {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: space-between;
  min-height: 52px;
  gap: 8px;
  padding: 4px 12px;
  border-bottom: 1px solid var(--color-border);
}
.note-type-editor__header h2 {
  margin: 0;
  font-size: 1rem;
}
.note-type-editor__header button {
  width: 44px;
  height: 44px;
  flex: none;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.5rem;
  cursor: pointer;
}
.note-type-editor__body {
  flex: 1;
  min-height: 0;
  min-width: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 12px;
}
.note-type-editor fieldset {
  display: grid;
  gap: 12px;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}
.note-type-editor :deep(.app-input),
.note-type-editor :deep(.app-textarea) {
  min-width: 0;
  gap: 4px;
}
.note-type-editor :deep(label) {
  padding: 0;
  font-size: 0.8rem;
  font-weight: 650;
}
.note-type-editor :deep(.app-input__control),
.note-type-editor :deep(.app-textarea__control) {
  box-sizing: border-box;
  min-width: 0;
  min-height: 44px;
  width: 100%;
  padding: 8px 10px;
  font: inherit;
  font-size: 16px;
  border-radius: var(--radius-sm);
}
.note-type-editor :deep(textarea) {
  resize: vertical;
}
.note-type-editor [role='alert'] {
  margin: 0 0 10px;
  color: var(--color-error);
  font-size: 0.82rem;
  overflow-wrap: anywhere;
}
.note-type-editor__fields-hint {
  margin: 12px 0 0;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}
.note-type-editor__footer {
  display: flex;
  flex: none;
  justify-content: flex-end;
  padding: 8px 12px max(8px, env(safe-area-inset-bottom, 0px));
  border-top: 1px solid var(--color-border);
}
.note-type-editor :deep(.app-button) {
  min-height: 44px;
  padding-inline: 12px;
  font-size: 0.85rem;
  box-shadow: none;
}
.note-type-editor :deep(.note-editor-confirm) {
  box-sizing: border-box;
  width: min(400px, calc(100% - 24px));
  max-height: 90dvh;
  overflow-y: auto;
  margin: auto;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.note-type-editor :deep(.note-editor-confirm h3) {
  margin: 0 0 8px;
  font-size: 1rem;
}
.note-type-editor :deep(.note-editor-confirm p) {
  margin: 0 0 12px;
  font-size: 0.85rem;
  line-height: 1.4;
}
.note-type-editor :deep(.note-editor-confirm__actions) {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}
</style>
