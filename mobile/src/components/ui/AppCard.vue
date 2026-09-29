<template>
  <section class="app-card">
    <slot />
  </section>
</template>

<style scoped>
.app-card {
  border: 1px solid rgb(230 225 216 / 75%);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
}
</style>
