<script setup lang="ts">
import type { ProgressNote } from '@/types/projectProgress'
import { projectIcon } from '@/utils/projectPresentation'
import { displayNoteValue } from '@/utils/progressNoteFields'
defineProps<{ note: ProgressNote }>()
</script>
<template>
  <details v-if="note.note_type_id && note.configuration" class="progress-note-values">
    <summary>
      <span aria-hidden="true">{{ projectIcon('folder') }}</span>
      <span class="progress-note-values__label"
        >{{ note.configuration.note_type.name }} ·
        {{ note.configuration.fields.length }} câmpuri<span
          v-if="note.current_configuration_state?.note_type_archived_at"
        >
          · Arhivat</span
        ></span
      >
      <span class="progress-note-values__chevron" aria-hidden="true">›</span>
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
.progress-note-values summary::-webkit-details-marker {
  display: none;
}
.progress-note-values summary {
  list-style: none;
  flex-wrap: nowrap;
}
.progress-note-values__label {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}
.progress-note-values__chevron {
  flex: none;
  font-size: 1.2rem;
}
.progress-note-values[open] .progress-note-values__chevron {
  transform: rotate(90deg);
}
.progress-note-values dl {
  display: grid;
  gap: 4px;
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
