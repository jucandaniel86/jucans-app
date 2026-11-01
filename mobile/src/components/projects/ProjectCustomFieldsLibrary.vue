<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import ProgressNoteConfirmDialog from './ProgressNoteConfirmDialog.vue'
import { ApiError } from '@/services/api'
import ProjectCustomFieldEditor from './ProjectCustomFieldEditor.vue'
import ProjectSelectOptionsEditor from './ProjectSelectOptionsEditor.vue'
import { useAuthStore } from '@/stores/auth'
import type { Project } from '@/types/projects'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import type {
  ProjectCustomField,
  ProjectCustomFieldType,
  ProjectNumericAggregation,
} from '@/types/projectNoteConfiguration'
import { projectError } from '@/utils/projectPresentation'

const props = defineProps<{ projectId: number; project?: Project }>()
const auth = useAuthStore()
const canManage = computed(
  () =>
    props.project?.id === props.projectId &&
    props.project?.created_by === auth.user?.id &&
    props.project?.status === 'active',
)
const creating = ref(false)
const mutating = ref(false)
const mutationError = ref('')
const confirmation = ref<InstanceType<typeof ProgressNoteConfirmDialog> | null>(null)
const editingField = ref<ProjectCustomField>()
const inspecting = ref(new Set<number>())
function inspect(field: ProjectCustomField, event?: Event): void {
  if (event) closeMenu(event)
  if (inspecting.value.has(field.id)) inspecting.value.delete(field.id)
  else inspecting.value.add(field.id)
}
function closeEditor(): void {
  creating.value = false
  editingField.value = undefined
}
function closeMenu(event: Event): void {
  const menu = (event.currentTarget as HTMLElement).closest('details')
  if (menu) menu.open = false
}
function openEditor(field?: ProjectCustomField, event?: Event): void {
  if (event) closeMenu(event)
  if (!canManage.value || mutating.value) return
  editingField.value = field
  creating.value = !field
}
async function lifecycle(field: ProjectCustomField, event?: Event): Promise<void> {
  if (event) closeMenu(event)
  if (!canManage.value || mutating.value || field.project_id !== props.projectId) return
  const projectId = props.projectId
  mutating.value = true
  mutationError.value = ''
  try {
    if (
      !field.archived_at &&
      !(await confirmation.value?.ask({
        title: 'Arhivezi câmpul?',
        message:
          'Câmpul nu va mai fi disponibil pentru asocieri noi. Definiția și istoricul se păstrează; îl poți restaura ulterior.',
        confirmText: 'Arhivează',
        cancelText: 'Anulează',
      }))
    )
      return
    if (!canManage.value || projectId !== props.projectId) return
    if (field.archived_at) await projectNoteConfigurationApi.restoreField(projectId, field.id)
    else await projectNoteConfigurationApi.archiveField(projectId, field.id)
    if (projectId === props.projectId) await load()
  } catch (failure) {
    if (projectId === props.projectId)
      mutationError.value =
        failure instanceof ApiError
          ? `${projectError(failure, failure.message)} ${Object.values(failure.errors).flat().join(' ')}`.trim()
          : 'Nu am putut schimba starea câmpului. Încearcă din nou.'
  } finally {
    mutating.value = false
  }
}
function saved(): void {
  closeEditor()
  void load()
}
const headingId = useId()
const fields = ref<ProjectCustomField[]>([])
const loading = ref(true)
const error = ref('')
const types: Record<ProjectCustomFieldType, { label: string; indicator: string }> = {
  number: { label: 'Număr', indicator: '#' },
  select: { label: 'Select', indicator: '≡' },
  boolean: { label: 'Da / Nu', indicator: '✓' },
  text: { label: 'Text', indicator: 'Aa' },
}
const aggregations: Record<ProjectNumericAggregation, string> = {
  average: 'Medie',
  sum: 'Sumă',
  min: 'Minim',
  max: 'Maxim',
  latest: 'Ultima valoare',
}
let sequence = 0
let disposed = false
async function load(): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  fields.value = []
  try {
    const response = await projectNoteConfigurationApi.fields(props.projectId)
    if (!disposed && request === sequence) fields.value = response.data
  } catch (failure) {
    if (!disposed && request === sequence)
      error.value = projectError(
        failure,
        'Nu am putut încărca câmpurile personalizate. Încearcă din nou.',
      )
  } finally {
    if (!disposed && request === sequence) loading.value = false
  }
}
watch(
  () => props.projectId,
  () => {
    closeEditor()
    mutationError.value = ''
    inspecting.value.clear()
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
  <section class="custom-fields-library" :aria-labelledby="headingId" :aria-busy="loading">
    <header class="custom-fields-library__heading">
      <div class="custom-fields-library__title">
        <h2 :id="headingId">Câmpuri personalizate</h2>
        <span
          v-if="!loading && !error"
          class="custom-fields-library__count"
          aria-label="Număr de câmpuri"
          >{{ fields.length }}</span
        >
      </div>
      <button
        v-if="canManage"
        class="custom-fields-library__add"
        type="button"
        aria-label="Adaugă câmp"
        :disabled="loading || mutating"
        @click="openEditor()"
      >
        + Câmp
      </button>
    </header>
    <p v-if="mutationError" class="projects-error" role="alert">{{ mutationError }}</p>
    <p v-if="loading" class="custom-fields-library__state" role="status">Se încarcă câmpurile…</p>
    <div v-else-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="load">Reîncearcă</AppButton>
    </div>
    <p v-else-if="!fields.length" class="custom-fields-library__state">
      Proiectul nu are încă câmpuri personalizate.
    </p>
    <ul v-else class="custom-fields-library__list">
      <li
        v-for="field in fields"
        :key="field.id"
        class="custom-field"
        :class="{ 'custom-field--archived': field.archived_at !== null }"
      >
        <span class="custom-field__indicator" aria-hidden="true">{{
          types[field.type].indicator
        }}</span>
        <div class="custom-field__body">
          <div class="custom-field__heading">
            <h3>{{ field.name }}</h3>
            <span v-if="field.archived_at !== null" class="custom-field__archived">Arhivat</span>
          </div>
          <dl class="custom-field__metadata">
            <div>
              <dt>Tip</dt>
              <dd>{{ types[field.type].label }}</dd>
            </div>
            <div v-if="field.type === 'number' && field.unit">
              <dt>Unitate</dt>
              <dd>{{ field.unit }}</dd>
            </div>
            <div v-if="field.type === 'number' && field.aggregation">
              <dt>Agregare</dt>
              <dd>{{ aggregations[field.aggregation] }}</dd>
            </div>
          </dl>
          <p v-if="field.description" class="custom-field__description">{{ field.description }}</p>
          <ProjectSelectOptionsEditor
            v-if="field.type === 'select' && inspecting.has(field.id)"
            :project-id="projectId"
            :field="field"
            disabled
            inspect
          />
        </div>
        <details v-if="canManage || field.type === 'select'" class="custom-field__actions">
          <summary
            :aria-label="`Acțiuni câmp ${field.name}`"
            @click="mutating && $event.preventDefault()"
          >
            •••
          </summary>
          <div>
            <button
              v-if="canManage"
              type="button"
              class="custom-field__edit"
              :aria-label="`Editează câmpul ${field.name}`"
              :disabled="mutating"
              @click="openEditor(field, $event)"
            >
              Editează
            </button>
            <button
              v-if="canManage"
              type="button"
              :aria-label="`${field.archived_at ? 'Restaurează' : 'Arhivează'} câmpul ${field.name}`"
              :disabled="mutating"
              @click="lifecycle(field, $event)"
            >
              {{ field.archived_at ? 'Restaurează' : 'Arhivează' }}
            </button>
            <button
              v-if="field.type === 'select'"
              type="button"
              :aria-label="`Vezi opțiunile câmpului ${field.name}`"
              @click="inspect(field, $event)"
            >
              {{ inspecting.has(field.id) ? 'Ascunde opțiunile' : 'Vezi opțiunile' }}
            </button>
          </div>
        </details>
      </li>
    </ul>
    <ProjectCustomFieldEditor
      v-if="(creating || editingField) && project"
      :key="editingField?.id ?? 'create'"
      :project="project"
      :field="editingField"
      @cancel="closeEditor"
      @saved="saved"
      @changed="load"
    />
    <ProgressNoteConfirmDialog v-if="canManage" ref="confirmation" class="custom-fields-confirm" />
  </section>
</template>

<style scoped>
.custom-fields-library {
  display: grid;
  gap: var(--space-3);
  min-width: 0;
  text-align: left;
}
.custom-fields-library__heading {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.custom-fields-library__heading {
  justify-content: space-between;
}
.custom-fields-library__title {
  display: flex;
  align-items: center;
  min-width: 0;
  gap: var(--space-2);
}
.custom-fields-library__add,
.custom-field__edit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
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
.custom-fields-library__add:disabled {
  opacity: 0.5;
  cursor: default;
}
.custom-field__edit {
  width: 44px;
  margin: -6px -6px 0 0;
}
.custom-fields-library__heading h2 {
  margin: 0;
  font-size: 1.05rem;
}
.custom-fields-library__count {
  padding: 3px 8px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-soft);
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
.custom-fields-library__state {
  margin: 0;
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text-muted);
  font-size: 0.85rem;
  line-height: 1.5;
}
.custom-fields-library .projects-error .app-button {
  justify-self: start;
  min-height: 44px;
  padding-inline: var(--space-4);
  font-size: 0.85rem;
}
.custom-fields-library__list {
  display: grid;
  gap: var(--space-2);
  min-width: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}
.custom-field {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  min-width: 0;
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
}
.custom-field--archived {
  border-style: dashed;
  background: var(--color-surface-soft);
}
.custom-field__indicator {
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: var(--color-primary-soft);
  color: var(--color-primary-strong);
  font-size: 0.85rem;
  font-weight: 700;
}
.custom-field--archived .custom-field__indicator {
  background: var(--color-border);
  color: var(--color-text-muted);
}
.custom-field__body {
  display: grid;
  gap: 6px;
  min-width: 0;
  flex: 1;
}
.custom-field__heading {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px var(--space-2);
}
.custom-field__heading h3 {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
.custom-field__archived {
  padding: 2px 7px;
  border-radius: var(--radius-pill);
  background: var(--color-border);
  color: var(--color-text);
  font-size: 0.7rem;
}
.custom-field__metadata {
  display: flex;
  flex-wrap: wrap;
  gap: 4px var(--space-3);
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.76rem;
  line-height: 1.5;
}
.custom-field__metadata > div {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  min-width: 0;
}
.custom-field__metadata dt::after {
  content: ':';
}
.custom-field__metadata dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.custom-field__description {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.5;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>

<style scoped>
.custom-field__actions {
  position: relative;
  flex: none;
  align-self: start;
  margin: -6px -6px 0 0;
}
.custom-field__actions summary {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  list-style: none;
  color: var(--color-primary-strong);
  cursor: pointer;
}
.custom-field__actions summary::-webkit-details-marker {
  display: none;
}
.custom-field__actions > div {
  position: absolute;
  right: 0;
  z-index: 2;
  display: grid;
  min-width: 140px;
  padding: 4px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}
.custom-field__actions button {
  width: 100%;
  min-height: 44px;
  margin: 0;
  padding: 6px 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--color-text);
  text-align: left;
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}
.custom-field__actions button:disabled {
  opacity: 0.5;
  cursor: default;
}
.custom-fields-confirm {
  box-sizing: border-box;
  width: min(420px, calc(100% - 24px));
  max-height: 90dvh;
  margin: auto;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text);
  background: var(--color-surface);
  text-align: left;
}
.custom-fields-confirm::backdrop {
  background: var(--color-overlay);
}
.custom-fields-confirm :deep(h3) {
  margin: 0 0 8px;
  font-size: 1rem;
}
.custom-fields-confirm :deep(p) {
  margin: 0 0 12px;
  font-size: 0.85rem;
  line-height: 1.4;
}
.custom-fields-confirm :deep(.note-editor-confirm__actions) {
  display: flex;
  flex-wrap: wrap;
  justify-content: end;
  gap: 8px;
}
.custom-fields-confirm :deep(.app-button) {
  min-height: 44px;
  padding-inline: 12px;
  font-size: 0.85rem;
}
</style>
