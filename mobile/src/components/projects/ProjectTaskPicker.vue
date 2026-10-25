<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { projectTasksApi } from '@/services/projectTasksApi'
import { ApiError } from '@/services/api'
import type { NoteTask } from '@/types/projectProgress'
import { taskError } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    projectId: number
    modelValue: NoteTask[]
    multiple?: boolean
    disabled?: boolean
    label?: string
  }>(),
  { multiple: false, disabled: false, label: 'Alege taskul' },
)
const emit = defineEmits<{ 'update:modelValue': [tasks: NoteTask[]]; accessChanged: [] }>()
const open = ref(false)
const search = ref('')
const tasks = ref<NoteTask[]>([])
const page = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
let sequence = 0
let timer: ReturnType<typeof setTimeout> | undefined
let disposed = false
async function load(more = false): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  try {
    const response = await projectTasksApi.list(props.projectId, {
      search: search.value.trim(),
      page: more ? page.value + 1 : 1,
      per_page: 20,
    })
    if (request !== sequence || disposed) return
    const rows = response.data.map(({ id, title, deleted_at }) => ({ id, title, deleted_at }))
    tasks.value = more
      ? [...new Map([...tasks.value, ...rows].map((task) => [task.id, task])).values()]
      : rows
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
  } catch (failure) {
    if (request === sequence && !disposed) {
      tasks.value = []
      page.value = 0
      lastPage.value = 1
      error.value = taskError(failure)
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
    }
  } finally {
    if (request === sequence && !disposed) loading.value = false
  }
}
function select(task: NoteTask): void {
  if (props.disabled) return
  const selected = props.modelValue.some((value) => value.id === task.id)
  if (selected)
    emit(
      'update:modelValue',
      props.modelValue.filter((value) => value.id !== task.id),
    )
  else if (props.multiple && props.modelValue.length < 100)
    emit('update:modelValue', [...props.modelValue, task])
  else if (!props.multiple) {
    emit('update:modelValue', [task])
    open.value = false
  }
}
watch(open, (value) => {
  if (value && page.value === 0) void load()
})
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    if (open.value) void load()
  }, 300)
})
onBeforeUnmount(() => {
  disposed = true
  sequence++
  clearTimeout(timer)
})
</script>

<template>
  <div class="progress-task-picker">
    <div v-if="modelValue.length" class="progress-task-chips">
      <span v-for="task in modelValue" :key="task.id"
        >{{ task.title }}{{ task.deleted_at ? ' (șters)' : '' }}</span
      >
    </div>
    <details :open="open" @toggle="open = ($event.target as HTMLDetailsElement).open">
      <summary @click="disabled && $event.preventDefault()">
        {{ label }}{{ multiple ? ` (${modelValue.length}/100)` : '' }}
      </summary>
      <fieldset class="task-fields" :disabled="disabled">
        <AppInput v-model="search" label="Caută în taskurile proiectului" :maxlength="255" />
        <p v-if="loading" role="status">Se încarcă taskurile…</p>
        <p v-if="error" class="task-overdue" role="alert">{{ error }}</p>
        <button
          v-for="task in tasks"
          :key="task.id"
          class="progress-task-option"
          type="button"
          :aria-pressed="modelValue.some((value) => value.id === task.id)"
          :disabled="
            disabled ||
            (multiple &&
              modelValue.length >= 100 &&
              !modelValue.some((value) => value.id === task.id))
          "
          @click="select(task)"
        >
          {{ task.title }}
          <span aria-hidden="true">{{
            modelValue.some((value) => value.id === task.id) ? '✓' : '+'
          }}</span>
        </button>
        <p v-if="!loading && !error && !tasks.length" class="projects-muted">
          Niciun task disponibil pentru această căutare.
        </p>
        <AppButton v-if="error" variant="secondary" @click="load()">Reîncearcă</AppButton>
        <AppButton v-if="page < lastPage" variant="secondary" :loading="loading" @click="load(true)"
          >Mai multe taskuri</AppButton
        >
      </fieldset>
    </details>
  </div>
</template>
