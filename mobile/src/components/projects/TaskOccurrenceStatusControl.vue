<script setup lang="ts">
import type { ExecutionAction, TaskOccurrence } from '@/types/projectTasks'

const props = defineProps<{
  status: TaskOccurrence['status']
  label: string
  disabled?: boolean
}>()
const emit = defineEmits<{ change: [action: ExecutionAction] }>()
function change(event: Event): void {
  const select = event.target as HTMLSelectElement
  const status = select.value as TaskOccurrence['status']
  select.value = props.status
  if (status === props.status || props.disabled) return
  const actions: Record<TaskOccurrence['status'], ExecutionAction> = {
    pending: 'undo',
    done: 'done',
    skipped: 'skip',
  }
  emit('change', actions[status])
}
</script>

<template>
  <span class="task-status-control" :class="`task-status-control--${status}`" :title="label">
    <svg
      class="task-status-control__icon"
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="9" />
      <path v-if="status === 'done'" d="m8 12 3 3 5-6" />
      <path v-else-if="status === 'skipped'" d="m6 6 12 12" />
      <path v-else d="M12 7v5l3 2" />
    </svg>
    <span class="task-status-control__text" aria-hidden="true">{{
      status === 'done' ? 'Finalizat' : status === 'skipped' ? 'Nu se poate realiza' : 'De făcut'
    }}</span>
    <svg
      width="12"
      height="12"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path d="m6 9 6 6 6-6" />
    </svg>
    <select :value="status" :disabled="disabled" :aria-label="label" @change="change">
      <option value="pending">De făcut</option>
      <option value="done">Finalizat</option>
      <option value="skipped">Nu se poate realiza</option>
    </select>
  </span>
</template>

<style scoped>
.task-status-control {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 34px;
  min-width: 0;
  max-width: 100%;
  width: 196px;
  padding: 6px 8px;
  box-sizing: border-box;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  background: var(--color-surface-soft);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 650;
}
.task-status-control--done {
  color: var(--color-success);
  background: #f0f8f4;
}
.task-status-control--skipped {
  color: var(--color-text-muted);
  background: var(--color-surface-soft);
}
.task-status-control__text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.task-status-control svg {
  flex: none;
}
select {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  font-size: 16px;
  cursor: pointer;
}
select:disabled {
  cursor: default;
}
.task-status-control:has(select:focus-visible) {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
</style>
