<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/services/api'
import { projectProgressApi } from '@/services/projectProgressApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { Project } from '@/types/projects'
import type { EvaluationRevision, TaskEvaluation } from '@/types/projectProgress'
import { progressAuthor, progressError } from '@/utils/projectProgress'
import { displayDate } from '@/utils/projectTasks'

const props = withDefaults(
  defineProps<{
    project: Project
    evaluation: TaskEvaluation
    taskTitle: string
    locked?: boolean
    taskDeleted?: boolean
  }>(),
  { locked: false, taskDeleted: false },
)
const emit = defineEmits<{ changed: []; busy: [value: boolean]; accessChanged: [] }>()
const auth = useAuthStore()
const notifications = useNotificationStore()
const record = ref(props.evaluation)
const editing = ref(false)
const score = ref(String(record.value.score))
let editVersion = record.value.lock_version
const busy = ref(false)
const error = ref('')
const withdrawn = ref(false)
const revisions = ref<EvaluationRevision[]>([])
const historyOpen = ref(false)
const historyLoading = ref(false)
const historyError = ref('')
const page = ref(0)
const lastPage = ref(1)
let historySequence = 0
const canManage = computed(
  () =>
    props.project.status === 'active' &&
    record.value.is_active &&
    !record.value.withdrawn_at &&
    !withdrawn.value &&
    !props.taskDeleted &&
    (props.project.created_by === auth.user?.id || record.value.created_by === auth.user?.id),
)
function setBusy(value: boolean): void {
  busy.value = value
  emit('busy', value)
}
function edit(): void {
  score.value = String(record.value.score)
  editVersion = record.value.lock_version
  editing.value = true
  error.value = ''
}
async function mutate(withdraw = false): Promise<void> {
  if (busy.value || props.locked || !canManage.value) return
  const version = withdraw ? record.value.lock_version : editVersion
  setBusy(true)
  error.value = ''
  try {
    if (withdraw) {
      if (
        !(await notifications.confirm({
          title: 'Retragi evaluarea?',
          message: 'Scorul nu va mai contribui la progres. Reviziile se păstrează.',
          confirmText: 'Retrage',
        }))
      )
        return
      await projectProgressApi.withdrawEvaluation(
        props.project.id,
        record.value.task_id,
        record.value.id,
        version,
      )
      withdrawn.value = true
    } else
      record.value = (
        await projectProgressApi.updateEvaluation(
          props.project.id,
          record.value.task_id,
          record.value.id,
          { score: Number(score.value), lock_version: version },
        )
      ).data
    editing.value = false
    if (historyOpen.value) void loadHistory()
    emit('changed')
  } catch (failure) {
    error.value = progressError(failure)
    if (failure instanceof ApiError && failure.status === 409) {
      editing.value = false
      try {
        record.value = (
          await projectProgressApi.evaluation(
            props.project.id,
            record.value.task_id,
            record.value.id,
          )
        ).data
      } catch {
        emit('accessChanged')
      }
      emit('changed')
    } else if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
      emit('accessChanged')
    }
  } finally {
    setBusy(false)
  }
}
async function loadHistory(more = false): Promise<void> {
  const request = ++historySequence
  historyLoading.value = true
  historyError.value = ''
  try {
    const response = await projectProgressApi.revisions(
      props.project.id,
      record.value.task_id,
      record.value.id,
      more ? page.value + 1 : 1,
    )
    if (request !== historySequence) return
    revisions.value = more ? [...revisions.value, ...response.data] : response.data
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
  } catch (failure) {
    if (request === historySequence) {
      historyError.value = progressError(failure)
      revisions.value = []
      page.value = 0
      lastPage.value = 1
      if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
    }
  } finally {
    if (request === historySequence) historyLoading.value = false
  }
}
function toggleHistory(): void {
  historyOpen.value = !historyOpen.value
  if (historyOpen.value && page.value === 0) void loadHistory()
}
watch(
  () => props.evaluation,
  (value) => {
    record.value = value
  },
)
onBeforeUnmount(() => {
  historySequence++
  if (busy.value) emit('busy', false)
})
</script>

<template>
  <div class="progress-evaluation">
    <div class="progress-evaluation__header">
      <strong>{{ taskTitle }} · {{ record.score }}/10</strong
      ><span>{{ displayDate(record.evaluation_date) }}</span>
    </div>
    <p class="projects-muted">
      {{ progressAuthor(record.created_by, project, auth.user)
      }}{{
        withdrawn || record.withdrawn_at ? ' · Retrasă' : !record.is_active ? ' · Suspendată' : ''
      }}
    </p>
    <p v-if="error" class="projects-error" role="alert">{{ error }}</p>
    <form v-if="editing && canManage" class="progress-inline-form" @submit.prevent="mutate()">
      <label
        >Scor nou (1–10)<input
          v-model="score"
          type="number"
          min="1"
          max="10"
          step="1"
          required
          :disabled="busy"
      /></label>
      <div class="task-buttons">
        <AppButton type="submit" :loading="busy">Actualizează explicit</AppButton
        ><button class="task-quick" type="button" :disabled="busy" @click="editing = false">
          Renunță
        </button>
      </div>
    </form>
    <div class="task-buttons">
      <button
        v-if="canManage && !editing"
        class="progress-text-action"
        type="button"
        :disabled="locked || busy"
        @click="edit"
      >
        Editează scorul</button
      ><button
        v-if="canManage"
        class="progress-text-action"
        type="button"
        :disabled="locked || busy"
        @click="mutate(true)"
      >
        Retrage</button
      ><button
        v-if="!taskDeleted"
        class="progress-text-action"
        type="button"
        :disabled="busy"
        :aria-expanded="historyOpen"
        @click="toggleHistory"
      >
        Revizii
      </button>
    </div>
    <div v-if="historyOpen" class="progress-revisions">
      <p v-if="historyLoading" role="status">Se încarcă reviziile…</p>
      <p v-if="historyError" role="alert">{{ historyError }}</p>
      <ul>
        <li v-for="revision in revisions" :key="revision.id">
          {{ revision.previous_score ?? '—' }} → {{ revision.new_score ?? 'retras' }} ·
          {{ progressAuthor(revision.actor_id, project, auth.user) }} ·
          {{ new Date(revision.recorded_at).toLocaleString('ro-RO') }}
        </li>
      </ul>
      <p v-if="!historyLoading && !historyError && !revisions.length" class="projects-muted">
        Nu există revizii vizibile.
      </p>
      <button v-if="historyError" class="task-quick" type="button" @click="loadHistory()">
        Reîncearcă</button
      ><AppButton
        v-if="page < lastPage"
        variant="secondary"
        :loading="historyLoading"
        @click="loadHistory(true)"
        >Mai multe revizii</AppButton
      >
    </div>
  </div>
</template>
