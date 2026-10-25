<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue'
import { RouterLink } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import PlusIcon from '@/components/icons/plusIcon.vue'
import ProjectCard from '@/components/projects/ProjectCard.vue'
import { projectsApi } from '@/services/projectsApi'
import type { Project } from '@/types/projects'
import { projectError } from '@/utils/projectPresentation'

function section() {
  return { items: [] as Project[], loading: false, loaded: false, error: '', page: 0, lastPage: 1 }
}
const active = reactive(section())
const inactive = reactive(section())
function groups(items: Project[]) {
  const categories = new Map<
    number,
    { id: number; name: string; order: number; projects: Project[] }
  >()
  for (const project of items) {
    if (!categories.has(project.category_id))
      categories.set(project.category_id, {
        id: project.category_id,
        name: project.category.name,
        order: project.category.sort_order,
        projects: [],
      })
    categories.get(project.category_id)!.projects.push(project)
  }
  return [...categories.values()]
    .sort((a, b) => a.order - b.order || a.id - b.id)
    .map((group) => ({
      ...group,
      projects: group.projects.sort(
        (a, b) => b.last_activity_at.localeCompare(a.last_activity_at) || b.id - a.id,
      ),
    }))
}
const activeGroups = computed(() => groups(active.items))
async function load(status: 'active' | 'inactive', more = false): Promise<void> {
  const state = status === 'active' ? active : inactive
  if (state.loading) return
  state.loading = true
  state.error = ''
  try {
    const response = await projectsApi.list(status, more ? state.page + 1 : 1)
    const items = more ? [...state.items, ...response.data] : response.data
    state.items = [...new Map(items.map((project) => [project.id, project])).values()]
    state.page = response.meta.current_page
    state.lastPage = response.meta.last_page
    state.loaded = true
  } catch (failure) {
    state.error = projectError(failure)
  } finally {
    state.loading = false
  }
}
function toggleHistory(event: Event): void {
  if ((event.target as HTMLDetailsElement).open && !inactive.loaded) void load('inactive')
}
onMounted(() => load('active'))
</script>

<template>
  <section class="projects-page">
    <header class="projects-header">
      <div>
        <p class="projects-eyebrow">Un pas mai aproape</p>
        <h1>Projects</h1>
      </div>
      <RouterLink class="projects-add" :to="{ name: 'project-create' }"
        ><PlusIcon aria-hidden="true" /> Proiect nou</RouterLink
      >
    </header>
    <p class="projects-muted">Obiectivele tale, într-un singur loc.</p>
    <p v-if="active.loading && !active.loaded" role="status">Se încarcă proiectele…</p>
    <div v-if="active.error" class="projects-error" role="alert">
      <p>{{ active.error }}</p>
      <AppButton variant="secondary" @click="load('active', active.loaded)"
        >Încearcă din nou</AppButton
      >
    </div>
    <div v-if="active.loaded && !active.items.length" class="projects-empty">
      <span aria-hidden="true">🌱</span>
      <h2>Cu ce vrei să începi?</h2>
      <p>Adaugă primul proiect și fă loc lucrurilor care contează pentru tine.</p>
      <RouterLink class="projects-link" :to="{ name: 'project-create' }"
        >Creează un proiect</RouterLink
      >
    </div>
    <section v-for="group in activeGroups" :key="group.id" class="projects-category">
      <h2>
        {{ group.name }} <span>{{ group.projects.length }}</span>
      </h2>
      <div class="projects-stack">
        <ProjectCard v-for="project in group.projects" :key="project.id" :project="project" />
      </div>
    </section>
    <AppButton
      v-if="active.loaded && active.page < active.lastPage"
      variant="secondary"
      block
      :loading="active.loading"
      @click="load('active', true)"
      >Mai multe proiecte</AppButton
    >
    <details class="projects-history" @toggle="toggleHistory">
      <summary>Finalizate și arhivate</summary>
      <p v-if="inactive.loading && !inactive.loaded" role="status">Se încarcă istoricul…</p>
      <div v-if="inactive.error" class="projects-error" role="alert">
        <p>{{ inactive.error }}</p>
        <AppButton variant="secondary" @click="load('inactive', inactive.loaded)"
          >Încearcă din nou</AppButton
        >
      </div>
      <p v-if="inactive.loaded && !inactive.items.length" class="projects-muted">
        Nu ai proiecte finalizate sau arhivate.
      </p>
      <div class="projects-stack">
        <ProjectCard v-for="project in inactive.items" :key="project.id" :project="project" />
      </div>
      <AppButton
        v-if="inactive.loaded && inactive.page < inactive.lastPage"
        variant="secondary"
        block
        :loading="inactive.loading"
        @click="load('inactive', true)"
        >Mai multe din istoric</AppButton
      >
    </details>
  </section>
</template>

<style src="./projects.css"></style>
