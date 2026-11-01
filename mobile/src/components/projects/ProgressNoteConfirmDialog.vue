<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'

const titleId = useId()
const messageId = useId()
const dialog = ref<HTMLDialogElement | null>(null)
const options = ref({ title: '', message: '', confirmText: '', cancelText: '' })
let resolve: ((answer: boolean) => void) | undefined
let pending: Promise<boolean> | undefined

function ask(value: typeof options.value): Promise<boolean> {
  if (pending) return pending
  options.value = value
  pending = new Promise<boolean>((answer) => {
    resolve = answer
  })
  void nextTick(() => dialog.value?.showModal())
  return pending
}
function answer(value: boolean): void {
  dialog.value?.close()
  const finish = resolve
  resolve = undefined
  pending = undefined
  finish?.(value)
}
onBeforeUnmount(() => answer(false))
defineExpose({ ask })
</script>

<template>
  <dialog
    ref="dialog"
    class="note-editor-confirm"
    :aria-labelledby="titleId"
    :aria-describedby="messageId"
    @cancel.prevent="answer(false)"
    @click.self="answer(false)"
  >
    <h3 :id="titleId">{{ options.title }}</h3>
    <p :id="messageId">{{ options.message }}</p>
    <div class="note-editor-confirm__actions">
      <AppButton variant="secondary" autofocus @click="answer(false)">{{
        options.cancelText
      }}</AppButton>
      <AppButton @click="answer(true)">{{ options.confirmText }}</AppButton>
    </div>
  </dialog>
</template>
