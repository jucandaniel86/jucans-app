<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'
import AppButton from '@/components/ui/AppButton.vue'
import AppCard from '@/components/ui/AppCard.vue'
import TrashIcon from '@/components/icons/trashIcon.vue'
import placeholder from '@/assets/jucans_logo.png'
import { DietStatus, type Diet } from '@/types/diets'

import { dietsApi } from '@/services/dietsApi'
import AppModal from '@/components/app/AppModal.vue'
import AddDraftDietView from './partials/AddDraftDiet.view.vue'

const router = useRouter()
const notifications = useNotificationStore()
const showAddDietModal = ref(false)
const loadingDraftDietSave = ref(false)
const diets = ref<Diet[]>([])
const loading = ref(true)
const failed = ref(false)
const deletingDietId = ref<number | null>(null)
const selectedStatus = ref<DietStatus | 'all'>('all')
const filters = [
  { name: 'all', label: 'Toate' },
  { name: DietStatus.ACTIVE, label: 'Active' },
  { name: DietStatus.COMPLETED, label: 'Finalizate' },
  { name: DietStatus.DRAFT, label: 'Draft' },
] as const
const statusLabels: Record<DietStatus, string> = {
  [DietStatus.ACTIVE]: 'Activă',
  [DietStatus.COMPLETED]: 'Finalizată',
  [DietStatus.DRAFT]: 'Draft',
}
const visibleDiets = computed(() =>
  diets.value
    .filter((diet) => selectedStatus.value === 'all' || diet.status === selectedStatus.value)
    .map((diet) => ({
      diet,
      description: plainDescription(diet.description),
      image: imageUrl(diet),
    })),
)

function plainDescription(html: string | null): string {
  if (!html) return ''
  const document = new DOMParser().parseFromString(html, 'text/html')
  document.querySelectorAll('script, style, template').forEach((node) => node.remove())
  document.querySelectorAll('p, div, li, br, h1, h2, h3, h4, h5, h6').forEach((node) => {
    node.append(' ')
  })
  return (document.body.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function imageUrl(diet: Diet): string | null {
  const path = diet.thumbnail_url || diet.thumbnail
  if (!path) return null
  return new URL(path, new URL('/', import.meta.env.VITE_API_BASE_URL || window.location.origin))
    .href
}

async function loadDiets(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    diets.value = (await dietsApi.getDiets()).data
  } catch (error) {
    failed.value = true
    notifications.apiError(error, 'Nu am putut încărca dietele. Încearcă din nou.')
  } finally {
    loading.value = false
  }
}

async function deleteDiet(diet: Diet): Promise<void> {
  if (deletingDietId.value !== null) return
  deletingDietId.value = diet.id
  try {
    const confirmed = await notifications.confirm({
      title: 'Șterge dieta',
      message: `Sigur vrei să ștergi dieta „${diet.name}”? Această acțiune este definitivă.`,
      confirmText: 'Șterge',
      cancelText: 'Anulează',
    })
    if (!confirmed) return
    await dietsApi.deleteDiet(diet.id)
    diets.value = diets.value.filter((item) => item.id !== diet.id)
    notifications.success(`Dieta „${diet.name}” a fost ștearsă.`)
  } catch (error) {
    notifications.apiError(error, 'Nu am putut șterge dieta.')
  } finally {
    deletingDietId.value = null
  }
}

onMounted(loadDiets)

//methods
const handleSaveDiet = async (data: { name: string }) => {
  loadingDraftDietSave.value = true
  try {
    const response = await dietsApi.createDraftDiet(data)
    showAddDietModal.value = false
    notifications.success(`Dieta "${response.data.name}" a fost creată cu succes!`)
    await router.push({ name: 'diet-edit', params: { id: response.data.id } })
  } catch (error) {
    notifications.apiError(error, 'A apărut o eroare la crearea dietei. Încearcă din nou.')
  } finally {
    loadingDraftDietSave.value = false
  }
}
</script>

<template>
  <section class="diets-page">
    <header class="diets-page__header">
      <div>
        <p>Food</p>
        <h1>Dietele noastre <span aria-hidden="true">🍳</span></h1>
      </div>

      <AppButton
        class="diets-page__add"
        block
        variant="secondary"
        aria-label="Adaugă o dietă"
        title="Adaugă o dietă"
        @click="showAddDietModal = true"
      >
        <span aria-hidden="true">+</span>
      </AppButton>
    </header>
    <div
      class="app__tabs diets-page__filters"
      role="group"
      aria-label="Filtrează dietele după status"
    >
      <button
        v-for="filter in filters"
        :key="filter.name"
        type="button"
        :aria-pressed="selectedStatus === filter.name"
        @click="selectedStatus = filter.name"
      >
        {{ filter.label }}
      </button>
    </div>
    <div :aria-busy="loading" aria-live="polite">
      <p v-if="loading" class="diets-page__state" role="status">Se încarcă dietele…</p>
      <div v-else-if="failed" class="diets-page__state">
        <p>Lista dietelor nu este disponibilă momentan.</p>
        <AppButton variant="secondary" @click="loadDiets">Încearcă din nou</AppButton>
      </div>
      <p v-else-if="!diets.length" class="diets-page__state">Nu există diete încă.</p>
      <p v-else-if="!visibleDiets.length" class="diets-page__state">
        Nu există diete pentru filtrul selectat.
      </p>
      <div v-else class="diets-page__list">
        <AppCard
          v-for="{ diet, description, image } in visibleDiets"
          :key="diet.id"
          class="diet-card"
        >
          <RouterLink
            :to="{ name: 'diet-detail', params: { id: diet.id } }"
            class="diet-card__link"
            :aria-label="`Deschide dieta ${diet.name}`"
          >
            <img
              :src="image || placeholder"
              :alt="image ? `Imagine ${diet.name}` : ''"
              class="diet-card__image"
              :class="{ 'diet-card__image--placeholder': !image }"
              loading="lazy"
            />
            <div class="diet-card__body">
              <h2>{{ diet.name }}</h2>
              <p v-if="description" class="diet-card__description">{{ description }}</p>
              <span class="diet-card__status" :class="`diet-card__status--${diet.status}`">{{
                statusLabels[diet.status]
              }}</span>
            </div>
          </RouterLink>
          <button
            class="diet-card__delete"
            type="button"
            :aria-label="`Șterge dieta ${diet.name}`"
            :title="`Șterge dieta ${diet.name}`"
            :disabled="deletingDietId !== null"
            :aria-busy="deletingDietId === diet.id"
            @click.stop.prevent="deleteDiet(diet)"
          >
            <TrashIcon />
          </button>
        </AppCard>
      </div>
    </div>
  </section>
  <AppModal v-model="showAddDietModal" title="Adaugă o dietă">
    <AddDraftDietView @on-save="handleSaveDiet" :loading="loadingDraftDietSave" />
  </AppModal>
</template>

<style scoped>
@import '../../components/app/styles/AppTabs.style.css';
@import './styles/diets.style.css';
</style>
