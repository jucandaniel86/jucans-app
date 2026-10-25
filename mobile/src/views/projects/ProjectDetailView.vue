<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, RouterLink, useRoute } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import ProjectTasksTab from '@/components/projects/ProjectTasksTab.vue'
import ProjectProgressTab from '@/components/projects/ProjectProgressTab.vue'
import ProjectChartsTab from '@/components/projects/ProjectChartsTab.vue'
import type { NoteTask } from '@/types/projectProgress'
import type { ProjectTask } from '@/types/projectTasks'
import { ApiError } from '@/services/api'
import { projectsApi } from '@/services/projectsApi'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import type { Project, ProjectProgressSummary, ProjectStatus } from '@/types/projects'
import { projectError, projectIcon, projectStatuses } from '@/utils/projectPresentation'

const route = useRoute()
const auth = useAuthStore()
const notifications = useNotificationStore()
const project = ref<Project | null>(null)
const summary = ref<ProjectProgressSummary | null>(null)
const loading = ref(true)
const changingStatus = ref(false)
const tasksBusy = ref(false)
const progressBusy = ref(false)
const contentBusy = computed(() => tasksBusy.value || progressBusy.value)
const progressTask = ref<NoteTask | null>(null)
const progressDate = ref<string>()
function openTaskProgress(task: ProjectTask, date: string): void {
  progressTask.value = { id: task.id, title: task.title, deleted_at: task.deleted_at }
  progressDate.value = date
  tab.value = 'progress'
}
function consumeProgressIntent(): void {
  progressTask.value = null
  progressDate.value = undefined
}
const error = ref('')
const summaryError = ref('')
const summaryLoading = ref(false)
const tab = ref('tasks')
const tabs = [
  { name: 'tasks', label: 'Tasks' },
  { name: 'progress', label: 'Progress' },
  { name: 'charts', label: 'Charts' },
]
const panelId = useId()
const actions = ref<HTMLDetailsElement | null>(null)
const isOwner = computed(() => project.value?.created_by === auth.user?.id)
const isActive = computed(() => project.value?.status === 'active')
let sequence = 0
let summarySequence = 0
async function loadSummary(id: number, request: number): Promise<void> {
  const refresh = ++summarySequence
  summaryLoading.value = true
  summaryError.value = ''
  try {
    const response = await projectsApi.summary(id)
    if (request === sequence && refresh === summarySequence) summary.value = response.data
  } catch (failure) {
    if (request === sequence && refresh === summarySequence)
      summaryError.value =
        failure instanceof ApiError && failure.status === 404
          ? 'Sumarul de progres nu este disponibil pe acest server.'
          : projectError(failure, 'Nu am putut încărca sumarul.')
  } finally {
    if (request === sequence && refresh === summarySequence) summaryLoading.value = false
  }
}
async function load(): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  summaryError.value = ''
  project.value = null
  summary.value = null
  try {
    const response = await projectsApi.get(Number(route.params.id))
    if (request !== sequence) return
    project.value = response.data
    void loadSummary(response.data.id, request)
  } catch (failure) {
    if (request === sequence) error.value = projectError(failure)
  } finally {
    if (request === sequence) loading.value = false
  }
}
async function changeStatus(status: ProjectStatus): Promise<void> {
  if (!project.value || !isOwner.value || changingStatus.value || contentBusy.value) return
  if (actions.value) actions.value.open = false
  const id = project.value.id
  const request = sequence
  const confirmed = await notifications.confirm({
    title:
      status === 'active'
        ? 'Reactivezi proiectul?'
        : status === 'completed'
          ? 'Finalizezi proiectul?'
          : 'Arhivezi proiectul?',
    message:
      status === 'active'
        ? 'Proiectul va putea fi modificat din nou.'
        : 'Proiectul va fi doar pentru citire până la reactivare.',
    confirmText:
      status === 'active' ? 'Reactivează' : status === 'completed' ? 'Finalizează' : 'Arhivează',
  })
  if (!confirmed || request !== sequence) return
  changingStatus.value = true
  try {
    const response = await projectsApi.status(id, status)
    project.value = response.data
    notifications.success('Starea proiectului a fost actualizată.')
    await loadSummary(id, request)
  } catch (failure) {
    notifications.error(projectError(failure, 'Nu am putut schimba starea proiectului.'))
    if (failure instanceof ApiError && [403, 404, 409].includes(failure.status)) await load()
  } finally {
    changingStatus.value = false
  }
}
async function tabKeydown(event: KeyboardEvent): Promise<void> {
  if (contentBusy.value || changingStatus.value) return
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const index = tabs.findIndex((item) => item.name === tab.value)
  tab.value =
    tabs[
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? 2
          : (index + (event.key === 'ArrowLeft' ? 2 : 1)) % 3
    ]!.name
  await nextTick()
  document.getElementById(`${panelId}-${tab.value}`)?.focus()
}
onBeforeRouteLeave(() => !changingStatus.value && !contentBusy.value)
onBeforeRouteUpdate(() => !changingStatus.value && !contentBusy.value)
watch(
  () => route.params.id,
  () => {
    tab.value = 'tasks'
    void load()
  },
  { immediate: true },
)
</script>

<template>
  <section class="projects-page project-detail">
    <p v-if="loading" role="status">Se încarcă proiectul…</p>
    <div v-else-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" @click="load">Încearcă din nou</AppButton>
    </div>
    <template v-else-if="project">
      <div class="project-detail__intro">
        <header class="projects-header">
          <div class="projects-title">
            <div class="project-detail__icon" :class="`project-detail__icon--${project.status}`">
              <span class="project-detail__status">{{ projectStatuses[project.status] }}</span>
              <span aria-hidden="true">{{ projectIcon(project.icon) }}</span>
            </div>
            <div>
              <div class="project-detail__meta">
                <span :title="project.category.name">{{ project.category.name }}</span>
                <RouterLink :to="{ name: 'projects' }">Toate proiectele</RouterLink>
              </div>
              <h1>{{ project.name }}</h1>
            </div>
          </div>
          <details ref="actions" class="projects-actions">
            <summary aria-label="Acțiuni proiect">•••</summary>
            <div class="projects-actions__menu">
              <template v-if="isOwner">
                <RouterLink
                  v-if="isActive"
                  :to="{ name: 'project-edit', params: { id: project.id } }"
                  >Setări proiect</RouterLink
                >
                <template v-if="isActive"
                  ><button
                    type="button"
                    :disabled="changingStatus || contentBusy"
                    @click="changeStatus('completed')"
                  >
                    Finalizează</button
                  ><button
                    type="button"
                    :disabled="changingStatus || contentBusy"
                    @click="changeStatus('archived')"
                  >
                    Arhivează
                  </button></template
                >
                <button
                  v-else
                  type="button"
                  :disabled="changingStatus || contentBusy"
                  @click="changeStatus('active')"
                >
                  Reactivează
                </button>
              </template>
              <RouterLink :to="{ name: 'projects' }">Toate proiectele</RouterLink>
            </div>
          </details>
        </header>
        <p v-if="project.description" class="projects-description">{{ project.description }}</p>
      </div>
      <p v-if="changingStatus" role="status">Se actualizează starea…</p>
      <section class="project-detail__summary" aria-label="Sumar proiect">
        <div class="project-detail__counts">
          <p v-if="summaryLoading" role="status">Se încarcă sumarul…</p>
          <div v-else-if="summaryError">
            <p class="projects-muted" role="status">{{ summaryError }}</p>
            <button
              class="projects-text-button"
              type="button"
              @click="loadSummary(project.id, sequence)"
            >
              Reîncearcă sumarul
            </button>
          </div>
          <template v-else-if="summary">
            <dl class="project-detail__metrics">
              <div>
                <dt>Taskuri active:</dt>
                <dd>{{ summary.completion.remaining_tasks }}</dd>
              </div>
              <div>
                <dt>Finalizate:</dt>
                <dd>{{ summary.completion.completed_tasks }}</dd>
              </div>
            </dl>
          </template>
        </div>
        <div
          v-if="tab === 'tasks'"
          :id="`${panelId}-task-actions`"
          class="project-detail__task-actions"
        />
      </section>
      <div class="projects-tabs" role="tablist" aria-label="Secțiuni proiect" @keydown="tabKeydown">
        <button
          v-for="item in tabs"
          :id="`${panelId}-${item.name}`"
          :key="item.name"
          type="button"
          role="tab"
          :aria-selected="tab === item.name"
          :aria-controls="`${panelId}-panel`"
          :tabindex="tab === item.name ? 0 : -1"
          :disabled="contentBusy || changingStatus"
          @click="tab = item.name"
        >
          {{ item.label }}
        </button>
      </div>
      <div
        :id="`${panelId}-panel`"
        role="tabpanel"
        :aria-labelledby="`${panelId}-${tab}`"
        tabindex="0"
      >
        <ProjectTasksTab
          v-if="tab === 'tasks'"
          :key="project.id"
          :project="project"
          :timezone="summary?.timezone ?? 'UTC'"
          :external-busy="changingStatus"
          :actions-target="`#${panelId}-task-actions`"
          @busy="tasksBusy = $event"
          @progress="openTaskProgress"
          @changed="loadSummary(project.id, sequence)"
          @access-changed="load"
        />
        <ProjectProgressTab
          v-else-if="tab === 'progress'"
          :key="project.id"
          :project="project"
          :timezone="summary?.timezone ?? 'UTC'"
          :initial-task="progressTask"
          :initial-date="progressDate"
          :external-busy="changingStatus"
          @consumed="consumeProgressIntent"
          @busy="progressBusy = $event"
          @changed="loadSummary(project.id, sequence)"
          @access-changed="load"
        />
        <ProjectChartsTab
          v-else
          :key="project.id"
          :project="project"
          :summary-data="summary"
          @access-changed="load"
        />
      </div>
    </template>
  </section>
</template>

<style src="./projects.css"></style>

<style scoped>
.project-detail {
  gap: 8px;
  min-width: 0;
}
.project-detail__intro {
  display: grid;
  gap: 4px;
  min-width: 0;
}
.project-detail .projects-header {
  gap: 8px;
  align-items: center;
}
.project-detail .projects-title {
  flex: 1;
  gap: 10px;
  min-width: 0;
}
.project-detail .projects-title > div:last-child {
  flex: 1;
  min-width: 0;
}
.project-detail h1 {
  font-size: 1.25rem;
  line-height: 1.2;
}
.project-detail__icon {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  gap: 3px;
  width: 62px;
  height: 62px;
  border-radius: var(--radius-md);
  font-size: 1.6rem;
  line-height: 1;
}
.project-detail__icon--active {
  background: #e7f2e9;
  color: #365f43;
}
.project-detail__icon--completed {
  background: var(--color-yellow-soft);
  color: #76591f;
}
.project-detail__icon--archived {
  background: var(--color-surface-soft);
  color: var(--color-text-muted);
}
.project-detail__status {
  font-size: 9px;
  line-height: 1.25;
  font-weight: 800;
  letter-spacing: 0.035em;
  text-transform: uppercase;
}
.project-detail__meta {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  font-size: 0.69rem;
  line-height: 1.2;
  color: var(--color-text-muted);
}
.project-detail__meta > span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.project-detail__meta a {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
  min-height: 44px;
  margin-block: -6px;
  color: var(--color-primary-strong);
  text-decoration: none;
}
.project-detail .projects-actions {
  align-self: center;
}
.project-detail .projects-description {
  font-size: 0.85rem;
  line-height: 1.4;
}
.project-detail__summary {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
  align-items: center;
  padding-block: 4px;
}
.project-detail__counts {
  min-width: 0;
  font-size: 0.78rem;
}
.project-detail__metrics {
  display: grid;
  gap: 8px;
  margin: 0;
}
.project-detail__metrics > div {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 8px;
}
.project-detail__metrics dt {
  font-size: 0.76rem;
  color: var(--color-text-muted);
}
.project-detail__metrics dd {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 800;
}
.project-detail__counts .projects-text-button {
  min-height: 44px;
  padding-block: 6px;
  font-size: 0.76rem;
}
.project-detail__task-actions {
  min-width: 0;
}
.project-detail__task-actions :deep(.task-summary-actions) {
  display: grid;
  gap: 4px;
}
.project-detail__task-actions :deep(.task-summary-actions > button) {
  width: 100%;
  min-width: 0;
  min-height: 44px;
  padding: 6px 8px;
  font-size: 0.77rem;
}
.project-detail__task-actions :deep(svg) {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}
.project-detail .projects-tabs {
  gap: 2px;
  padding: 2px;
}
.project-detail :deep(.project-tasks),
.project-detail :deep(.task-calendar) {
  gap: 8px;
}
@media (min-width: 600px) {
  .project-detail h1 {
    font-size: 1.5rem;
  }
  .project-detail__summary {
    grid-template-columns: minmax(0, 1fr) minmax(0, 280px);
  }
  .project-detail__task-actions :deep(.task-summary-actions) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
