<script setup lang="ts">
// import { useNotificationStore } from '@/stores/notifications'
import { watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { dietsApi } from '@/services/dietsApi'

import { useDiet } from '@/composables/useDiet'
//components
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import AppTabs from '@/components/app/AppTabs.vue'
import DietDailyStructure from './partials/DietDailyStructure.view.vue'
import type { DailyStructureConfig } from '@/types/diets.ts'
import DietSources from './partials/DietSources.view.vue'

//models
const loading = ref(false)
const loadError = ref<string | null>(null)

//composables
const route = useRoute()
const router = useRouter()
const draftDiet = useDiet()
const activeTab = ref<string>(draftDiet.defaultTab)
const dailyStructure = ref<DailyStructureConfig[]>([])

// const { warning } = useNotificationStore()

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

const saveDiet = async () => {}

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
</script>
<template>
  <section v-if="loading" class="diet-form-state" aria-live="polite">Se încarcă dieta...</section>
  <section v-else-if="loadError" class="diet-form-state" role="alert">
    <p>{{ loadError }}</p>
    <AppButton variant="secondary" @click="router.push({ name: 'diets' })">
      Înapoi la diete
    </AppButton>
  </section>
  <form v-else class="add-diet" novalidate @submit.prevent="saveDiet">
    <header class="add-diet__intro">
      <span class="add-diet__icon" aria-hidden="true">🍳</span>
      <div>
        <p>Food</p>
        <h1 v-html="`Editează dieta <strong>${draftDiet.diet.name || ''}</strong>`" />
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
    <div class="form-section__body">
      <AppInput
        v-model="draftDiet.diet.name"
        label="Numele dietei"
        placeholder="Dieta de slabit"
        :maxlength="255"
      />
      <AppTextarea
        v-model="draftDiet.diet.description"
        label="Descriere"
        placeholder="Ce e special la dieta asta?"
        :rows="4"
      />
    </div>
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
        </div>
      </div>
    </section>
  </form>
</template>
<style lang="css" scoped>
@import './styles/diets-edit.style.css';
</style>
