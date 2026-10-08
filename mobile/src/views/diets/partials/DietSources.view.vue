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
const deletingId = ref<number | null>(null)
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
const { error } = useNotificationStore()

//methods
const addSource = async () => {
  const source = await dietsApi.addSource(props.dietId, sourceItemForm)
  emit('reloadList', source.data)
}

const editSource = async () => {
  //   const source = await dietsApi.updateSource(props.dietId, editingId, payload)
  // emit(
  //   'reloadList',
  //   props.sources.map(item =>
  //     item.id === source.data.id ? source.data : item
  //   )
  // )
}

const deleteSource = async () => {
  //   await dietsApi.deleteSource(props.dietId, sourceId)
  // emit(
  //   'reloadList',
  //   props.sources.filter(item => item.id !== sourceId)
  // )
}

const handleEdit = (item: DietSource) => {
  editingItem.value = item
  showModal.value = true
}

const handleAdd = () => {
  editingItem.value = null
  showModal.value = true
}

const handleSave = async () => {
  saving.value = true
  try {
    const currentAction = editingItem.value ? editSource : addSource
    await currentAction()
    showModal.value = false
  } catch (err) {
    console.warn('error', err)
    error('Something went wrong' + JSON.stringify(err))
  }
  saving.value = false
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
      <SourceCard v-for="item in props.items" :key="item.id" :item="item" @edit="handleEdit" />
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
