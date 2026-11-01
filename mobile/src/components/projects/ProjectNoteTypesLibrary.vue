<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import ProjectNoteTypeFields from './ProjectNoteTypeFields.vue'
import ProjectNoteTypeEditor from './ProjectNoteTypeEditor.vue'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import { projectError } from '@/utils/projectPresentation'
import { useAuthStore } from '@/stores/auth'
import type { Project } from '@/types/projects'
import type { ProjectNoteType } from '@/types/projectNoteConfiguration'

const props = defineProps<{ project: Project }>()
const emit = defineEmits<{ definitionsChanged: [] }>()
const inspecting = ref(new Set<number>())
const associationVersion = ref(0)
function inspect(type: ProjectNoteType, event: Event): void {
  if ((event.target as HTMLDetailsElement).open) inspecting.value.add(type.id)
}
const auth = useAuthStore()
const canManage = computed(
  () => props.project.created_by === auth.user?.id && props.project.status === 'active',
)
const types = ref<ProjectNoteType[]>([])
const loading = ref(true)
const error = ref('')
const mutationError = ref('')
const busy = ref(false)
const creating = ref(false)
const editing = ref<ProjectNoteType>()
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const headingId = useId()
const activeCount = computed(() => types.value.filter((type) => !type.archived_at).length)
let sequence = 0
let disposed = false
async function load(): Promise<void> {
  const request = ++sequence
  const projectId = props.project.id
  loading.value = true
  error.value = ''
  try {
    const response = await projectNoteConfigurationApi.noteTypes(projectId)
    if (!disposed && request === sequence) types.value = response.data
  } catch (failure) {
    if (!disposed && request === sequence)
      error.value = projectError(failure, 'Nu am putut încărca tipurile de note. Încearcă din nou.')
  } finally {
    if (!disposed && request === sequence) loading.value = false
  }
}
function closeEditor(): void {
  creating.value = false
  editing.value = undefined
}
function closeMenu(event?: Event): void {
  const menu = (event?.currentTarget as HTMLElement | undefined)?.closest('details')
  if (menu) menu.open = false
}
function openEditor(type?: ProjectNoteType, event?: Event): void {
  closeMenu(event)
  if (!canManage.value || busy.value || loading.value) return
  editing.value = type
  creating.value = !type
}
function saved(): void {
  closeEditor()
  mutationError.value = ''
  void load()
}
function canArchive(type: ProjectNoteType): boolean {
  return !type.archived_at && !type.is_default && activeCount.value > 1
}
async function mutate(
  type: ProjectNoteType,
  action: 'default' | 'archive' | 'restore',
  event?: Event,
): Promise<void> {
  closeMenu(event)
  if (!canManage.value || busy.value || loading.value || type.project_id !== props.project.id)
    return
  if (action === 'archive' && !canArchive(type)) return
  if (action === 'default' && (type.archived_at || type.is_default)) return
  const projectId = props.project.id
  busy.value = true
  mutationError.value = ''
  try {
    if (
      action === 'archive' &&
      !(await confirmation.value?.ask({
        title: 'Arhivezi tipul de notă?',
        message:
          'După integrarea în editor, tipul nu va mai fi disponibil pentru observații noi. Configurația și istoricul se păstrează; îl poți restaura.',
        cancelText: 'Anulează',
        confirmText: 'Arhivează',
      }))
    )
      return
    if (disposed || projectId !== props.project.id || !canManage.value) return
    if (action === 'default')
      await projectNoteConfigurationApi.setDefaultNoteType(projectId, type.id)
    else if (action === 'archive')
      await projectNoteConfigurationApi.archiveNoteType(projectId, type.id)
    else await projectNoteConfigurationApi.restoreNoteType(projectId, type.id)
    if (!disposed && projectId === props.project.id) await load()
  } catch (failure) {
    if (!disposed && projectId === props.project.id)
      mutationError.value =
        failure instanceof ApiError
          ? `${projectError(failure, failure.message)} ${Object.values(failure.errors).flat().join(' ')}`.trim()
          : 'Nu am putut modifica tipul de notă. Verifică lista și încearcă din nou.'
  } finally {
    busy.value = false
  }
}
watch(
  () => props.project.id,
  () => {
    closeEditor()
    types.value = []
    inspecting.value.clear()
    mutationError.value = ''
    void load()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  disposed = true
  sequence++
})
</script>
<template>
  <section class="note-types-library" :aria-labelledby="headingId" :aria-busy="loading">
    <header class="note-types-library__heading">
      <div>
        <h2 :id="headingId">Tipuri de note</h2>
        <span
          v-if="!loading && !error"
          class="note-types-library__count"
          aria-label="Număr de tipuri"
          >{{ types.length }}</span
        >
      </div>
      <button
        v-if="canManage"
        type="button"
        aria-label="Adaugă tip de notă"
        :disabled="loading || busy"
        @click="openEditor()"
      >
        + Tip
      </button>
    </header>
    <p v-if="mutationError" class="projects-error" role="alert">{{ mutationError }}</p>
    <p v-if="loading" class="note-types-library__state" role="status">
      Se încarcă tipurile de note…
    </p>
    <div v-else-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="load">Reîncearcă</AppButton>
    </div>
    <p v-else-if="!types.length" class="note-types-library__state">
      Proiectul nu are încă tipuri de note.
    </p>
    <ul v-else class="note-types-library__list">
      <li
        v-for="type in types"
        :key="type.id"
        class="note-type"
        :class="{ 'note-type--archived': type.archived_at !== null }"
      >
        <div class="note-type__body">
          <div class="note-type__heading">
            <h3>{{ type.name }}</h3>
            <span v-if="type.is_default" class="note-type__default">Implicit</span
            ><span v-if="type.archived_at" class="note-type__archived">Arhivat</span>
          </div>
          <p v-if="type.description" class="note-type__description">{{ type.description }}</p>
          <details v-if="type.template" class="note-type__template">
            <summary :aria-label="`Vezi șablonul ${type.name}`">Șablon text</summary>
            <p>{{ type.template }}</p>
          </details>
          <details class="note-type__template" @toggle="inspect(type, $event)">
            <summary :aria-label="`Vezi câmpurile ${type.name}`">Câmpuri asociate</summary>
            <ProjectNoteTypeFields
              v-if="inspecting.has(type.id)"
              :key="`${type.id}-${associationVersion}`"
              :project="project"
              :note-type="type"
              read-only
            />
          </details>
        </div>
        <details v-if="canManage" class="note-type__actions">
          <summary
            :aria-label="`Acțiuni tip ${type.name}`"
            @click="busy && $event.preventDefault()"
          >
            •••
          </summary>
          <div>
            <button
              type="button"
              :aria-label="`Editează tipul ${type.name}`"
              :disabled="busy"
              @click="openEditor(type, $event)"
            >
              Editează
            </button>
            <button
              v-if="!type.archived_at && !type.is_default"
              type="button"
              :aria-label="`Setează implicit ${type.name}`"
              :disabled="busy"
              @click="mutate(type, 'default', $event)"
            >
              Setează implicit
            </button>
            <button
              v-if="canArchive(type)"
              type="button"
              :aria-label="`Arhivează tipul ${type.name}`"
              :disabled="busy"
              @click="mutate(type, 'archive', $event)"
            >
              Arhivează
            </button>
            <button
              v-if="type.archived_at"
              type="button"
              :aria-label="`Restaurează tipul ${type.name}`"
              :disabled="busy"
              @click="mutate(type, 'restore', $event)"
            >
              Restaurează
            </button>
          </div>
        </details>
      </li>
    </ul>
    <ProjectNoteTypeEditor
      v-if="creating || editing"
      :key="editing?.id ?? 'create'"
      :project="project"
      :note-type="editing"
      @saved="saved"
      @cancel="closeEditor"
      @uncertain="load"
      @definitions-changed="emit('definitionsChanged')"
      @associations-changed="associationVersion++"
    />
    <ProgressNoteConfirmDialog v-if="canManage" ref="confirmation" class="note-types-confirm" />
  </section>
</template>
<style scoped>
.note-types-library {
  display: grid;
  gap: var(--space-3);
  min-width: 0;
  text-align: left;
  margin-bottom: 20px;
}
.note-types-library__heading,
.note-types-library__heading > div {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.note-types-library__heading {
  justify-content: space-between;
}
.note-types-library__heading h2 {
  margin: 0;
  font-size: 1.05rem;
}
.note-types-library__heading button {
  flex: none;
  min-height: 44px;
  padding: 6px 8px;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-primary-strong);
  font: inherit;
  font-size: 0.8rem;
  font-weight: 650;
  cursor: pointer;
}
.note-types-library__count {
  padding: 3px 8px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-soft);
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
.note-types-library__state {
  margin: 0;
  padding: 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: 0.85rem;
}
.note-types-library__list {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.note-type {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  min-width: 0;
  padding: 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
}
.note-type--archived {
  border-style: dashed;
  background: var(--color-surface-soft);
}
.note-type__body {
  flex: 1;
  display: grid;
  gap: 6px;
  min-width: 0;
}
.note-type__heading {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px;
}
.note-type h3 {
  margin: 0;
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.note-type__default,
.note-type__archived {
  padding: 2px 7px;
  border-radius: var(--radius-pill);
  background: var(--color-border);
  font-size: 0.7rem;
}
.note-type__default {
  background: var(--color-primary-soft);
  color: var(--color-primary-strong);
}
.note-type__description,
.note-type__template p {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font-size: 0.82rem;
  line-height: 1.5;
}
.note-type__description {
  color: var(--color-text-muted);
}
.note-type__template summary {
  display: flex;
  align-items: center;
  min-height: 44px;
  color: var(--color-primary-strong);
  font-size: 0.8rem;
  cursor: pointer;
}
.note-type__template summary::before {
  content: '›';
  margin-right: 6px;
}
.note-type__template[open] summary::before {
  transform: rotate(90deg);
}
.note-type__actions {
  position: relative;
  flex: none;
  margin: -6px -6px 0 0;
}
.note-type__actions summary {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  list-style: none;
  color: var(--color-primary-strong);
  cursor: pointer;
}
.note-type__actions summary::-webkit-details-marker {
  display: none;
}
.note-type__actions > div {
  position: absolute;
  right: 0;
  z-index: 2;
  display: grid;
  min-width: 160px;
  padding: 4px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}
.note-type__actions button {
  width: 100%;
  min-height: 44px;
  padding: 6px 10px;
  border: 0;
  background: transparent;
  color: var(--color-text);
  text-align: left;
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}
.note-types-library button:disabled {
  opacity: 0.5;
  cursor: default;
}
.note-types-library :deep(.app-button) {
  min-height: 44px;
  padding-inline: 12px;
  font-size: 0.85rem;
}
.note-types-confirm {
  box-sizing: border-box;
  width: min(420px, calc(100% - 24px));
  max-height: 90dvh;
  overflow-y: auto;
  margin: auto;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  text-align: left;
}
.note-types-confirm::backdrop {
  background: var(--color-overlay);
}
.note-types-confirm :deep(h3) {
  margin: 0 0 8px;
  font-size: 1rem;
}
.note-types-confirm :deep(p) {
  margin: 0 0 12px;
  font-size: 0.85rem;
  line-height: 1.4;
}
.note-types-confirm :deep(.note-editor-confirm__actions) {
  display: flex;
  flex-wrap: wrap;
  justify-content: end;
  gap: 8px;
}
</style>
