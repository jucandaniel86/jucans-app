<script setup lang="ts">
import { dietSourceTypes } from '@/config/dietSources'
import type { DietSource } from '@/types/diets'

import PencilIcon from '@/components/icons/pencilIcon.vue'
import TrashIcon from '@/components/icons/trashIcon.vue'

const props = defineProps<{
  item: DietSource
  readonly?: boolean
}>()

const emit = defineEmits<{
  edit: [source: DietSource]
  delete: [sourceId: number]
}>()
</script>
<template>
  <div class="source-card">
    <div class="source-card__title">
      <span>
        {{ dietSourceTypes[item.type].icon }}

        {{ item.title }}</span
      >
      <span v-if="item.is_official" class="source-card__oficial">Oficial</span>
    </div>
    <div v-if="item.url" class="source-card__link">
      <a :href="item.url" target="_blank" rel="noopener noreferrer">{{ item.url }}</a>
    </div>
    <p v-if="readonly && item.notes" class="source-card__notes">{{ item.notes }}</p>
    <div v-if="!readonly" class="source-card__actions">
      <button
        type="button"
        class="source-card-action__btn"
        @click="emit('edit', props.item)"
        aria-label="Editează sursa"
      >
        <PencilIcon />
        Editare
      </button>
      <button
        type="button"
        class="source-card-action__btn delete"
        aria-label="Șterge sursa"
        @click="emit('delete', props.item.id)"
      >
        <TrashIcon />
      </button>
    </div>
  </div>
</template>
<style scoped lang="css">
@import url(../styles/source-card.style.css);

.source-card__notes {
  margin: 0;
  white-space: pre-wrap;
}
</style>
