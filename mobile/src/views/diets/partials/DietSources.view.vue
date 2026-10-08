<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { dietsApi } from '@/services/dietsApi.ts'
import { DietSourceType, type DietSource } from '@/types/diets'
import { dietSourceTypes } from '@/config/dietSources.ts'
import { useNotificationStore } from '@/stores/notifications.ts'
//components
import AppButton from '@/components/ui/AppButton.vue'
import AppModal from '@/components/app/AppModal.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import SourceCard from '../components/SourceCard.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'

//props
const props = defineProps<{
  items: DietSource[]
  dietId: number
}>()

//emits
const emit = defineEmits<{
  reloadList: [items: DietSource[]]
}>()

//models
const showModal = ref(false)
const editingItem = ref<DietSource | null>(null)
const saving = ref(false)
const sourceOptions = Object.entries(dietSourceTypes).map(([value, config]) => ({
  value,
  label: `${config.icon} ${config.label}`,
}))
const sourceItemForm = reactive<DietSource>({
  id: 0,
  title: '',
  type: DietSourceType.ARTICLE,
  url: '',
  notes: '',
  is_official: false,
})

//composables
const notifications = useNotificationStore()

//methods
const handleEdit = (item: DietSource) => {
  editingItem.value = item
  showModal.value = true
}

const handleAdd = () => {
  editingItem.value = null
  showModal.value = true
}

const handleDelete = async (sourceId: number) => {
  const confirmed = await notifications.confirm({
    title: 'Șterge sursa',
    message: 'Sigur vrei să ștergi această sursă?',
    confirmText: 'Șterge',
    cancelText: 'Anulează',
  })

  if (!confirmed) return

  try {
    const response = await dietsApi.deleteSource(props.dietId, sourceId)

    emit('reloadList', response.data)

    notifications.success('Sursa a fost ștearsă.')
  } catch (err) {
    notifications.apiError(err, 'Nu am putut șterge sursa.')
  }
}

const handleSave = async () => {
  saving.value = true
  const isEditing = editingItem.value !== null

  try {
    const response =
      isEditing && editingItem.value
        ? await dietsApi.updateSource(props.dietId, editingItem.value.id, sourceItemForm)
        : await dietsApi.addSource(props.dietId, sourceItemForm)

    emit('reloadList', response.data)
    showModal.value = false
    notifications.success(isEditing ? 'Sursa a fost actualizată.' : 'Sursa a fost adăugată.')
  } catch (err) {
    console.warn('error', err)
    notifications.apiError(err, 'Nu am putut salva sursa.')
  } finally {
    saving.value = false
    editingItem.value = null
  }
}

watch(editingItem, (currentItem) => {
  if (currentItem) {
    Object.assign(sourceItemForm, currentItem)
    return
  }

  Object.assign(sourceItemForm, {
    id: 0,
    title: '',
    type: DietSourceType.ARTICLE,
    url: '',
    notes: '',
    is_official: false,
  })
})
</script>
<template>
  <AppModal v-model="showModal" title="Salvare surse">
    <div class="diet-source-form__wrapper">
      <AppInput v-model="sourceItemForm.title" label="Title" placeholder="Official Website" />
      <AppSelect
        v-model="sourceItemForm.type"
        label="Tipul sursei"
        :placeholder="DietSourceType.ARTICLE"
        :options="sourceOptions"
      />
      <AppTextarea v-model="sourceItemForm.url" label="URL" placeholder="https://google.com" />
      <AppTextarea v-model="sourceItemForm.notes" label="Notes" />
    </div>

    <AppButton type="button" class="add-source__btn" :loading="saving" @click.prevent="handleSave"
      >Save</AppButton
    >
  </AppModal>
  <div class="diet-sources-wrapper">
    <div class="diet-sources-list__wrapper">
      <SourceCard
        v-for="item in props.items"
        :key="item.id"
        :item="item"
        @edit="handleEdit"
        @delete="handleDelete"
      />
    </div>
    <div class="diet-sources__actions">
      <AppButton
        class="diet-daily-structure-table__add-day-button"
        variant="primary"
        size="small"
        type="button"
        @click.prevent="handleAdd"
      >
        + Adaugă sursa
      </AppButton>
    </div>
  </div>
</template>
<style scoped lang="css">
@import url(../styles/diets-sources.style.css);
</style>
