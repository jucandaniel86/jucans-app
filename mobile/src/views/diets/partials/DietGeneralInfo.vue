<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import AppImageInput from '@/components/ui/AppImageInput.vue'
import { dietsApi } from '@/services/dietsApi'
import { ApiError } from '@/services/api'
import { useNotificationStore } from '@/stores/notifications'
import type { Diet } from '@/types/diets'

const props = defineProps<{ diet: Diet }>()
const emit = defineEmits<{ updated: [diet: Diet] }>()
const notifications = useNotificationStore()
const name = ref('')
const description = ref('')
const notes = ref('')
const file = ref<File | null>(null)
const localPreview = ref<string | null>(null)
const removingThumbnail = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
let alive = true

const previewUrl = computed(() => {
  if (localPreview.value) return localPreview.value
  if (removingThumbnail.value) return null
  const path = props.diet.thumbnail_url || props.diet.thumbnail
  if (!path) return null
  const backendRoot = new URL('/', import.meta.env.VITE_API_BASE_URL || window.location.origin)
  return new URL(path, backendRoot).href
})
const hasImage = computed(() => Boolean(previewUrl.value))

function releasePreview(): void {
  if (localPreview.value) URL.revokeObjectURL(localPreview.value)
  localPreview.value = null
}

function hydrate(diet: Diet): void {
  name.value = diet.name
  description.value = diet.description ?? ''
  notes.value = diet.notes ?? ''
  file.value = null
  removingThumbnail.value = false
  errors.value = {}
  releasePreview()
}

function selectImage(selected: File): void {
  if (saving.value) return
  if (
    !['image/jpeg', 'image/png', 'image/webp'].includes(selected.type) ||
    selected.size > 8 * 1024 * 1024
  ) {
    errors.value.thumbnail = 'Alege o imagine JPG, PNG sau WebP de maximum 8 MB.'
    return
  }
  releasePreview()
  file.value = selected
  localPreview.value = URL.createObjectURL(selected)
  removingThumbnail.value = false
  delete errors.value.thumbnail
}

function removeImage(): void {
  if (saving.value) return
  releasePreview()
  file.value = null
  removingThumbnail.value = true
  delete errors.value.thumbnail
}

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  if (!name.value.trim() || name.value.trim().length > 255) {
    errors.value.name = 'Numele dietei este obligatoriu și poate avea maximum 255 de caractere.'
    return
  }
  saving.value = true
  try {
    const response = await dietsApi.updateDiet(props.diet.id, {
      name: name.value.trim(),
      description: description.value.trim() ? description.value : null,
      notes: notes.value.trim() ? notes.value : null,
      thumbnail: file.value,
      removeThumbnail: removingThumbnail.value,
    })
    if (!alive) return
    hydrate(response.data)
    emit('updated', response.data)
    notifications.success('Informațiile dietei au fost salvate.')
  } catch (error) {
    if (!alive) return
    if (error instanceof ApiError && error.status === 422) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages.join(' ')]),
      )
    }
    notifications.apiError(error, 'Nu am putut salva informațiile dietei.')
  } finally {
    if (alive) saving.value = false
  }
}

watch(
  () => [
    props.diet.id,
    props.diet.name,
    props.diet.description,
    props.diet.notes,
    props.diet.thumbnail,
    props.diet.thumbnail_url,
  ],
  () => hydrate(props.diet),
  { immediate: true },
)
onBeforeUnmount(() => {
  alive = false
  releasePreview()
})
</script>

<template>
  <form class="diet-general-info" novalidate :aria-busy="saving" @submit.prevent="save">
    <AppInput
      v-model="name"
      label="Numele dietei"
      placeholder="Dieta de slăbit"
      :maxlength="255"
      :disabled="saving"
      :error="errors.name"
    />
    <AppTextarea
      v-model="description"
      label="Descriere"
      :rows="4"
      :disabled="saving"
      :error="errors.description"
    />
    <AppTextarea
      v-model="notes"
      label="Observații"
      :rows="3"
      :disabled="saving"
      :error="errors.notes"
    />
    <AppImageInput
      :preview-url="previewUrl"
      :has-image="hasImage"
      label="Imagine dietă"
      alt-text="Previzualizare imagine dietă"
      :disabled="saving"
      :error="errors.thumbnail"
      @select="selectImage"
      @remove="removeImage"
    />
    <AppButton type="submit" :loading="saving">Salvează modificările</AppButton>
  </form>
</template>

<style scoped>
.diet-general-info {
  display: grid;
  gap: var(--space-4);
  min-width: 0;
}
.diet-general-info :deep(.app-button) {
  justify-self: start;
}
</style>
