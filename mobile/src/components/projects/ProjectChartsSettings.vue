<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import PencilIcon from '@/components/icons/pencilIcon.vue'
import TrashIcon from '@/components/icons/trashIcon.vue'
import ProjectChartEditor from '@/components/projects/ProjectChartEditor.vue'
import { projectChartDefinitionsApi } from '@/services/projectChartDefinitionsApi'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { useNotificationStore } from '@/stores/notifications'
import { useAuthStore } from '@/stores/auth'
import { projectError } from '@/utils/projectPresentation'
import type { Project } from '@/types/projects'
import type { ProjectCustomField } from '@/types/projectNoteConfiguration'
import type { ProjectChartDefinition } from '@/types/projectChartDefinitions'

const props = defineProps<{ project: Project }>()
const emit = defineEmits<{ busy: [value: boolean] }>()
const auth = useAuthStore()
const notifications = useNotificationStore()
const writable = computed(
  () => props.project.status === 'active' && props.project.created_by === auth.user?.id,
)
const charts = ref<ProjectChartDefinition[]>([])
const fields = ref<ProjectCustomField[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const creating = ref(false)
const editing = ref<ProjectChartDefinition>()
let sequence = 0
let disposed = false
function setBusy(value: boolean): void {
  busy.value = value
  emit('busy', value)
}
async function load(): Promise<void> {
  const request = ++sequence
  loading.value = true
  error.value = ''
  try {
    const [definitions, available] = await Promise.all([
      projectChartDefinitionsApi.list(props.project.id),
      projectNoteConfigurationApi.fields(props.project.id),
    ])
    if (disposed || request !== sequence) return
    charts.value = definitions.data
    fields.value = available.data
  } catch (failure) {
    if (!disposed && request === sequence)
      error.value = projectError(failure, 'Nu am putut încărca graficele.')
  } finally {
    if (!disposed && request === sequence) loading.value = false
  }
}
function edit(chart?: ProjectChartDefinition): void {
  if (busy.value || !writable.value) return
  editing.value = chart
  creating.value = !chart
}
function close(): void {
  editing.value = undefined
  creating.value = false
}
function saved(): void {
  close()
  void load()
}
async function remove(chart: ProjectChartDefinition): Promise<void> {
  if (busy.value || !writable.value) return
  setBusy(true)
  try {
    if (
      !(await notifications.confirm({
        title: 'Ștergi graficul?',
        message: `„${chart.title}” va fi șters. Notele și valorile rămân păstrate.`,
        confirmText: 'Șterge',
      }))
    )
      return
    await projectChartDefinitionsApi.delete(props.project.id, chart.id)
    if (editing.value?.id === chart.id) close()
    await load()
  } catch (failure) {
    error.value = projectError(failure)
  } finally {
    setBusy(false)
  }
}
onBeforeRouteLeave(() => !busy.value)
onBeforeRouteUpdate(() => !busy.value)
onMounted(() => {
  void load()
})
onBeforeUnmount(() => {
  disposed = true
  sequence++
  emit('busy', false)
})
</script>

<template>
  <section class="chart-config" aria-label="Configurare grafice">
    <header class="chart-config-heading">
      <h2>Grafice</h2>
      <AppButton v-if="writable" :disabled="busy || loading" @click="edit()">+ Grafic</AppButton>
    </header>
    <p v-if="loading" role="status">Se încarcă graficele…</p>
    <div v-if="error" class="projects-error" role="alert">
      <p>{{ error }}</p>
      <AppButton variant="secondary" :disabled="busy" @click="load">Reîncearcă</AppButton>
    </div>
    <ProjectChartEditor
      v-if="(creating || editing) && writable"
      :key="editing?.id ?? 'new'"
      :project-id="project.id"
      :chart="editing"
      :fields="fields"
      @busy="setBusy"
      @cancel="close"
      @saved="saved"
    />
    <p v-if="!loading && !error && !charts.length" class="projects-muted">
      Nu ai grafice configurate.
    </p>
    <article v-for="chart in charts" :key="chart.id" class="chart-config-item">
      <div>
        <strong>{{ chart.title }}</strong>
        <p>
          {{ chart.type.toUpperCase() }} · Priority {{ chart.priority }} ·
          {{ chart.active ? 'Activ' : 'Inactiv' }}
        </p>
      </div>
      <div class="task-buttons">
        <button
          v-if="writable"
          class="chart-config-icon"
          type="button"
          :aria-label="`Editează ${chart.title}`"
          title="Editează"
          :disabled="busy"
          @click="edit(chart)"
        >
          <PencilIcon /></button
        ><button
          v-if="writable"
          class="chart-config-icon"
          type="button"
          :aria-label="`Șterge ${chart.title}`"
          title="Șterge"
          :disabled="busy"
          @click="remove(chart)"
        >
          <TrashIcon />
        </button>
      </div>
    </article>
  </section>
</template>

<style src="./projectChartDefinitions.css"></style>
