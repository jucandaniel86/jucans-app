<script setup lang="ts">
import { computed, nextTick, reactive, ref, useId, watch } from 'vue'
import {
  onBeforeRouteLeave,
  onBeforeRouteUpdate,
  RouterLink,
  useRoute,
  useRouter,
} from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import ProjectNoteTypesLibrary from '@/components/projects/ProjectNoteTypesLibrary.vue'
import ProjectCustomFieldsLibrary from '@/components/projects/ProjectCustomFieldsLibrary.vue'
import { ApiError } from '@/services/api'
import { projectsApi } from '@/services/projectsApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type {
  Project,
  ProjectCategory,
  ProjectExecutionType,
  ProjectProgressType,
} from '@/types/projects'
import {
  projectError,
  projectIcons,
  projectProgressLabels,
  projectExecutionLabels,
} from '@/utils/projectPresentation'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationStore()
const editing = computed(() => route.name === 'project-edit')
const tabs = [
  { name: 'general', label: 'General' },
  { name: 'settings', label: 'Settings' },
  { name: 'charts', label: 'Charts' },
] as const
type EditTab = (typeof tabs)[number]['name']
const tabId = useId()
const activeTab = computed<EditTab>(() =>
  editing.value && (route.query.tab === 'settings' || route.query.tab === 'charts')
    ? route.query.tab
    : 'general',
)
const settingsVisited = ref(false)
const fieldLibraryVersion = ref(0)
const project = ref<Project | null>(null)
const categories = ref<ProjectCategory[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const loadError = ref('')
const validation = ref<Record<string, string[]>>({})
const formElement = ref<HTMLFormElement | null>(null)
const form = reactive({
  name: '',
  description: '',
  note_template: '',
  category_id: '',
  icon: 'folder',
  progress_type: 'completion' as ProjectProgressType,
  default_execution_type: 'one_time' as ProjectExecutionType,
})
const canEdit = computed(
  () =>
    !editing.value ||
    (project.value?.created_by === auth.user?.id && project.value?.status === 'active'),
)
const categoryOptions = computed(() => [
  { value: '', label: 'Alege categoria' },
  ...categories.value.map((category) => ({ value: String(category.id), label: category.name })),
])
const iconOptions = computed(() =>
  projectIcons.some((icon) => icon.value === form.icon)
    ? projectIcons
    : [
        ...projectIcons,
        { value: form.icon, label: `Pictograma curentă (${form.icon})`, symbol: '📁' },
      ],
)
const progressOptions = Object.entries(projectProgressLabels).map(([value, label]) => ({
  value,
  label,
}))
const executionOptions = Object.entries(projectExecutionLabels).map(([value, label]) => ({
  value,
  label,
}))
const back = computed(() =>
  editing.value
    ? { name: 'project-detail', params: { id: route.params.id as string } }
    : { name: 'projects' },
)
let sequence = 0
async function load(): Promise<void> {
  const request = ++sequence
  loading.value = true
  loadError.value = ''
  error.value = ''
  validation.value = {}
  project.value = null
  settingsVisited.value = false
  try {
    const [categoryResponse, projectResponse] = await Promise.all([
      projectsApi.categories(),
      editing.value ? projectsApi.get(Number(route.params.id)) : Promise.resolve(null),
    ])
    if (request !== sequence) return
    categories.value = categoryResponse.data
    project.value = projectResponse?.data ?? null
    Object.assign(
      form,
      project.value
        ? {
            name: project.value.name,
            description: project.value.description ?? '',
            note_template: project.value.note_template ?? '',
            category_id: String(project.value.category_id),
            icon: project.value.icon,
            progress_type: project.value.progress_type,
            default_execution_type: project.value.default_execution_type,
          }
        : {
            name: '',
            description: '',
            note_template: '',
            category_id: '',
            icon: 'folder',
            progress_type: 'completion',
            default_execution_type: 'one_time',
          },
    )
  } catch (failure) {
    if (request === sequence) loadError.value = projectError(failure)
  } finally {
    if (request === sequence) loading.value = false
  }
}
async function save(): Promise<void> {
  if (saving.value || !canEdit.value) return
  saving.value = true
  error.value = ''
  validation.value = {}
  let savedId: number | null = null
  try {
    const payload = {
      ...form,
      name: form.name.trim(),
      description: form.description.trim() || null,
      note_template: form.note_template.trim() ? form.note_template : null,
      category_id: Number(form.category_id),
    }
    const response =
      editing.value && project.value
        ? await projectsApi.update(project.value.id, payload)
        : await projectsApi.create(payload)
    savedId = response.data.id
    notifications.success(
      editing.value ? 'Proiectul a fost actualizat.' : 'Proiectul a fost creat.',
    )
  } catch (failure) {
    if (failure instanceof ApiError && failure.status === 422) {
      validation.value = failure.errors
      error.value = 'Verifică informațiile introduse.'
      await nextTick()
      formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    } else {
      error.value = projectError(failure, 'Nu am putut salva proiectul. Încearcă din nou.')
      if (failure instanceof ApiError && [403, 404, 409].includes(failure.status))
        loadError.value = error.value
    }
  } finally {
    saving.value = false
  }
  if (savedId !== null) await router.push({ name: 'project-detail', params: { id: savedId } })
}
async function changeTab(name: EditTab, focus = false): Promise<void> {
  if (!editing.value || saving.value) return
  if (activeTab.value !== name) {
    await router.push({
      name: 'project-edit',
      params: { id: route.params.id },
      query: { ...route.query, tab: name === 'general' ? undefined : name },
      hash: route.hash,
    })
  }
  if (focus && activeTab.value === name) {
    await nextTick()
    document.getElementById(`${tabId}-${name}`)?.focus()
  }
}
function tabKeydown(event: KeyboardEvent): void {
  if (saving.value || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const index = tabs.findIndex((tab) => tab.name === activeTab.value)
  const next =
    event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? tabs.length - 1
        : (index + (event.key === 'ArrowLeft' ? tabs.length - 1 : 1)) % tabs.length
  void changeTab(tabs[next]!.name, true)
}
watch([() => activeTab.value, () => project.value?.id], ([tab, id]) => {
  if (tab === 'settings' && id !== undefined) settingsVisited.value = true
})
onBeforeRouteLeave(() => !saving.value)
onBeforeRouteUpdate(() => !saving.value)
watch(
  // Query-only tab changes must never reload or reset the General draft.
  [() => route.name, () => route.params.id],
  () => {
    void load()
  },
  { immediate: true },
)
</script>

<template>
  <section class="projects-page">
    <RouterLink class="projects-back" :to="back"
      >‹ {{ editing ? 'Înapoi la proiect' : 'Toate proiectele' }}</RouterLink
    >
    <h1>{{ editing ? 'Setări proiect' : 'Proiect nou' }}</h1>
    <p class="projects-muted">
      {{
        editing ? 'Un proiect care evoluează odată cu tine.' : 'Începe cu un nume și o direcție.'
      }}
    </p>
    <p v-if="loading" role="status">Se încarcă…</p>
    <div v-else-if="loadError" class="projects-error" role="alert">
      <p>{{ loadError }}</p>
      <AppButton variant="secondary" @click="load">Reîncarcă</AppButton>
    </div>
    <template v-else>
      <div
        v-if="editing"
        class="projects-tabs project-edit-tabs"
        role="tablist"
        aria-label="Configurare proiect"
        aria-orientation="horizontal"
        @keydown="tabKeydown"
      >
        <button
          v-for="tab in tabs"
          :id="`${tabId}-${tab.name}`"
          :key="tab.name"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.name"
          :aria-controls="`${tabId}-${tab.name}-panel`"
          :tabindex="activeTab === tab.name ? 0 : -1"
          :disabled="saving"
          @click="changeTab(tab.name)"
        >
          {{ tab.label }}
        </button>
      </div>
      <section
        v-show="activeTab === 'general'"
        :id="`${tabId}-general-panel`"
        class="project-edit-panel"
        :role="editing ? 'tabpanel' : undefined"
        :aria-labelledby="editing ? `${tabId}-general` : undefined"
        :tabindex="editing ? 0 : undefined"
      >
        <div v-if="!canEdit" class="projects-empty">
          <h2>Doar pentru citire</h2>
          <p>Doar proprietarul poate edita setările unui proiect activ.</p>
          <RouterLink class="projects-link" :to="back">Înapoi la proiect</RouterLink>
        </div>
        <div v-else-if="!categories.length" class="projects-empty">
          <h2>Nu sunt categorii disponibile</h2>
          <p>Crearea unui proiect necesită o categorie configurată pe server.</p>
          <AppButton variant="secondary" @click="load">Reîncarcă</AppButton>
        </div>
        <form v-else ref="formElement" class="projects-form" @submit.prevent="save">
          <h2 v-if="editing">Setări generale</h2>
          <div v-if="error" class="projects-error" role="alert">
            <p>{{ error }}</p>
            <p v-for="(messages, field) in validation" :key="field">{{ messages.join(' ') }}</p>
          </div>
          <fieldset :disabled="saving" class="projects-fields">
            <AppInput
              v-model="form.name"
              label="Nume *"
              :maxlength="255"
              :error="validation.name?.[0]"
              placeholder="Ce vrei să construiești?"
            />
            <AppTextarea
              v-model="form.description"
              label="Descriere (opțional)"
              :rows="3"
              :error="validation.description?.[0]"
            />
            <AppSelect
              v-model="form.category_id"
              label="Categorie *"
              :options="categoryOptions"
              :error="validation.category_id?.[0]"
            />
            <AppSelect
              v-model="form.icon"
              label="Pictogramă"
              :options="iconOptions"
              :error="validation.icon?.[0]"
            />
            <AppSelect
              v-model="form.progress_type"
              label="Cum urmărești progresul?"
              :options="progressOptions"
              :error="validation.progress_type?.[0]"
            />
            <AppSelect
              v-model="form.default_execution_type"
              label="Tip implicit de task"
              :options="executionOptions"
              :error="validation.default_execution_type?.[0]"
            />
            <AppTextarea
              v-model="form.note_template"
              label="Template pentru notițe"
              :rows="4"
              :error="validation.note_template?.[0]"
              hint="Opțional. Poți folosi {date} pentru dată, {tasks} pentru taskurile asociate, {project} pentru numele proiectului și {user} pentru utilizator. Template-ul se aplică la cerere în formularul de progres."
            />
          </fieldset>
          <AppButton type="submit" block :loading="saving">{{
            editing ? 'Salvează modificările' : 'Creează proiectul'
          }}</AppButton>
          <RouterLink v-if="!saving" class="projects-cancel" :to="back">Renunță</RouterLink>
        </form>
      </section>
      <section
        v-if="editing && project"
        v-show="activeTab === 'settings'"
        :id="`${tabId}-settings-panel`"
        class="project-edit-panel"
        role="tabpanel"
        :aria-labelledby="`${tabId}-settings`"
        tabindex="0"
      >
        <ProjectNoteTypesLibrary
          v-if="settingsVisited"
          :key="`types-${project.id}`"
          @definitions-changed="fieldLibraryVersion++"
          :project="project"
        />
        <ProjectCustomFieldsLibrary
          v-if="settingsVisited"
          :key="`${project.id}-${fieldLibraryVersion}`"
          :project-id="project.id"
          :project="project"
        />
      </section>
      <section
        v-if="editing && project"
        v-show="activeTab === 'charts'"
        :id="`${tabId}-charts-panel`"
        class="project-edit-panel"
        role="tabpanel"
        :aria-labelledby="`${tabId}-charts`"
        tabindex="0"
      >
        <div class="project-edit-charts">
          <h2>Configurare grafice</h2>
          <p>Aici vei putea configura graficele proiectului și sursele lor de date.</p>
          <span class="projects-badge">În curând</span>
        </div>
      </section>
    </template>
  </section>
</template>

<style src="./projects.css"></style>

<style scoped>
.project-edit-tabs {
  min-width: 0;
  gap: 2px;
  padding: 2px;
}
.project-edit-tabs button {
  min-width: 0;
  min-height: 44px;
  padding: var(--space-2);
  font-size: 0.85rem;
}
.project-edit-tabs button:disabled {
  opacity: 0.6;
  cursor: default;
}
.project-edit-panel {
  min-width: 0;
}
.project-edit-charts {
  display: grid;
  justify-items: start;
  gap: var(--space-2);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
}
.project-edit-charts p {
  color: var(--color-text-muted);
  font-size: 0.85rem;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
</style>
