<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import PencilIcon from '@/components/icons/pencilIcon.vue'
import ProjectProgressNoteEditor from './ProjectProgressNoteEditor.vue'
import ProgressNoteContent from './ProgressNoteContent.vue'
import ProgressNoteValuesDisplay from './ProgressNoteValuesDisplay.vue'
import { onProgressNoteDeleted, projectProgressApi } from '@/services/projectProgressApi'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { displayDate, taskError, taskStates } from '@/utils/projectTasks'
import type { Project } from '@/types/projects'
import type {
  CalendarReviews,
  CalendarReadRequest,
  ProjectTask,
  TaskOccurrence,
} from '@/types/projectTasks'
import type { ProgressNote } from '@/types/projectProgress'

const props = defineProps<{
  project: Project
  task: ProjectTask
  occurrenceKey: string
  status: TaskOccurrence['status']
  date: string
  timezone: string
  disabled?: boolean
  calendarReviews?: CalendarReviews
}>()
const emit = defineEmits<{
  changed: []
  busy: [value: boolean]
  accessChanged: []
  reviewChanged: [note: ProgressNote]
  calendarRead: [request: CalendarReadRequest]
}>()
const auth = useAuthStore()
const notes = ref<ProgressNote[]>([])
const canCreate = ref(false)
const page = ref(0)
const lastPage = ref(1)
const total = ref(0)
const reviewCount = computed(
  () =>
    props.calendarReviews?.review_context.active_count ??
    (props.task.review_mode !== 'multiple'
      ? notes.value.filter((note) => !note.deleted_at).length
      : Math.max(0, total.value - notes.value.filter((note) => note.deleted_at).length)),
)
const loading = ref(false)
const error = ref('')
const dialog = ref<HTMLDialogElement | null>(null)
const editor = ref(false)
const editing = ref<ProgressNote>()
const saving = ref(false)
const own = computed(() =>
  notes.value.find(
    (note) =>
      note.created_by === auth.user?.id &&
      (!note.deleted_at || props.task.review_mode === 'multiple'),
  ),
)
const canEdit = computed(
  () => props.project.status === 'active' && own.value && !own.value.deleted_at,
)
const titleId = useId()
// Keep the API's slot/access decision, with the Stage 4 UI restriction for To Do.
const mayCreate = computed(
  () =>
    !loading.value &&
    canCreate.value &&
    props.status !== 'pending' &&
    !own.value &&
    props.project.status === 'active',
)
const authorGroups = computed(() => {
  const groups = new Map<number, { name: string; notes: ProgressNote[] }>()
  for (const note of notes.value) {
    if (!groups.has(note.created_by))
      groups.set(note.created_by, {
        name: note.author?.username ?? `Utilizator #${note.created_by}`,
        notes: [],
      })
    groups.get(note.created_by)!.notes.push(note)
  }
  return [...groups.entries()].map(([id, group]) => ({ id, ...group }))
})
function reviewTime(value: string): string {
  return new Date(value).toLocaleString('ro-RO', {
    timeZone: props.timezone,
    dateStyle: 'short',
    timeStyle: 'short',
  })
}
let sequence = 0
// A deletion in Progress can leave this task's mounted review list and API
// eligibility stale. Refresh only the panel containing the deleted review.
const stopNoteDeleted = onProgressNoteDeleted((project, note) => {
  if (props.calendarReviews) return
  if (project !== props.project.id || !notes.value.some((value) => value.id === note)) return
  canCreate.value = false
  void load()
})
async function load(more = false, force = false): Promise<void> {
  if (props.calendarReviews && !more && !force) {
    sequence++
    notes.value = props.calendarReviews.data
    canCreate.value = props.calendarReviews.review_context.can_create
    page.value = props.calendarReviews.meta.current_page
    lastPage.value = props.calendarReviews.meta.last_page
    total.value = props.calendarReviews.meta.total
    loading.value = false
    return
  }
  const request = ++sequence
  loading.value = true
  error.value = ''
  try {
    const response = props.calendarReviews
      ? await new Promise<CalendarReviews>((resolve, reject) =>
          emit('calendarRead', {
            taskId: props.task.id,
            kind: 'reviews',
            key: props.occurrenceKey,
            page: more ? page.value + 1 : 1,
            resolve: (value) => resolve(value as CalendarReviews),
            reject,
          }),
        )
      : await projectProgressApi.reviews(
          props.project.id,
          props.task.id,
          props.occurrenceKey,
          more ? page.value + 1 : 1,
        )
    if (request !== sequence) return
    notes.value = more ? [...notes.value, ...response.data] : response.data
    canCreate.value = response.review_context.can_create
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
    total.value = response.meta.total
  } catch (failure) {
    if (request !== sequence) return
    error.value = taskError(failure)
    canCreate.value = false
    if (failure instanceof ApiError && [403, 404].includes(failure.status)) emit('accessChanged')
  } finally {
    if (request === sequence) loading.value = false
  }
}
function openEditor(note?: ProgressNote): void {
  if (
    props.disabled ||
    saving.value ||
    (note ? !canEdit.value || note.id !== own.value?.id : !mayCreate.value)
  )
    return
  dialog.value?.close()
  editing.value = note
  editor.value = true
}
async function closeEditor(): Promise<void> {
  editor.value = false
  await nextTick()
  dialog.value?.showModal()
}
function busy(value: boolean): void {
  saving.value = value
  emit('busy', value)
}
function saved(note: ProgressNote): void {
  void closeEditor()
  if (props.calendarReviews) emit('reviewChanged', note)
  else {
    void load()
    emit('changed')
  }
}
function editorChanged(): void {
  if (props.calendarReviews) void load(false, true)
  else emit('changed')
}
async function restore(note: ProgressNote): Promise<void> {
  if (props.disabled || saving.value) return
  busy(true)
  try {
    const response = await projectProgressApi.restoreNote(props.project.id, note.id)
    if (props.calendarReviews) emit('reviewChanged', response.data)
    else {
      await load()
      emit('changed')
    }
  } catch (failure) {
    error.value =
      failure instanceof ApiError && failure.status === 409
        ? 'Review-ul nu poate fi restaurat: există deja un review activ sau un conflict de evaluări. Nu a fost suprascris nimic.'
        : taskError(failure)
  } finally {
    busy(false)
  }
}
watch(
  () => [
    props.task.id,
    props.occurrenceKey,
    props.status,
    props.project.status,
    props.task.status,
    props.task.review_mode,
    props.task,
    props.calendarReviews,
  ],
  (value, previous) => {
    const identityChanged = !previous || value[0] !== previous[0] || value[1] !== previous[1]
    if (identityChanged) {
      dialog.value?.close()
      editor.value = false
      editing.value = undefined
      notes.value = []
      total.value = 0
      page.value = 0
      lastPage.value = 1
    }
    canCreate.value = false
    void load()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  stopNoteDeleted()
  sequence++
  dialog.value?.close()
  if (saving.value) emit('busy', false)
})
</script>

<template>
  <span class="task-reviews-actions">
    <button
      type="button"
      class="task-review-icon"
      :disabled="disabled || saving"
      title="Review-uri"
      :aria-label="`Review-uri${reviewCount ? ` (${reviewCount})` : ''}: ${task.title}`"
      :aria-busy="loading"
      aria-haspopup="dialog"
      @click="dialog?.showModal()"
    >
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path
          d="M12 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12M8 3v18M2 7h4M2 12h4M2 17h4M17 3l4 4-7 7-4 1 1-4Z"
        />
      </svg>
      <span v-if="reviewCount" class="task-review-count" aria-hidden="true">{{ reviewCount }}</span>
    </button>
    <Teleport to="body">
      <dialog
        ref="dialog"
        class="task-reviews-dialog"
        :aria-labelledby="titleId"
        @click.self="dialog?.close()"
      >
        <header>
          <h3 :id="titleId">{{ task.title }}</h3>
          <button
            type="button"
            class="task-review-icon"
            aria-label="Închide review-urile"
            autofocus
            @click="dialog?.close()"
          >
            ×
          </button>
        </header>
        <p class="task-reviews-context">
          {{ taskStates[status] }} ·
          {{ task.execution_type === 'one_time' ? 'Execuție unică' : displayDate(occurrenceKey) }}
        </p>
        <p v-if="loading" class="projects-muted" role="status">Se încarcă review-urile…</p>
        <p v-if="error" class="projects-error" role="alert">{{ error }}</p>
        <button
          v-if="error"
          type="button"
          class="task-quick"
          :disabled="loading"
          @click="load(false, true)"
        >
          Reîncarcă review-urile
        </button>
        <section v-for="group in authorGroups" :key="group.id" class="task-review-author">
          <h4>{{ group.name }}<span v-if="group.id === auth.user?.id"> · Tu</span></h4>
          <article
            v-for="note in group.notes"
            :key="note.id"
            :aria-label="`Review de ${group.name}`"
          >
            <header>
              <div class="task-review-meta">
                <time v-if="note.created_at" :datetime="note.created_at">{{
                  reviewTime(note.created_at)
                }}</time>
                <span v-else>Data și ora indisponibile</span>
                <span v-if="note.updated_at && note.updated_at !== note.created_at">
                  · Editat
                  <time :datetime="note.updated_at">{{ reviewTime(note.updated_at) }}</time>
                </span>
                <span v-if="note.deleted_at"> · Șters</span>
              </div>
              <button
                v-if="
                  note.created_by === auth.user?.id &&
                  !note.deleted_at &&
                  project.status === 'active'
                "
                type="button"
                class="task-review-icon"
                :disabled="disabled || saving"
                aria-label="Editează review"
                @click="openEditor(note)"
              >
                <PencilIcon aria-hidden="true" />
              </button>
            </header>
            <p class="task-review-title">{{ note.title }}</p>
            <ProgressNoteContent :content="note.content" />
            <ProgressNoteValuesDisplay :note="note" expanded />
            <p v-for="score in note.evaluations.filter((value) => value.is_active)" :key="score.id">
              Scor: {{ score.score }}/10 · {{ score.evaluation_date }}
            </p>
            <button
              v-if="
                note.deleted_at && note.created_by === auth.user?.id && project.status === 'active'
              "
              type="button"
              class="task-review-icon"
              :disabled="disabled || saving"
              aria-label="Restaurează review-ul"
              title="Restaurează review-ul"
              @click="restore(note)"
            >
              <span aria-hidden="true">↶</span>
            </button>
          </article>
        </section>
        <p v-if="!notes.length && !loading && !error" class="projects-muted">
          Nu există review-uri pentru această apariție.
        </p>
        <button
          v-if="mayCreate"
          type="button"
          class="task-quick"
          :disabled="disabled || saving"
          @click="openEditor()"
        >
          Adaugă review
        </button>
        <button
          v-if="page < lastPage"
          type="button"
          class="task-quick"
          :disabled="loading"
          @click="load(true)"
        >
          Mai multe review-uri
        </button>
      </dialog>
    </Teleport>
    <ProjectProgressNoteEditor
      v-if="editor"
      :project="project"
      :note="editing"
      :initial-tasks="[{ id: task.id, title: task.title, deleted_at: task.deleted_at }]"
      :initial-date="date"
      :timezone="timezone"
      :review-context="{ task_id: task.id, occurrence_key: occurrenceKey }"
      @saved="saved"
      @cancel="closeEditor"
      @busy="busy"
      @changed="editorChanged"
      @access-changed="emit('accessChanged')"
    />
  </span>
</template>

<style scoped>
.task-reviews-actions {
  display: inline-flex;
  flex-shrink: 0;
}
.task-review-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 44px;
  gap: 4px;
  min-height: 44px;
  padding: 8px;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-primary-strong);
  font-size: 20px;
  cursor: pointer;
}
.task-review-count {
  font-size: 0.75rem;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
}
.task-review-icon svg {
  width: 16px;
  height: 16px;
}
.task-review-icon:disabled {
  opacity: 0.5;
}
.task-reviews-dialog {
  width: min(480px, calc(100% - 24px));
  box-sizing: border-box;
  max-height: min(80dvh, 720px);
  margin: auto;
  overscroll-behavior: contain;
  padding: 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  overflow-y: auto;
}
.task-reviews-dialog::backdrop {
  background: rgb(0 0 0 / 35%);
}
.task-reviews-dialog header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.task-reviews-dialog h3 {
  min-width: 0;
  overflow-wrap: anywhere;
  margin: 0;
  font-size: 1rem;
}
.task-review-author {
  padding-block: 10px;
  border-top: 1px solid var(--color-border);
}
.task-review-author h4 {
  margin: 0;
  font-size: 0.85rem;
  overflow-wrap: anywhere;
}
.task-reviews-context,
.task-review-meta {
  margin: 0 0 8px;
  color: var(--color-text-muted);
  font-size: 0.78rem;
  overflow-wrap: anywhere;
}
.task-review-meta {
  margin: 0;
}
.task-review-title {
  margin: 4px 0;
  font-size: 0.88rem;
  font-weight: 600;
}
.task-review-icon:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: -2px;
}
.task-reviews-dialog :deep(.progress-note__content) {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font-size: 0.88rem;
}
.task-reviews-dialog :deep(.progress-note__content > *) {
  margin: 4px 0;
}
.task-reviews-dialog :deep(.progress-note__content > :is(ul, ol)) {
  padding-left: 20px;
}
.task-reviews-dialog article {
  min-width: 0;
  overflow-wrap: anywhere;
}
</style>
