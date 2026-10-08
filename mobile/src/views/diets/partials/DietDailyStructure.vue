<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { DailyStructureConfig, DietDailyStructure } from '@/types/diets.ts'
import { dietsApi } from '@/services/dietsApi'
import { useNotificationStore } from '@/stores/notifications'
//components
import AppModal from '@/components/app/AppModal.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppSelect from '@/components/ui/AppSelect.vue'

const props = defineProps<{
  dailyStructureConfig: DailyStructureConfig[]
  dailyStructure: DietDailyStructure[]
  dietId: number
}>()

//models
const show = ref(false)
const addMealType = ref<number | null>(null)
const saveState = ref(false)
const items = ref<DietDailyStructure[]>([])

//composables
const { error, success } = useNotificationStore()

//computed
const dailyStructureOptions = computed(() =>
  props.dailyStructureConfig.map((item) => ({
    value: item.id,
    label: item.name,
  })),
)

//methods
const saveDailyStructure = async () => {
  saveState.value = true
  try {
    const response = await dietsApi.addDailyStructure(props.dietId, {
      daily_structure_id: addMealType.value,
    })
    items.value = response.data
    show.value = false
    addMealType.value = null
    success('Structura mesei a fost adaugat cu succes!')
  } catch (err) {
    error('A aparut o eroare.')
    console.warn(err)
  } finally {
    saveState.value = false
  }
}

const changeOrder = async (itemId: number, direction: 'up' | 'down') => {
  const response = await dietsApi.changeDailyStructureOrder(props.dietId, itemId, direction)
  success('Ordinea a fost schimbata cu succes!')
  items.value = response.data
}

const deleteItem = async (itemId: number) => {
  const response = await dietsApi.deleteDailyStructure(props.dietId, itemId)
  success('Tipul a fost sters cu succes!')
  items.value = response.data
}

//watch
watch(
  () => props.dailyStructure,
  (dailyStructure) => {
    items.value = [...dailyStructure]
  },
  { immediate: true },
)
</script>
<template>
  <AppModal v-model="show" title="Structura zilnică a dietei">
    <AppSelect v-model="addMealType" label="Tipul mesei" :options="dailyStructureOptions as any" />
    <AppButton
      type="button"
      class="save-meal-type__btn"
      @click.prevent="saveDailyStructure"
      :loading="saveState"
      >Save</AppButton
    >
  </AppModal>
  <table class="diet-daily-structure-table">
    <thead>
      <tr>
        <th>Tip</th>
        <th colspan="3">Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(item, index) in items" :key="item.id">
        <td width="80%">{{ item.structure.name }}</td>
        <td>
          <button
            :disabled="index === 0"
            class="diet-daily-structure-action__btn"
            @click.prevent="changeOrder(item.id, 'up')"
          >
            ↑
          </button>
        </td>
        <td>
          <button
            :disabled="index === items.length - 1"
            class="diet-daily-structure-action__btn"
            @click.prevent="changeOrder(item.id, 'down')"
          >
            ↓
          </button>
        </td>
        <td>
          <button
            class="diet-daily-structure-action__btn delete"
            @click.prevent="deleteItem(item.id)"
          >
            ×
          </button>
        </td>
      </tr>
    </tbody>
  </table>
  <AppButton
    class="diet-daily-structure-table__add-day-button"
    variant="primary"
    size="small"
    type="button"
    @click="show = true"
  >
    + Adaugă masă
  </AppButton>
</template>
<style scoped src="../styles/daily-structure.style.css"></style>
