<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import IngredientPastePanel from '@/components/recipe/IngredientPastePanel.vue'
import IngredientReviewList from '@/components/recipe/IngredientReviewList.vue'
import RecipeImageInput from '@/components/recipe/RecipeImageInput.vue'
import RecipeTagSelector from '@/components/recipe/RecipeTagSelector.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import AppTextarea from '@/components/ui/AppTextarea.vue'
import { validateRecipeDraft } from '@/composables/recipeDraft'
import { useRecipeDraft } from '@/composables/useRecipeDraft'
import { foodApi } from '@/services/foodApi'
import type { FoodTagPayload } from '@/types/food'

const router = useRouter()
const route = useRoute()
const draft = useRecipeDraft()
const tagSelector = ref<InstanceType<typeof RecipeTagSelector> | null>(null)
const loadingRecipe = ref(false)
const loadError = ref('')
const isEdit = computed(() => route.name === 'recipe-edit')
const recipeId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const canSave = computed(() => {
  if (
    draft.loadingOptions.value ||
    draft.saving.value ||
    Object.keys(draft.units.value).length === 0
  ) {
    return false
  }

  return validateRecipeDraft(
    draft.recipe,
    draft.reviewIngredients.value,
    Object.keys(draft.units.value),
  ).valid
})

onMounted(async () => {
  const optionsPromise = draft.loadOptions()

  if (isEdit.value) {
    if (!Number.isInteger(recipeId.value) || (recipeId.value ?? 0) < 1) {
      loadError.value = 'Rețeta nu este validă.'
    } else {
      loadingRecipe.value = true

      try {
        const response = await foodApi.getRecipe(recipeId.value!)
        draft.hydrateRecipe(response.data)
      } catch {
        loadError.value = 'Nu am putut încărca rețeta pentru editare.'
      } finally {
        loadingRecipe.value = false
      }
    }
  }

  await optionsPromise
})

async function createTag(payload: FoodTagPayload): Promise<void> {
  const success = await draft.createTag(payload)
  tagSelector.value?.finishCreation(success)
}

async function saveRecipe(): Promise<void> {
  const saved = await draft.save(recipeId.value)

  if (!saved && Object.keys(draft.ingredientErrors.value).length > 0) {
    await nextTick()
    document.querySelector('.ingredient-card--invalid')?.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    })
  }

  if (saved && isEdit.value && recipeId.value) {
    await router.replace({ name: 'recipe-detail', params: { id: recipeId.value } })
  }
}
</script>

<template>
  <section v-if="loadingRecipe" class="recipe-form-state" aria-live="polite">
    Se încarcă rețeta…
  </section>

  <section v-else-if="loadError" class="recipe-form-state" role="alert">
    <p>{{ loadError }}</p>
    <AppButton variant="secondary" @click="router.push({ name: 'recipes' })">
      Înapoi la rețete
    </AppButton>
  </section>

  <section v-else-if="draft.savedRecipe.value" class="recipe-success" aria-live="polite">
    <div class="recipe-success__mark" aria-hidden="true">✓</div>
    <p class="recipe-success__eyebrow">Gata!</p>
    <h1>Rețeta a fost salvată!</h1>
    <p>
      <strong>{{ draft.savedRecipe.value.name }}</strong> este acum în colecția familiei.
    </p>
    <div class="recipe-success__actions">
      <AppButton block @click="draft.startAnotherRecipe">Adaugă altă rețetă</AppButton>
      <AppButton
        block
        variant="secondary"
        @click="router.push({ name: 'recipe-detail', params: { id: draft.savedRecipe.value.id } })"
      >
        Vezi rețeta
      </AppButton>
      <AppButton block variant="secondary" @click="router.push({ name: 'recipes' })">
        Toate rețetele
      </AppButton>
    </div>
  </section>

  <form v-else class="add-recipe" novalidate @submit.prevent="saveRecipe">
    <header class="add-recipe__intro">
      <span class="add-recipe__icon" aria-hidden="true">🍳</span>
      <div>
        <p>Food</p>
        <h1>{{ isEdit ? 'Editează rețeta' : 'Adaugă rețetă' }}</h1>
      </div>
    </header>

    <div v-if="draft.optionsError.value" class="page-message page-message--error" role="alert">
      <span>{{ draft.optionsError.value }}</span>
      <button type="button" @click="draft.loadOptions">Încearcă din nou</button>
    </div>

    <section class="form-section">
      <header class="form-section__header">
        <span>1</span>
        <div>
          <h2>Despre rețetă</h2>
          <p>Doar informațiile care te ajută s-o recunoști rapid.</p>
        </div>
      </header>

      <div class="form-section__body">
        <AppInput
          v-model="draft.recipe.name"
          label="Numele rețetei"
          placeholder="Ciorbă rădăuțeană"
          :maxlength="255"
          :error="draft.nameError.value"
          @update:model-value="draft.nameError.value = ''"
        />
        <AppTextarea
          v-model="draft.recipe.description"
          label="Descriere"
          placeholder="Ce e special la rețeta asta?"
          :rows="4"
        />
        <AppInput
          v-model="draft.recipe.url"
          label="Link către rețetă"
          type="url"
          inputmode="url"
          placeholder="https://youtube.com/..."
          :maxlength="2048"
          :error="draft.urlError.value"
          @update:model-value="draft.urlError.value = ''"
        />
        <RecipeImageInput
          :preview-url="draft.imagePreviewUrl.value"
          :has-image="draft.hasImage.value"
          :error="draft.imageError.value"
          :disabled="draft.saving.value"
          @select="draft.selectImage"
          @remove="draft.removeImage"
        />
      </div>
    </section>

    <section class="form-section">
      <header class="form-section__header">
        <span>2</span>
        <div>
          <h2>Tag-uri</h2>
          <p>Alege ce se potrivește sau adaugă un tag al familiei.</p>
        </div>
      </header>

      <RecipeTagSelector
        ref="tagSelector"
        :tags="draft.tags.value"
        :selected-ids="draft.recipe.tagIds"
        :loading="draft.loadingOptions.value"
        :creating="draft.creatingTag.value"
        :error="draft.tagError.value"
        @toggle="draft.toggleTag"
        @create="createTag"
      />
    </section>

    <section class="form-section form-section--ingredients">
      <header class="form-section__header">
        <span>3</span>
        <div>
          <h2>Ingrediente</h2>
          <p>Lipește lista, apoi verifică pe scurt ce am înțeles.</p>
        </div>
      </header>

      <IngredientPastePanel
        v-if="draft.reviewIngredients.value.length === 0"
        v-model="draft.rawIngredients.value"
        :processing="draft.processingIngredients.value"
        :error="draft.resolverError.value"
        @process="draft.processIngredients"
      />

      <template v-else>
        <details v-if="draft.rawIngredients.value.trim()" class="raw-ingredients">
          <summary>Vezi sau modifică textul lipit</summary>
          <div class="raw-ingredients__content">
            <IngredientPastePanel
              v-model="draft.rawIngredients.value"
              :processing="draft.processingIngredients.value"
              :error="draft.resolverError.value"
              @process="draft.processIngredients"
            />
          </div>
        </details>

        <div class="review-heading">
          <div>
            <h3>Verifică ingredientele</h3>
            <p>{{ draft.reviewIngredients.value.length }} ingrediente de revizuit</p>
          </div>
        </div>

        <IngredientReviewList
          :ingredients="draft.reviewIngredients.value"
          :units="draft.unitOptions.value"
          :errors="draft.ingredientErrors.value"
          :associating-key="draft.associatingKey.value"
          :association-errors="draft.associationErrors.value"
          @update="draft.updateIngredient"
          @choose-candidate="draft.chooseCandidate"
          @choose-new="draft.chooseNewIngredient"
          @remove="draft.removeIngredient"
          @associate="draft.associateIngredient"
          @add="draft.addIngredient"
        />
      </template>

      <button
        v-if="draft.reviewIngredients.value.length === 0"
        class="manual-ingredient"
        type="button"
        :disabled="draft.loadingOptions.value"
        @click="draft.addIngredient"
      >
        <span aria-hidden="true">+</span> Adaugă manual un ingredient
      </button>
    </section>

    <div class="recipe-save">
      <p v-if="draft.saveError.value" class="recipe-save__error" role="alert">
        {{ draft.saveError.value }}
      </p>
      <AppButton type="submit" block :loading="draft.saving.value" :disabled="!canSave">
        {{ isEdit ? 'Salvează modificările' : 'Salvează rețeta' }}
      </AppButton>
    </div>
  </form>
</template>

<style scoped>
.add-recipe {
  display: grid;
  gap: var(--space-8);
  padding-bottom: var(--space-4);
}

.recipe-form-state {
  display: grid;
  min-height: 360px;
  place-content: center;
  justify-items: center;
  gap: var(--space-3);
  color: var(--color-text-muted);
  text-align: center;
}

.recipe-form-state p {
  margin: 0;
}

.add-recipe__intro {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.add-recipe__icon {
  display: grid;
  width: 58px;
  height: 58px;
  flex: 0 0 auto;
  place-items: center;
  border-radius: var(--radius-md);
  font-size: 1.7rem;
  background: var(--color-yellow-soft);
  box-shadow: var(--shadow-sm);
}

.add-recipe__intro p,
.add-recipe__intro h1 {
  margin: 0;
}

.add-recipe__intro p {
  margin-bottom: var(--space-1);
  color: var(--color-primary-strong);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.add-recipe__intro h1 {
  font-size: 2rem;
  line-height: 1.08;
  letter-spacing: 0;
}

.form-section {
  display: grid;
  gap: var(--space-5);
}

.form-section__header {
  display: grid;
  grid-template-columns: 36px minmax(0, 1fr);
  align-items: start;
  gap: var(--space-3);
}

.form-section__header > span {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 50%;
  color: var(--color-primary-strong);
  font-size: 0.86rem;
  font-weight: 850;
  background: var(--color-primary-soft);
}

.form-section:nth-of-type(2) .form-section__header > span {
  color: #a53468;
  background: var(--color-pink-soft);
}

.form-section:nth-of-type(3) .form-section__header > span {
  color: #825d00;
  background: var(--color-yellow-soft);
}

.form-section__header h2,
.form-section__header p {
  margin: 0;
}

.form-section__header h2 {
  font-size: 1.2rem;
  letter-spacing: 0;
}

.form-section__header p {
  margin-top: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.87rem;
  line-height: 1.45;
}

.form-section__body {
  display: grid;
  gap: var(--space-4);
}

.form-section--ingredients {
  min-width: 0;
}

.page-message {
  display: flex;
  min-height: 48px;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-sm);
  font-size: 0.86rem;
  background: var(--color-error-soft);
}

.page-message button {
  flex: 0 0 auto;
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-radius: var(--radius-pill);
  color: var(--color-error);
  font-weight: 750;
  background: var(--color-surface);
}

.raw-ingredients {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: rgb(255 255 255 / 58%);
}

.raw-ingredients summary {
  min-height: 48px;
  padding: var(--space-3) var(--space-4);
  color: var(--color-text-muted);
  font-size: 0.86rem;
  font-weight: 720;
  cursor: pointer;
}

.raw-ingredients__content {
  padding: 0 var(--space-4) var(--space-4);
}

.review-heading {
  display: flex;
  align-items: end;
  justify-content: space-between;
}

.review-heading h3,
.review-heading p {
  margin: 0;
}

.review-heading h3 {
  font-size: 1.05rem;
}

.review-heading p {
  margin-top: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.manual-ingredient {
  min-height: 48px;
  border: 0;
  color: var(--color-primary-strong);
  font-weight: 750;
  background: transparent;
  cursor: pointer;
}

.recipe-save {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  border: 1px solid rgb(230 225 216 / 80%);
  border-radius: var(--radius-lg);
  background: rgb(255 248 236 / 94%);
  box-shadow: var(--shadow-md);
}

.recipe-save__error {
  margin: 0;
  color: var(--color-error);
  font-size: 0.82rem;
  font-weight: 650;
  text-align: center;
}

.recipe-success {
  display: grid;
  min-height: calc(100vh - 180px);
  min-height: calc(100dvh - 180px);
  place-content: center;
  justify-items: center;
  text-align: center;
}

.recipe-success__mark {
  display: grid;
  width: 82px;
  height: 82px;
  place-items: center;
  border-radius: 50%;
  color: #fff;
  font-size: 2.3rem;
  font-weight: 900;
  background: var(--color-success);
  box-shadow: 0 12px 28px rgb(38 140 105 / 24%);
  animation: success-pop 420ms cubic-bezier(0.2, 1.5, 0.4, 1);
}

.recipe-success__eyebrow {
  margin: var(--space-5) 0 var(--space-2);
  color: var(--color-success);
  font-size: 0.8rem;
  font-weight: 850;
  text-transform: uppercase;
}

.recipe-success h1 {
  margin: 0;
  font-size: 2rem;
  letter-spacing: 0;
}

.recipe-success > p:not(.recipe-success__eyebrow) {
  max-width: 320px;
  margin: var(--space-3) 0 var(--space-6);
  color: var(--color-text-muted);
  line-height: 1.55;
}

.recipe-success__actions {
  display: grid;
  width: min(100%, 320px);
  gap: var(--space-3);
}

@keyframes success-pop {
  0% {
    opacity: 0;
    transform: scale(0.72);
  }
  72% {
    transform: scale(1.06);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .recipe-success__mark {
    animation: none;
  }
}
</style>
