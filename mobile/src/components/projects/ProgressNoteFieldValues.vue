<script setup lang="ts">
import { useId } from 'vue'
import type {
  NoteConfigurationField,
  NoteCurrentState,
  NoteFieldValue,
} from '@/types/projectProgress'
import { fieldRestriction, optionUnavailable, sameNoteValue } from '@/utils/progressNoteFields'

const props = defineProps<{
  fields: NoteConfigurationField[]
  values: Record<number, NoteFieldValue>
  originals: Map<number, NoteFieldValue>
  current: NoteCurrentState | null
  errors: Record<number, string>
  disabled?: boolean
}>()
const emit = defineEmits<{ change: [id: number, value: NoteFieldValue] }>()
const id = useId()
function original(field: NoteConfigurationField): NoteFieldValue {
  return props.originals.get(field.field_id) ?? null
}
function text(field: NoteConfigurationField, event: Event): void {
  const value = (event.target as HTMLInputElement).value
  emit('change', field.field_id, field.definition.type === 'number' && value === '' ? null : value)
}
function select(field: NoteConfigurationField, event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  emit(
    'change',
    field.field_id,
    value === ''
      ? null
      : field.definition.type === 'boolean'
        ? value === 'true'
        : (field.options.find((option) => String(option.id) === value)?.id ?? null),
  )
}
function selected(field: NoteConfigurationField): string {
  const value = props.values[field.field_id]
  return value === null || value === undefined ? '' : String(value)
}
function blocked(field: NoteConfigurationField): boolean {
  return Boolean(props.disabled || fieldRestriction(field, props.current))
}
</script>
<template>
  <section v-if="fields.length" class="note-field-values" aria-label="Câmpuri personalizate">
    <h3>Câmpuri</h3>
    <div
      v-for="field in fields"
      :key="field.field_id"
      class="note-field-value"
      :data-custom-field="field.field_id"
    >
      <div class="note-field-value__heading">
        <label :for="`${id}-${field.field_id}`"
          >{{ field.definition.name
          }}<span v-if="field.definition.unit"> ({{ field.definition.unit }})</span
          ><span v-if="field.required" aria-hidden="true"> *</span></label
        ><button
          v-if="!field.required && values[field.field_id] !== null"
          type="button"
          :disabled="disabled"
          :aria-label="`Golește ${field.definition.name}`"
          @click="emit('change', field.field_id, null)"
        >
          Golește
        </button>
      </div>
      <input
        v-if="field.definition.type === 'number'"
        :id="`${id}-${field.field_id}`"
        :value="selected(field)"
        type="text"
        inputmode="decimal"
        autocomplete="off"
        :disabled="blocked(field)"
        :required="field.required"
        :aria-invalid="Boolean(errors[field.field_id])"
        :aria-describedby="`${id}-${field.field_id}-help ${id}-${field.field_id}-error`"
        @input="text(field, $event)"
      />
      <textarea
        v-else-if="field.definition.type === 'text'"
        :id="`${id}-${field.field_id}`"
        :value="selected(field)"
        rows="2"
        :disabled="blocked(field)"
        :required="field.required"
        :aria-invalid="Boolean(errors[field.field_id])"
        :aria-describedby="`${id}-${field.field_id}-help ${id}-${field.field_id}-error`"
        @input="text(field, $event)"
      />
      <select
        v-else
        :id="`${id}-${field.field_id}`"
        :value="selected(field)"
        :disabled="blocked(field)"
        :required="field.required"
        :aria-invalid="Boolean(errors[field.field_id])"
        :aria-describedby="`${id}-${field.field_id}-help ${id}-${field.field_id}-error`"
        @change="select(field, $event)"
      >
        <option value="">Nesetat</option>
        <template v-if="field.definition.type === 'boolean'"
          ><option value="true">Da</option>
          <option value="false">Nu</option></template
        >
        <template v-else
          ><option
            v-for="option in field.options"
            :key="option.id"
            :value="String(option.id)"
            :disabled="
              optionUnavailable(field, option.id, current) && option.id !== original(field)
            "
          >
            {{ option.label
            }}{{ optionUnavailable(field, option.id, current) ? ' (indisponibilă)' : '' }}
          </option></template
        >
      </select>
      <div :id="`${id}-${field.field_id}-help`" class="note-field-value__hint">
        <span v-if="field.definition.description">{{ field.definition.description }}</span>
        <span
          v-if="
            field.definition.type === 'number' &&
            [field.definition.min, field.definition.max, field.definition.step].some(
              (value) => value !== null,
            )
          "
          ><template v-if="field.definition.min !== null"
            >Min {{ field.definition.min }} · </template
          ><template v-if="field.definition.max !== null"
            >Max {{ field.definition.max }} · </template
          ><template v-if="field.definition.step !== null"
            >Pas {{ field.definition.step }}</template
          ></span
        >
        <span v-if="fieldRestriction(field, current)">{{ fieldRestriction(field, current) }}</span>
      </div>
      <button
        v-if="
          original(field) !== null &&
          !sameNoteValue(field, values[field.field_id] ?? null, original(field))
        "
        type="button"
        :disabled="disabled"
        @click="emit('change', field.field_id, original(field))"
      >
        Păstrează valoarea salvată
      </button>
      <p
        :id="`${id}-${field.field_id}-error`"
        class="note-editor-field-error"
        :role="errors[field.field_id] ? 'alert' : undefined"
      >
        {{ errors[field.field_id] ?? '' }}
      </p>
    </div>
  </section>
</template>
<style scoped>
.note-field-values {
  display: grid;
  gap: 12px;
  min-width: 0;
}
.note-field-values h3 {
  margin: 0;
  font-size: 0.85rem;
}
.note-field-value {
  display: grid;
  gap: 4px;
  min-width: 0;
}
.note-field-value__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 32px;
}
.note-field-value__heading label {
  overflow-wrap: anywhere;
}
.note-field-value button {
  justify-self: start;
  flex: none;
  min-height: 44px;
  padding: 4px 8px;
  border: 0;
  background: transparent;
  color: var(--color-primary-strong);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}
.note-field-value button:disabled {
  opacity: 0.5;
  cursor: default;
}
.note-field-value input,
.note-field-value select,
.note-field-value textarea {
  box-sizing: border-box;
  min-width: 0;
  width: 100%;
  min-height: 44px;
  padding: 8px 10px;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  font: inherit;
  font-size: 16px;
  color: var(--color-text);
  background: var(--color-surface);
}
.note-field-value textarea {
  min-height: 72px;
  resize: vertical;
}
.note-field-value__hint {
  display: grid;
  gap: 2px;
  font-size: 0.75rem;
  color: var(--color-text-muted);
  overflow-wrap: anywhere;
}
.note-field-value [aria-invalid='true'] {
  border-color: var(--color-error);
}
.note-field-value :disabled {
  background: var(--color-surface-soft);
}
.note-field-value p {
  margin: 0;
}
</style>
