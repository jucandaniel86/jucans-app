<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
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
onBeforeRouteLeave(() => !saving.value)
onBeforeRouteUpdate(() => !saving.value)
watch(
  () => route.fullPath,
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
    <div v-else-if="!canEdit" class="projects-empty">
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
</template>

<style src="./projects.css"></style>
