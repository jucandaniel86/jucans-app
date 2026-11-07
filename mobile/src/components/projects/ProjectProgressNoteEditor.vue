<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectProgressNoteForm from './ProjectProgressNoteForm.vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import type { Project } from '@/types/projects'
import type { NoteTask, ProgressNote, TaskReviewContext } from '@/types/projectProgress'

const props = defineProps<{
  project: Project
  note?: ProgressNote
  initialTasks?: NoteTask[]
  initialDate?: string
  reviewContext?: TaskReviewContext
  timezone?: string
}>()
const emit = defineEmits<{
  saved: []
  changed: []
  cancel: []
  busy: [value: boolean]
  accessChanged: []
}>()
const dialog = ref<HTMLDialogElement | null>(null)
const form = ref<InstanceType<typeof ProjectProgressNoteForm> | null>(null)
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const titleId = useId()
const saving = computed(() => form.value?.busy ?? false)
const headerSaveLabel = computed(() =>
  form.value?.saveLabel === 'Reîncearcă evaluările' ? 'Reîncearcă' : 'Salvează',
)
const previousFocus = document.activeElement as HTMLElement | null
const scrollY = window.scrollY
const previousBody = {
  position: document.body.style.position,
  top: document.body.style.top,
  width: document.body.style.width,
}
let focusTimer: ReturnType<typeof setTimeout> | undefined
let disposed = false

async function mayLeave(): Promise<boolean> {
  if (saving.value) return false
  if (!form.value?.dirty) return true
  return (
    (await confirmation.value?.ask({
      title: 'Renunți la modificări?',
      message: form.value.closeMessage,
      confirmText: 'Renunță la modificări',
      cancelText: 'Continuă editarea',
    })) ?? false
  )
}
async function requestClose(): Promise<void> {
  if (await mayLeave()) emit('cancel')
}
function beforeUnload(event: BeforeUnloadEvent): void {
  if (saving.value || form.value?.dirty) {
    event.preventDefault()
    event.returnValue = ''
  }
}
function revealInput(): void {
  const target = document.activeElement
  if (
    target instanceof HTMLElement &&
    dialog.value?.contains(target) &&
    target.matches('input, textarea, select')
  )
    target.scrollIntoView({ block: 'nearest' })
}
function focusInput(event: FocusEvent): void {
  if (!(event.target instanceof HTMLElement) || !event.target.matches('input, textarea, select'))
    return
  clearTimeout(focusTimer)
  focusTimer = setTimeout(revealInput, 250)
}
function updateViewport(): void {
  const viewport = window.visualViewport
  // Stay within the keyboard-visible viewport, without fighting pinch zoom.
  if (viewport && viewport.scale === 1) {
    dialog.value?.style.setProperty('--note-viewport-height', `${viewport.height}px`)
    dialog.value?.style.setProperty('--note-viewport-top', `${viewport.offsetTop}px`)
  } else {
    dialog.value?.style.removeProperty('--note-viewport-height')
    dialog.value?.style.removeProperty('--note-viewport-top')
  }
  void nextTick(() => {
    if (!disposed) revealInput()
  })
}
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate(mayLeave)
onMounted(() => {
  // Match the established shopping-list overlay scroll lock, including iOS Safari.
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
  disposed = true
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
  emit('busy', false)
})
defineExpose({ requestClose })
</script>

<template>
  <Teleport to="body">
    <dialog
      ref="dialog"
      class="note-editor"
      :aria-labelledby="titleId"
      @cancel.prevent="requestClose"
      @click.self="requestClose"
      @focusin="focusInput"
    >
      <div class="note-editor-workspace">
        <header class="note-editor-header">
          <button
            class="note-editor-close"
            type="button"
            autofocus
            aria-label="Închide editorul"
            :disabled="saving"
            @click="requestClose"
          >
            ×
          </button>
          <h2 :id="titleId">
            {{
              note?.review || reviewContext
                ? note
                  ? 'Editează review'
                  : 'Adaugă review'
                : note
                  ? 'Editează nota'
                  : 'Adaugă notă'
            }}
          </h2>
          <AppButton
            class="note-editor-header-save"
            :loading="saving"
            :disabled="form?.saveDisabled"
            @click="form?.save()"
            >{{ headerSaveLabel }}</AppButton
          >
        </header>
        <div class="note-editor-body">
          <ProjectProgressNoteForm
            ref="form"
            v-bind="props"
            @saved="emit('saved')"
            @changed="emit('changed')"
            @busy="emit('busy', $event)"
            @access-changed="emit('accessChanged')"
          />
        </div>
        <footer class="note-editor-footer">
          <AppButton :loading="saving" :disabled="form?.saveDisabled" @click="form?.save()">{{
            form?.saveLabel ?? 'Salvează nota'
          }}</AppButton>
        </footer>
      </div>
      <ProgressNoteConfirmDialog ref="confirmation" />
    </dialog>
  </Teleport>
</template>

<style src="./projectProgressNoteEditor.css"></style>
