<script setup lang="ts">
import { ref } from 'vue'

import type { FoodTag, FoodTagPayload } from '@/types/food'

const props = defineProps<{
  tags: FoodTag[]
  selectedIds: number[]
  loading: boolean
  creating: boolean
  error: string
}>()

const emit = defineEmits<{
  toggle: [tagId: number]
  create: [payload: FoodTagPayload]
}>()

const addingTag = ref(false)
const newTagName = ref('')
const newTagEmoji = ref('')
const localError = ref('')

function submitTag(): void {
  const name = newTagName.value.trim()

  if (!name) {
    localError.value = 'Scrie numele tag-ului.'
    return
  }

  const emoji = newTagEmoji.value.trim()

  if (Array.from(emoji).length > 16) {
    localError.value = 'Emoji-ul este prea lung.'
    return
  }

  localError.value = ''
  emit('create', { name, emoji: emoji || null })
}

function finishCreation(success: boolean): void {
  if (success) {
    newTagName.value = ''
    newTagEmoji.value = ''
    addingTag.value = false
  }
}

defineExpose({ finishCreation })
</script>

<template>
  <div class="tag-selector">
    <div v-if="loading" class="tag-selector__loading">Se încarcă tag-urile…</div>
    <div v-else class="tag-selector__list">
      <button
        v-for="tag in tags"
        :key="tag.id"
        class="tag-chip"
        :class="{ 'tag-chip--selected': selectedIds.includes(tag.id) }"
        type="button"
        :aria-pressed="selectedIds.includes(tag.id)"
        @click="$emit('toggle', tag.id)"
      >
        {{ tag.emoji ? `${tag.emoji} ${tag.name}` : tag.name }}
      </button>
      <button
        v-if="!addingTag"
        class="tag-chip tag-chip--add"
        type="button"
        @click="addingTag = true"
      >
        <span aria-hidden="true">+</span> Tag
      </button>
    </div>

    <form v-if="addingTag" class="tag-selector__new" @submit.prevent="submitTag">
      <label for="new-food-tag">Tag nou</label>
      <div class="tag-selector__new-row">
        <input
          v-model="newTagEmoji"
          class="tag-selector__emoji"
          type="text"
          maxlength="32"
          autocomplete="off"
          aria-label="Emoji opțional"
          placeholder="🍲"
          :disabled="creating"
          @input="localError = ''"
        />
        <input
          id="new-food-tag"
          v-model="newTagName"
          type="text"
          maxlength="255"
          autocomplete="off"
          :disabled="creating"
          @input="localError = ''"
        />
        <button type="submit" :disabled="creating">
          {{ creating ? 'Se salvează…' : 'Adaugă' }}
        </button>
        <button
          class="tag-selector__cancel"
          type="button"
          aria-label="Renunță la tag-ul nou"
          :disabled="creating"
          @click="addingTag = false"
        >
          ×
        </button>
      </div>
      <p v-if="localError || props.error" class="tag-selector__error" role="alert">
        {{ localError || props.error }}
      </p>
    </form>
    <p v-else-if="error" class="tag-selector__error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.tag-selector {
  display: grid;
  gap: var(--space-3);
}

.tag-selector__loading {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.tag-selector__list {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}

.tag-chip {
  min-height: 42px;
  padding: 0 var(--space-4);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-pill);
  color: var(--color-text-muted);
  font-weight: 720;
  background: var(--color-surface);
  cursor: pointer;
  transition:
    transform var(--transition-fast),
    color var(--transition-fast),
    border-color var(--transition-fast),
    background-color var(--transition-fast);
}

.tag-chip--selected {
  border-color: var(--color-primary);
  color: var(--color-primary-strong);
  background: var(--color-primary-soft);
  transform: scale(1.03);
}

.tag-chip--add {
  border-style: dashed;
  color: var(--color-pink);
}

.tag-chip:active {
  transform: scale(0.96);
}

.tag-selector__new {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  border-radius: var(--radius-md);
  background: var(--color-pink-soft);
}

.tag-selector__new label {
  font-size: 0.82rem;
  font-weight: 750;
}

.tag-selector__new-row {
  display: grid;
  grid-template-columns: 52px minmax(0, 1fr) auto 44px;
  gap: var(--space-2);
}

.tag-selector__new .tag-selector__emoji {
  padding: 0;
  font-size: 1.35rem;
  text-align: center;
}

@media (max-width: 430px) {
  .tag-selector__new-row {
    grid-template-columns: 52px minmax(0, 1fr) 44px;
  }

  .tag-selector__new-row button[type='submit'] {
    grid-column: 1 / -1;
    grid-row: 2;
  }
}

.tag-selector__new input {
  min-width: 0;
  min-height: 46px;
  padding: 0 var(--space-3);
  border: 1.5px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  color: var(--color-text);
  background: var(--color-surface);
}

.tag-selector__new button {
  min-height: 44px;
  padding: 0 var(--space-3);
  border: 0;
  border-radius: var(--radius-sm);
  color: #fff;
  font-weight: 750;
  background: var(--color-pink);
}

.tag-selector__new .tag-selector__cancel {
  padding: 0;
  color: var(--color-text-muted);
  font-size: 1.4rem;
  background: var(--color-surface);
}

.tag-selector__error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.82rem;
  font-weight: 620;
}

@media (prefers-reduced-motion: reduce) {
  .tag-chip--selected {
    transform: none;
  }
}
</style>
