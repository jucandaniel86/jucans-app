<script setup lang="ts">
import type { ProgressNote } from '@/types/projectProgress'
import { displayNoteValue } from '@/utils/progressNoteFields'
defineProps<{ note: ProgressNote }>()
</script>
<template>
  <details v-if="note.note_type_id && note.configuration" class="progress-note-values">
    <summary>
      {{ note.configuration.note_type.name }}
      <span v-if="note.configuration.fields.length"
        >· {{ note.configuration.fields.length }} câmpuri</span
      ><span v-if="note.current_configuration_state?.note_type_archived_at"> · Arhivat</span>
    </summary>
    <dl>
      <div v-for="field in note.configuration.fields" :key="field.field_id">
        <dt>
          {{ field.definition.name
          }}<span v-if="field.definition.unit"> ({{ field.definition.unit }})</span
          ><small v-if="field.required"> · Obligatoriu</small>
        </dt>
        <dd>
          {{
            displayNoteValue(
              field,
              note.values?.find((value) => value.field_id === field.field_id)?.value ?? null,
            )
          }}
        </dd>
      </div>
    </dl>
  </details>
</template>
<style scoped>
.progress-note-values {
  min-width: 0;
  font-size: 0.82rem;
}
.progress-note-values summary {
  min-height: 44px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  color: var(--color-primary-strong);
  cursor: pointer;
}
.progress-note-values summary::before {
  content: '›';
  margin-right: 4px;
}
.progress-note-values[open] summary::before {
  transform: rotate(90deg);
}
.progress-note-values dl {
  display: grid;
  gap: 8px;
  margin: 0;
}
.progress-note-values dl > div {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 8px;
}
.progress-note-values dt,
.progress-note-values dd {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.progress-note-values dd {
  margin: 0;
}
.progress-note-values small {
  color: var(--color-text-muted);
}
</style>
