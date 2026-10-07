<script lang="ts" setup>
import { defineProps, defineModel } from 'vue'

const props = defineProps<{
  tabs: Array<{ name: string; label: string }>
}>()

const tabModel = defineModel()
</script>
<template>
  <div class="app__tabs" role="tablist">
    <button
      v-for="(option, index) in props.tabs"
      :id="`${index}-${option}`"
      :key="index"
      type="button"
      role="tab"
      :aria-selected="tabModel === option.name"
      :aria-controls="`Tab${index}`"
      :tabindex="tabModel === option.name ? 0 : -1"
      @click="tabModel = option.name"
    >
      <span>{{ option.label }}</span>
    </button>
  </div>
</template>
<style scoped lang="css">
@import './styles/AppTabs.style.css';
</style>
