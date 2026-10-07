<script setup lang="ts">
import { ref } from 'vue'
import AppModal from '@/components/app/AppModal.vue'
import AppButton from '@/components/ui/AppButton.vue'
import type { DailyStructure } from '@/types/diets.ts'

defineProps<{
  dailyStructure: DailyStructure[]
}>()

const show = ref(false)
</script>
<template>
  <AppModal v-model="show" title="Structura zilnică a dietei" :width="600" :height="400">
    <div>HAHHAHA</div>
  </AppModal>
  <table class="diet-daily-structure-table">
    <thead>
      <tr>
        <th colspan="2">Day</th>
        <th colspan="3">Actions</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(day, index) in dailyStructure" :key="index">
        <td>{{ index + 1 }}</td>
        <td width="80%">{{ day.name }}</td>
        <td>
          <button :disabled="index === 0" class="diet-daily-structure-action__btn">↑</button>
        </td>
        <td>
          <button
            :disabled="index === dailyStructure.length - 1"
            class="diet-daily-structure-action__btn"
          >
            ↓
          </button>
        </td>
        <td>
          <button class="diet-daily-structure-action__btn delete">×</button>
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
