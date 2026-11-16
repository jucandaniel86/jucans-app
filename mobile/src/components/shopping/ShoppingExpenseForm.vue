<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppSelect from '@/components/ui/AppSelect.vue'
import { ApiError } from '@/services/api'
import { projectsApi } from '@/services/projectsApi'
import { actionErrorMessage } from '@/utils/actionError'
import type { Project } from '@/types/projects'
import type { ShoppingExpenseInput } from '@/types/shopping'

const props = withDefaults(defineProps<{ active?: boolean; disabled?: boolean }>(), {
  active: true,
  disabled: false,
})
const expenseAmount = ref('')
const expenseProject = ref('')
const expenseProjects = ref<Project[]>([])
const expensePage = ref(0)
const expenseLastPage = ref(1)
const expenseProjectsLoading = ref(false)
const expenseProjectsError = ref('')
const expenseErrors = ref<Record<string, string>>({})
const closeError = ref('')
let expenseSequence = 0
let expenseTimezone: string | undefined
const expenseProjectOptions = computed(() => [
  { value: '', label: 'Alege proiectul' },
  ...expenseProjects.value.map((project) => ({ value: String(project.id), label: project.name })),
])
async function loadExpenseProjects(): Promise<void> {
  if (expenseProjectsLoading.value) return
  const sequence = ++expenseSequence
  expenseProjectsLoading.value = true
  expenseProjectsError.value = ''
  try {
    const response = await projectsApi.list('active', expensePage.value + 1, 5, true)
    if (sequence !== expenseSequence) return
    for (const project of response.data) {
      if (project && !expenseProjects.value.some((item) => item.id === project.id))
        expenseProjects.value.push(project)
    }
    expensePage.value = response.meta.current_page
    expenseLastPage.value = response.meta.last_page
  } catch (failure) {
    if (sequence === expenseSequence)
      expenseProjectsError.value = actionErrorMessage(
        failure,
        'Nu am putut încărca proiectele. Încearcă din nou.',
      )
  } finally {
    if (sequence === expenseSequence) expenseProjectsLoading.value = false
  }
}
onBeforeUnmount(() => {
  expenseSequence++
})
watch(
  () => props.active,
  (active) => {
    if (active && expensePage.value === 0) void loadExpenseProjects()
  },
  { immediate: true },
)

function input(): Omit<ShoppingExpenseInput, 'close_list'> | null {
  closeError.value = ''
  expenseErrors.value = {}
  const amount = expenseAmount.value.trim().replace(',', '.')
  if (!/^\d{1,12}(\.\d{1,6})?$/.test(amount) || !/[1-9]/.test(amount))
    expenseErrors.value.amount = 'Introdu o sumă pozitivă, cu maximum 6 zecimale.'
  if (!expenseProjects.value.some((project) => project.id === Number(expenseProject.value)))
    expenseErrors.value.project_id = 'Alege un proiect configurat pentru shopping.'
  if (expenseProjectsLoading.value || Object.keys(expenseErrors.value).length) return null
  const timezone = (expenseTimezone ??= Intl.DateTimeFormat().resolvedOptions().timeZone)
  if (!timezone) {
    closeError.value = 'Fusul orar al telefonului nu este disponibil.'
    return null
  }
  return { project_id: Number(expenseProject.value), amount, timezone }
}
function showError(failure: unknown): void {
  if (failure instanceof ApiError) {
    for (const key of ['amount', 'project_id']) {
      if (failure.errors[key]?.[0]) expenseErrors.value[key] = failure.errors[key][0]
    }
  }
  closeError.value = actionErrorMessage(failure, 'Nu am putut confirma înregistrarea costului.')
  if (!(failure instanceof ApiError) || failure.status === 0 || failure.status >= 500)
    closeError.value += ' Rezultatul nu este confirmat. Reîncearcă folosind aceleași valori.'
}
defineExpose({ input, showError, loading: expenseProjectsLoading })
</script>
<template>
  <fieldset class="shopping-expense-form" :disabled="disabled">
    <AppInput
      v-model="expenseAmount"
      label="Sumă *"
      inputmode="decimal"
      :disabled="disabled"
      :error="expenseErrors.amount"
      data-expense="amount"
    />
    <AppSelect
      v-model="expenseProject"
      label="Proiect *"
      :options="expenseProjectOptions"
      :disabled="disabled || expenseProjectsLoading"
      :error="expenseErrors.project_id"
      data-expense="project"
    />
    <p v-if="expenseProjectsLoading" role="status">Se încarcă proiectele…</p>
    <p v-else-if="!expenseProjects.length && !expenseProjectsError">
      Nu există proiecte active accesibile configurate pentru Shopping.
    </p>
    <p v-if="expenseProjectsError" role="alert">{{ expenseProjectsError }}</p>
    <button
      v-if="expenseProjectsError || expensePage < expenseLastPage"
      type="button"
      class="shopping-expense-form__more"
      :disabled="expenseProjectsLoading || disabled"
      @click="loadExpenseProjects"
    >
      {{ expenseProjectsError ? 'Reîncearcă proiectele' : 'Mai multe proiecte' }}
    </button>
    <p v-if="closeError" role="alert">{{ closeError }}</p>
  </fieldset>
</template>

<style scoped>
.shopping-expense-form {
  display: grid;
  gap: var(--space-2);
  min-width: 0;
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
.shopping-expense-form p {
  margin: 0;
  font-size: 0.9rem;
}
.shopping-expense-form__more {
  min-height: 44px;
  padding: var(--space-1);
  border: 0;
  background: transparent;
  color: var(--color-primary-strong);
  font: inherit;
  cursor: pointer;
}
</style>
