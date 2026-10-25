<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { Project } from '@/types/projects'
import { projectIcon, projectStatuses } from '@/utils/projectPresentation'

defineProps<{ project: Project }>()
</script>

<template>
  <RouterLink class="project-card" :to="{ name: 'project-detail', params: { id: project.id } }">
    <span class="project-card__icon" aria-hidden="true">{{ projectIcon(project.icon) }}</span>
    <span class="project-card__body">
      <strong>{{ project.name }}</strong>
      <span
        >{{ project.category.name
        }}<template v-if="project.status !== 'active'">
          · {{ projectStatuses[project.status] }}</template
        ></span
      >
    </span>
    <span class="project-card__arrow" aria-hidden="true">›</span>
  </RouterLink>
</template>

<style scoped>
.project-card {
  display: flex;
  min-height: 84px;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
  text-decoration: none;
}
.project-card:active {
  background: var(--color-surface-soft);
}
.project-card__icon {
  display: grid;
  width: 48px;
  height: 48px;
  flex-shrink: 0;
  place-items: center;
  border-radius: var(--radius-sm);
  background: var(--color-primary-soft);
  font-size: 1.4rem;
}
.project-card__body {
  display: grid;
  min-width: 0;
  gap: var(--space-1);
}
.project-card__body strong {
  overflow-wrap: anywhere;
  font-size: 1rem;
}
.project-card__body > span {
  color: var(--color-text-muted);
  font-size: 0.8rem;
}
.project-card__arrow {
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 1.5rem;
}
</style>
