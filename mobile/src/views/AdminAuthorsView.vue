<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import AppModal from '@/components/app/AppModal.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppImageInput from '@/components/ui/AppImageInput.vue'
import { ApiError } from '@/services/api'
import { authorsApi } from '@/services/authorsApi'
import { useNotificationStore } from '@/stores/notifications'
import type { Author } from '@/types/authors'

const notifications = useNotificationStore()
const authors = ref<Author[]>([])
const search = ref('')
const loading = ref(false)
const loadError = ref(false)
const busy = ref(false)
const open = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({ name: '', youtube_link: '', website_link: '' })
const errors = ref<Record<string, string[]>>({})
const saveError = ref('')
const icon = ref<File | null>(null)
const preview = ref<string | null>(null)
const removeIcon = ref(false)
let localPreview: string | null = null
const filteredAuthors = computed(() => authors.value.filter((author) =>
  author.name.toLocaleLowerCase('ro').includes(search.value.trim().toLocaleLowerCase('ro')),
))

function revokePreview(): void {
  if (localPreview) URL.revokeObjectURL(localPreview)
  localPreview = null
}
onBeforeUnmount(revokePreview)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = false
  try {
    authors.value = (await authorsApi.list()).data
  } catch (error) {
    loadError.value = true
    notifications.apiError(error)
  } finally {
    loading.value = false
  }
}
onMounted(load)

function edit(author: Author | null): void {
  revokePreview()
  editingId.value = author?.id ?? null
  Object.assign(form, {
    name: author?.name ?? '',
    youtube_link: author?.youtube_link ?? '',
    website_link: author?.website_link ?? '',
  })
  icon.value = null
  preview.value = author?.icon_url ?? null
  removeIcon.value = false
  errors.value = {}
  saveError.value = ''
  open.value = true
}

function selectIcon(file: File): void {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) {
    errors.value.icon = ['Alege o imagine JPG, PNG sau WebP de maximum 8 MB.']
    return
  }
  revokePreview()
  icon.value = file
  localPreview = URL.createObjectURL(file)
  preview.value = localPreview
  removeIcon.value = false
  delete errors.value.icon
}

function clearIcon(): void {
  revokePreview()
  icon.value = null
  preview.value = null
  removeIcon.value = true
  delete errors.value.icon
}

async function save(): Promise<void> {
  if (busy.value || !form.name.trim()) return
  busy.value = true
  errors.value = {}
  saveError.value = ''
  const data = new FormData()
  data.append('name', form.name.trim())
  data.append('youtube_link', form.youtube_link.trim())
  data.append('website_link', form.website_link.trim())
  if (icon.value) data.append('icon', icon.value)
  if (removeIcon.value) data.append('remove_icon', '1')
  try {
    await authorsApi.save(editingId.value, data)
    open.value = false
    revokePreview()
    notifications.success('Autorul a fost salvat.')
    await load()
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      errors.value = error.errors
      saveError.value = 'Verifică informațiile introduse.'
    } else {
      notifications.apiError(error)
    }
  } finally {
    busy.value = false
  }
}

async function remove(author: Author): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    const confirmed = await notifications.confirm({
      title: 'Șterge autorul',
      message: `Sigur vrei să ștergi autorul „${author.name}”?`,
      confirmText: 'Șterge', cancelText: 'Anulează',
    })
    if (!confirmed) return
    await authorsApi.remove(author.id)
    notifications.success('Autorul a fost șters.')
    await load()
  } catch (error) {
    notifications.apiError(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="admin-authors">
    <header class="admin-authors__header">
      <div><p>Administrare</p><h1>Authors</h1></div>
      <AppButton :disabled="busy" @click="edit(null)">Adaugă autor</AppButton>
    </header>
    <AppInput v-model="search" label="Caută autor" placeholder="Numele autorului" />
    <p v-if="loading" role="status">Se încarcă autorii…</p>
    <AppButton v-else-if="loadError" variant="secondary" @click="load">Încearcă din nou</AppButton>
    <p v-else-if="!filteredAuthors.length">Nu există autori pentru această selecție.</p>
    <div v-else class="admin-authors__list">
      <article v-for="author in filteredAuthors" :key="author.id" class="admin-authors__row">
        <img v-if="author.icon_url" :src="author.icon_url" alt="" />
        <strong>{{ author.name }}</strong>
        <div class="admin-authors__actions">
          <AppButton variant="secondary" :disabled="busy" @click="edit(author)">Editează</AppButton>
          <AppButton variant="secondary" :disabled="busy" @click="remove(author)">Șterge</AppButton>
        </div>
      </article>
    </div>
    <AppModal :model-value="open" :title="editingId ? 'Editează autor' : 'Adaugă autor'" @update:model-value="!busy && (open = $event)">
      <form class="admin-authors__form" @submit.prevent="save">
        <fieldset :disabled="busy">
          <AppInput v-model="form.name" label="Name" :maxlength="255" :error="errors.name?.[0]" />
          <AppInput v-model="form.youtube_link" label="YouTube link" type="url" :maxlength="2048" :error="errors.youtube_link?.[0]" />
          <AppInput v-model="form.website_link" label="Website link" type="url" :maxlength="2048" :error="errors.website_link?.[0]" />
          <AppImageInput label="Icon" :preview-url="preview" :has-image="Boolean(preview)" :disabled="busy" :error="errors.icon?.[0]" @select="selectIcon" @remove="clearIcon" />
        </fieldset>
        <p v-if="saveError" role="alert">{{ saveError }}</p>
        <AppButton type="submit" :loading="busy" :disabled="busy || !form.name.trim()">Salvează</AppButton>
        <AppButton variant="secondary" :disabled="busy" @click="open = false">Anulează</AppButton>
      </form>
    </AppModal>
  </section>
</template>

<style scoped>
.admin-authors,
.admin-authors__list,
.admin-authors__form,
.admin-authors__form fieldset {
  display: grid;
  gap: var(--space-4);
}
.admin-authors__header,
.admin-authors__row,
.admin-authors__actions {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
}
.admin-authors__header { justify-content: space-between; }
.admin-authors__header p { color: var(--color-text-muted); }
.admin-authors__header p,
.admin-authors__header h1 { margin: 0; }
.admin-authors__row {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
}
.admin-authors__row img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
.admin-authors__row strong { overflow-wrap: anywhere; }
.admin-authors__actions { margin-left: auto; }
.admin-authors__form fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
.admin-authors__form > p { color: var(--color-error); }
</style>
