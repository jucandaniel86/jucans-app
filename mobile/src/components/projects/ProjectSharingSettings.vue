<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import { projectsApi } from '@/services/projectsApi'
import { projectError } from '@/utils/projectPresentation'
import type { Project, ProjectCollaborator, ProjectShareableUser } from '@/types/projects'

const props = defineProps<{ project: Project; disabled?: boolean }>()
const emit = defineEmits<{ busy: [value: boolean] }>()
const users = ref<ProjectShareableUser[]>([])
const members = ref<ProjectCollaborator[]>([])
const selected = ref<number[]>([])
const search = ref('')
const loading = ref(true)
const busy = ref(false)
const loaded = ref(false)
const error = ref('')
const success = ref('')
const editable = computed(() => props.project.status === 'active')
const filteredUsers = computed(() =>
  users.value.filter((user) =>
    user.username.toLocaleLowerCase('ro').includes(search.value.trim().toLocaleLowerCase('ro')),
  ),
)
const dirty = computed(
  () =>
    selected.value.length !== members.value.length ||
    members.value.some((member) => !selected.value.includes(member.user_id)),
)
const restricted = computed(
  () =>
    new Set(
      members.value
        .filter((member) => member.sharing_mode === 'selected_tasks')
        .map((member) => member.user_id),
    ),
)

async function load(): Promise<void> {
  loading.value = true
  loaded.value = false
  error.value = ''
  try {
    const [available, current] = await Promise.all([
      projectsApi.shareableUsers(props.project.id),
      projectsApi.collaborators(props.project.id),
    ])
    users.value = available.data.filter((user) => user.id !== props.project.created_by)
    members.value = current.data
    selected.value = current.data.map((member) => member.user_id)
    loaded.value = true
  } catch (failure) {
    error.value = projectError(failure, 'Nu am putut încărca lista de membri.')
  } finally {
    loading.value = false
  }
}
async function save(): Promise<void> {
  if (!loaded.value || !editable.value || busy.value || props.disabled || !dirty.value) return
  const removed = members.value.filter((member) => !selected.value.includes(member.user_id))
  if (
    removed.length &&
    !window.confirm(
      'Elimini accesul membrilor deselectați? Taskurile, notele și istoricul se păstrează.',
    )
  )
    return
  const added = selected.value.filter(
    (id) => !members.value.some((member) => member.user_id === id),
  )
  busy.value = true
  emit('busy', true)
  error.value = ''
  success.value = ''
  let changed = false
  try {
    // Reuse transactional membership APIs; update the local baseline after each success.
    for (const member of removed) {
      await projectsApi.removeCollaborator(props.project.id, member.id)
      members.value = members.value.filter((current) => current.id !== member.id)
      changed = true
    }
    for (const userId of added) {
      const response = await projectsApi.addCollaborator(props.project.id, userId)
      members.value.push(response.data)
      changed = true
    }
    success.value = 'Partajarea a fost salvată.'
  } catch (failure) {
    error.value =
      (changed ? 'O parte dintre modificări a fost salvată. ' : '') +
      projectError(failure, 'Nu am putut salva partajarea.')
    try {
      // Preserve the desired selection so retry sends only outstanding changes.
      members.value = (await projectsApi.collaborators(props.project.id)).data
    } catch {
      loaded.value = false
      error.value += ' Reîncarcă lista înainte de a încerca din nou.'
    }
  } finally {
    busy.value = false
    emit('busy', false)
  }
}
onMounted(load)
</script>

<template>
  <section class="project-sharing projects-form" aria-label="Share with">
    <h2>Share with</h2>
    <p class="projects-muted">
      Membrii noi au acces la toate taskurile. Partajarea se salvează separat.
    </p>
    <p v-if="loading" role="status">Se încarcă membrii…</p>
    <p v-if="error" class="projects-error" role="alert">{{ error }}</p>
    <p v-if="success" role="status">{{ success }}</p>
    <AppButton v-if="!loading && !loaded" variant="secondary" :disabled="disabled" @click="load"
      >Reîncarcă membrii</AppButton
    >
    <template v-if="loaded">
      <AppInput v-model="search" label="Caută utilizatori" :disabled="busy || disabled" />
      <fieldset class="project-sharing-options" :disabled="!editable || busy || disabled">
        <legend class="projects-muted">{{ selected.length }} membri selectați</legend>
        <label v-for="user in filteredUsers" :key="user.id">
          <input v-model="selected" type="checkbox" :value="user.id" @change="success = ''" />
          <span
            >{{ user.username
            }}<small v-if="restricted.has(user.id)"> · acces restrâns existent</small></span
          >
        </label>
        <p v-if="!filteredUsers.length" class="projects-muted">
          Nu există utilizatori disponibili.
        </p>
      </fieldset>
      <AppButton v-if="editable" :loading="busy" :disabled="disabled || !dirty" @click="save"
        >Salvează partajarea</AppButton
      >
      <p v-else class="projects-muted">Reactivează proiectul pentru a modifica membrii.</p>
    </template>
  </section>
</template>

<style scoped>
.project-sharing {
  margin-top: var(--space-3);
  min-width: 0;
}
.project-sharing-options {
  margin: 0;
  padding: 0;
  border: 0;
  max-height: 240px;
  overflow-y: auto;
  min-width: 0;
}
.project-sharing-options label {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  cursor: pointer;
}
.project-sharing-options input {
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  accent-color: var(--color-primary-strong);
}
.project-sharing-options span {
  overflow-wrap: anywhere;
  min-width: 0;
}
.project-sharing-options small {
  color: var(--color-text-muted);
}
.project-sharing :deep(.app-button) {
  min-height: 44px;
  padding-inline: var(--space-3);
}
</style>
