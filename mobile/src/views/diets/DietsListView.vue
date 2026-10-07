<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'
import AppButton from '@/components/ui/AppButton.vue'

import { dietsApi } from '@/services/dietsApi'
import AppModal from '@/components/app/AppModal.vue'
import AddDraftDietView from './partials/AddDraftDiet.view.vue'

const router = useRouter()
const notifications = useNotificationStore()
const showAddDietModal = ref(false)
const loadingDraftDietSave = ref(false)

//methods
const handleSaveDiet = async (data: { name: string }) => {
  loadingDraftDietSave.value = true
  try {
    const response = await dietsApi.createDraftDiet(data)
    showAddDietModal.value = false
    notifications.success(`Dieta "${response.data.name}" a fost creată cu succes!`)
    await router.push({ name: 'diet-edit', params: { id: response.data.id } })
  } catch (error) {
    console.error('Error creating draft diet:', error)
    notifications.error('A apărut o eroare la crearea dietei. Încearcă din nou.')
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

      <AppButton class="diets-page__add" block variant="secondary" @click="showAddDietModal = true">
        <span aria-hidden="true">+</span>
      </AppButton>
    </header>
  </section>
  <AppModal v-model="showAddDietModal" title="Adaugă o dietă">
    <AddDraftDietView @on-save="handleSaveDiet" :loading="loadingDraftDietSave" />
  </AppModal>
</template>

<style scoped>
@import './styles/diets.style.css';
</style>
