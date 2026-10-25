<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import AppButton from '@/components/ui/AppButton.vue'
import { meditationsApi } from '@/services/meditationsApi'
import { useNotificationStore } from '@/stores/notifications'
import type { Meditation } from '@/types/meditation'

const notifications = useNotificationStore()
const meditations = ref<Meditation[]>([])
const loading = ref(true)
const loadError = ref(false)
const selectedCategory = ref('')
const selectedMeditation = ref<Meditation | null>(null)
const audio = ref<HTMLAudioElement | null>(null)
const playing = ref(false)
const playbackError = ref('')
let playbackRequest = 0
let disposed = false

const categories = computed(() => [...new Set(meditations.value.map((item) => item.category))])
const visibleMeditations = computed(() =>
  meditations.value.filter((item) => item.category === selectedCategory.value),
)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    const response = await meditationsApi.list()
    if (disposed) return
    meditations.value = response.data
    selectedCategory.value = categories.value[0] ?? ''
  } catch (error) {
    if (disposed) return
    loadError.value = true
    notifications.apiError(error)
  } finally {
    if (!disposed) loading.value = false
  }
}

function stop(): void {
  playbackRequest++
  audio.value?.pause()
  audio.value?.removeAttribute('src')
  audio.value?.load()
  selectedMeditation.value = null
  playing.value = false
  playbackError.value = ''
}

function selectCategory(category: string): void {
  if (category === selectedCategory.value) return
  stop()
  selectedCategory.value = category
}

function navigateCategories(event: KeyboardEvent, index: number): void {
  const count = categories.value.length
  const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? count - 1
    : event.key === 'ArrowRight' ? (index + 1) % count
      : event.key === 'ArrowLeft' ? (index - 1 + count) % count : null
  if (nextIndex === null) return
  const category = categories.value[nextIndex]
  if (category === undefined) return
  event.preventDefault()
  selectCategory(category)
  document.getElementById(`meditation-tab-${nextIndex}`)?.focus()
}

async function togglePlayback(meditation: Meditation): Promise<void> {
  const player = audio.value
  if (!player) return

  const request = ++playbackRequest
  playbackError.value = ''
  if (selectedMeditation.value?.id !== meditation.id) {
    player.pause()
    playing.value = false
    selectedMeditation.value = meditation
    player.src = meditation.audio
    player.load()
  } else if (!player.paused) {
    player.pause()
    return
  }

  try {
    await player.play()
  } catch {
    if (!disposed && request === playbackRequest) {
      playbackError.value = 'Nu am putut reda înregistrarea. Încearcă din nou.'
    }
  }
}

onMounted(load)
onBeforeUnmount(() => {
  disposed = true
  stop()
})
</script>

<template>
  <section class="meditation-page">
    <header><h1>Meditation</h1></header>

    <p v-if="loading" class="meditation-page__state" role="status">Se încarcă meditațiile…</p>
    <div v-else-if="loadError" class="meditation-page__state" role="alert">
      <p>Nu am putut încărca meditațiile.</p>
      <AppButton variant="secondary" @click="load">Încearcă din nou</AppButton>
    </div>
    <p v-else-if="!meditations.length" class="meditation-page__state">
      Nu există meditații disponibile.
    </p>

    <template v-else>
      <div class="meditation-page__tabs" role="tablist" aria-label="Categorii de meditații">
        <button
          v-for="(category, index) in categories"
          :id="`meditation-tab-${index}`"
          :key="category"
          type="button"
          role="tab"
          :aria-selected="selectedCategory === category"
          :tabindex="selectedCategory === category ? 0 : -1"
          aria-controls="meditation-list"
          @click="selectCategory(category)"
          @keydown="navigateCategories($event, index)"
        >
          {{ category }}
        </button>
      </div>

      <div
        id="meditation-list"
        role="tabpanel"
        :aria-labelledby="`meditation-tab-${categories.indexOf(selectedCategory)}`"
      >
        <ul class="meditation-page__list">
          <li v-for="meditation in visibleMeditations" :key="meditation.id">
            <span>{{ meditation.title }}</span>
            <AppButton
              variant="secondary"
              :aria-label="`${selectedMeditation?.id === meditation.id && playing ? 'Pause' : 'Play'}: ${meditation.title}`"
              @click="togglePlayback(meditation)"
            >
              {{ selectedMeditation?.id === meditation.id && playing ? 'Pause' : 'Play' }}
            </AppButton>
          </li>
        </ul>
      </div>
    </template>

    <div v-show="selectedMeditation" class="meditation-page__player">
      <p id="meditation-player-title">{{ selectedMeditation?.title }}</p>
      <audio
        ref="audio"
        controls
        preload="none"
        aria-labelledby="meditation-player-title"
        @play="playing = true; playbackError = ''"
        @pause="playing = false"
        @ended="playing = false"
        @error="selectedMeditation && (playbackError = 'Fișierul audio nu poate fi redat.')"
      />
      <p v-if="playbackError" role="alert">{{ playbackError }}</p>
    </div>
  </section>
</template>

<style scoped>
.meditation-page {
  isolation: isolate;
  display: grid;
  min-width: 0;
  min-height: calc(100dvh - 140px);
  align-content: start;
  gap: 16px;
  color: #171717;
  background: #fff;
}

.meditation-page::before {
  position: fixed;
  inset: 0;
  z-index: -1;
  background: #fff;
  pointer-events: none;
  content: '';
}

.meditation-page h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  line-height: 1.15;
}

.meditation-page__tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding: 3px;
}

.meditation-page__tabs button {
  flex: 0 0 auto;
  min-height: 44px;
  padding: 0 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  color: #222;
  background: #f5f5f5;
  font-size: 12px;
  cursor: pointer;
}

.meditation-page__tabs button[aria-selected='true'] {
  border-color: #222;
  color: #fff;
  background: #222;
}

.meditation-page__list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.meditation-page__list li {
  display: flex;
  min-height: 60px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid #e9e9e9;
  font-size: 14px;
}

.meditation-page__list li > span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.meditation-page :deep(.app-button) {
  flex-shrink: 0;
  min-height: 44px;
  padding: 0 16px;
  border-color: #ddd;
  border-radius: 4px;
  color: #222;
  background: #fff;
  box-shadow: none;
  font-size: 13px;
  font-weight: 500;
}

.meditation-page__player {
  display: grid;
  min-width: 0;
  gap: 8px;
  padding: 12px;
  border: 1px solid #e9e9e9;
  border-radius: 4px;
  background: #fafafa;
}

.meditation-page__player p {
  margin: 0;
  overflow-wrap: anywhere;
  font-size: 13px;
}

.meditation-page__player audio {
  width: 100%;
  min-width: 0;
  color-scheme: light;
}

.meditation-page__state {
  padding: 32px 0;
  color: #777;
  text-align: center;
  font-size: 13px;
}

.meditation-page button:focus-visible,
.meditation-page audio:focus-visible {
  outline: 2px solid #555;
  outline-offset: 2px;
}
</style>
