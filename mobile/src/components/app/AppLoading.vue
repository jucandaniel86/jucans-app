<template>
  <main class="loading-screen" aria-label="Jucans se încarcă" aria-live="polite">
    <div class="loading-screen__content">
      <img
        class="loading-screen__logo"
        src="@/assets/jucans_logo.png"
        alt="Jucans"
        width="527"
        height="521"
      />
      <div class="loading-screen__status">
        <span class="loading-screen__dot" />
        <span class="loading-screen__dot" />
        <span class="loading-screen__dot" />
        <span class="loading-screen__text">Pregătim aplicația</span>
      </div>
    </div>
  </main>
</template>

<style scoped>
.loading-screen {
  display: grid;
  min-height: 100vh;
  min-height: 100dvh;
  place-items: center;
  padding: max(var(--space-6), env(safe-area-inset-top)) var(--space-6)
    max(var(--space-6), env(safe-area-inset-bottom));
  overflow: hidden;
  background: var(--color-background);
}

.loading-screen__content {
  display: grid;
  width: min(78vw, 330px);
  justify-items: center;
  gap: var(--space-6);
}

.loading-screen__logo {
  width: 100%;
  height: auto;
  filter: drop-shadow(0 14px 15px rgb(21 36 61 / 12%));
  transform-origin: 50% 92%;
  animation: logo-soft-bounce 1800ms cubic-bezier(0.36, 0.02, 0.2, 1) infinite;
}

.loading-screen__status {
  display: flex;
  min-height: 24px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: var(--color-text-muted);
  font-size: 0.84rem;
  font-weight: 650;
}

.loading-screen__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-primary);
  animation: loading-dot 1100ms ease-in-out infinite;
}

.loading-screen__dot:nth-child(2) {
  animation-delay: 130ms;
  background: var(--color-pink);
}

.loading-screen__dot:nth-child(3) {
  animation-delay: 260ms;
  background: var(--color-yellow);
}

.loading-screen__text {
  margin-left: var(--space-1);
}

@keyframes logo-soft-bounce {
  0%,
  100% {
    transform: translateY(0) scale(1);
  }
  34% {
    transform: translateY(-12px) scale(1);
  }
  58% {
    transform: translateY(1px) scaleX(1.015) scaleY(0.985);
  }
  72% {
    transform: translateY(-4px) scale(1);
  }
  86% {
    transform: translateY(0) scale(1);
  }
}

@keyframes loading-dot {
  0%,
  60%,
  100% {
    opacity: 0.4;
    transform: translateY(0);
  }
  30% {
    opacity: 1;
    transform: translateY(-3px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .loading-screen__logo,
  .loading-screen__dot {
    animation: none;
  }
}
</style>
