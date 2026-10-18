<script setup lang="ts">
import { useNotificationStore } from '@/stores/notifications'
import { watch, ref, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { dietsApi } from '@/services/dietsApi'

import { useDiet } from '@/composables/useDiet'
//components
import AppButton from '@/components/ui/AppButton.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import DietGeneralInfo from './partials/DietGeneralInfo.vue'
import AppTabs from '@/components/app/AppTabs.vue'
import DietDailyStructure from './partials/DietDailyStructure.view.vue'
import { DietStatus, type DailyStructureConfig, type Diet } from '@/types/diets.ts'
import DietSources from './partials/DietSources.view.vue'
import DietIngredients from './partials/DietIngredients.vue'
import DietRecipes from './partials/DietRecipes.vue'

//models
const loading = ref(false)
const loadError = ref<string | null>(null)

//composables
const route = useRoute()
const router = useRouter()
const draftDiet = useDiet()
const activeTab = ref<string>(draftDiet.defaultTab)
const dailyStructure = ref<DailyStructureConfig[]>([])

const notifications = useNotificationStore()
const selectedStatus = ref<DietStatus>(draftDiet.diet.status)
const savingStatus = ref(false)
const statusLabels: Record<DietStatus, string> = {
  [DietStatus.DRAFT]: 'Draft',
  [DietStatus.COMPLETED]: 'Finalizată',
  [DietStatus.ACTIVE]: 'Activă',
}
const statusOptions = Object.entries(statusLabels).map(([value, label]) => ({ value, label }))

watch(
  () => [draftDiet.diet.id, draftDiet.diet.status],
  () => {
    selectedStatus.value = draftDiet.diet.status
  },
)

async function saveStatus(): Promise<void> {
  if (savingStatus.value || loading.value) return
  const dietId = draftDiet.diet.id
  const requestedStatus = selectedStatus.value
  savingStatus.value = true
  try {
    const response = await dietsApi.updateDietStatus(
      dietId,
      requestedStatus,
      draftDiet.diet.name,
    )
    if (draftDiet.diet.id !== dietId || Number(route.params.id) !== dietId) return
    if (response.data.status !== requestedStatus) {
      notifications.error(
        'Statusul dietei nu a fost actualizat de server. Încearcă din nou.',
      )
      return
    }
    draftDiet.diet.status = response.data.status
    draftDiet.diet.updated_at = response.data.updated_at
    selectedStatus.value = response.data.status
    notifications.success('Statusul dietei a fost salvat.')
  } catch (error) {
    notifications.apiError(error, 'Nu am putut salva statusul dietei.')
  } finally {
    savingStatus.value = false
  }
}

//methods
const loadDiet = async () => {
  const dietId = Number(route.params.id)

  if (!Number.isInteger(dietId) || dietId < 1) {
    loadError.value = 'Dieta nu este validă.'
    loading.value = false
    throw new Error('Invalid diet ID')
  }

  try {
    return await dietsApi.getDiet(dietId)
  } catch {
    throw new Error('Failed to load diet')
  }
}

const loadDailyStructure = async () => await dietsApi.getDailyStructureConfig()

const updateGeneralInfo = (diet: Diet) => {
  Object.assign(draftDiet.diet, {
    name: diet.name,
    description: diet.description,
    notes: diet.notes,
    thumbnail: diet.thumbnail,
    thumbnail_url: diet.thumbnail_url,
    updated_at: diet.updated_at,
  })
}

watch(
  () => route.params.id,
  async () => {
    loading.value = true
    loadError.value = null
    try {
      const [dietResponse, dailyStructureResponse] = await Promise.all([
        loadDiet(),
        loadDailyStructure(),
      ])
      draftDiet.hydrateDiet(dietResponse.data)
      dailyStructure.value = dailyStructureResponse.data
    } catch (error) {
      console.error('Error loading diet or daily structure:', error)
      loadError.value = 'Nu am putut încărca dieta pentru editare.'
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

watch(
  [loading, () => route.query.tab],
  async () => {
    if (loading.value || loadError.value || route.query.tab !== 'recipes') return
    await nextTick()
    const heading = document.getElementById('diet-recipes-heading')
    heading?.focus({ preventScroll: true })
    heading?.scrollIntoView({ block: 'start' })
  },
  { flush: 'post', immediate: true },
)
</script>
<template>
  <section v-if="loading" class="diet-form-state" aria-live="polite">Se încarcă dieta...</section>
  <section v-else-if="loadError" class="diet-form-state" role="alert">
    <p>{{ loadError }}</p>
    <AppButton variant="secondary" @click="router.push({ name: 'diets-lists' })">
      Înapoi la diete
    </AppButton>
  </section>
  <div v-else class="add-diet">
    <AppButton variant="secondary" @click="router.push({ name: 'diets-lists' })">
      Înapoi la diete
    </AppButton>
    <header class="add-diet__intro">
      <span class="add-diet__icon" aria-hidden="true">🍳</span>
      <div>
        <p>Food</p>
        <h1>
          Editează dieta <strong>{{ draftDiet.diet.name }}</strong>
        </h1>
      </div>
    </header>

    <section class="form-section">
      <header class="form-section__header">
        <span>1</span>
        <div>
          <h2>Despre dieta</h2>
          <p>Doar informațiile care te ajută s-o recunoști rapid.</p>
        </div>
      </header>
    </section>
    <DietGeneralInfo :key="draftDiet.diet.id" :diet="draftDiet.diet" @updated="updateGeneralInfo" />
    <section class="form-section">
      <header class="form-section__header">
        <span>2</span>
        <div>
          <h2>Setari</h2>
          <p>Setarile dietei.</p>
        </div>
      </header>
      <div class="form-section__body">
        <AppTabs v-model="activeTab" :tabs="draftDiet.activeTabs" />
        <div class="app__tabs-content">
          <DietDailyStructure
            v-if="activeTab === 'daily-structure'"
            :daily-structure-config="dailyStructure"
            :daily-structure="draftDiet.diet.daily_structure"
            :diet-id="draftDiet.diet.id"
            @reload-list="draftDiet.diet.daily_structure = $event"
          />
          <DietSources
            v-else-if="activeTab === 'sources'"
            :items="draftDiet.diet.sources"
            :diet-id="draftDiet.diet.id"
            @reload-list="draftDiet.diet.sources = $event"
          />

          <DietIngredients
            v-else-if="activeTab === 'ingredients'"
            :diet-id="draftDiet.diet.id"
            :ingredients="draftDiet.diet.ingredients"
            @reload-list="draftDiet.diet.ingredients = $event"
          />
        </div>
      </div>
    </section>
    <section class="form-section" aria-labelledby="diet-recipes-heading">
      <header class="form-section__header">
        <span>3</span>
        <div>
          <h2 id="diet-recipes-heading" tabindex="-1">Rețete</h2>
          <p>Rețetele asociate dietei și prioritățile lor.</p>
        </div>
      </header>
      <div class="form-section__body">
        <DietRecipes
          :key="draftDiet.diet.id"
          :diet-id="draftDiet.diet.id"
          :recipes="draftDiet.diet.recipes ?? []"
          @reload-list="draftDiet.diet.recipes = $event"
        />
      </div>
    </section>
    <section class="form-section" aria-labelledby="diet-status-heading">
      <header class="form-section__header">
        <span>4</span>
        <div>
          <h2 id="diet-status-heading">Finalizare</h2>
          <p aria-live="polite">Status curent: {{ statusLabels[draftDiet.diet.status] }}</p>
        </div>
      </header>
      <form class="form-section__body" :aria-busy="savingStatus" @submit.prevent="saveStatus">
        <AppSelect
          :model-value="selectedStatus"
          label="Status dietă"
          :options="statusOptions"
          :disabled="savingStatus"
          @update:model-value="selectedStatus = $event as DietStatus"
        />
        <AppButton type="submit" :loading="savingStatus">Salvează status</AppButton>
      </form>
    </section>
  </div>
</template>
<style lang="css" scoped>
@import './styles/diets-edit.style.css';

#diet-recipes-heading {
  scroll-margin-top: calc(80px + env(safe-area-inset-top, 0px));
}
</style>
