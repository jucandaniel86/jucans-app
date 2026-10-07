<script setup lang="ts">
import { ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'

//props
defineProps<{ loading: boolean }>()

//models
const dietName = ref('')

//emits
const emits = defineEmits<{
  onSave: [data: { name: string }]
}>()

//methods
const saveDiet = () => {
  emits('onSave', { name: dietName.value })
}
</script>

<template>
  <div class="form-section__body di-card">
    <AppInput
      v-model="dietName"
      label="Numele dietei"
      placeholder="Ex: Dieta de slabit"
      :maxlength="255"
    />
    <AppButton @click="saveDiet" :loading="loading">Save</AppButton>
  </div>
</template>
<style lang="css" scoped>
.di-card__name {
  font-size: 1.12rem;
  font-weight: 800;
}
.form-section__body {
  display: grid;
  gap: var(--space-4);
}
</style>
