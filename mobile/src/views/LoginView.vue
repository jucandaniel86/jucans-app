<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import AppButton from '@/components/ui/AppButton.vue'
import AppInput from '@/components/ui/AppInput.vue'
import { ApiError } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const username = ref('')
const pin = ref('')
const usernameError = ref('')
const pinError = ref('')
const formError = ref('')

const canSubmit = computed(() => username.value.trim().length > 0 && /^\d{4,}$/.test(pin.value))

function updatePin(value: string): void {
  pin.value = value.replace(/\D/g, '')
  pinError.value = ''
}

async function submit(): Promise<void> {
  usernameError.value = ''
  pinError.value = ''
  formError.value = ''

  if (!username.value.trim()) {
    usernameError.value = 'Introdu numele de utilizator.'
  }

  if (!/^\d{4,}$/.test(pin.value)) {
    pinError.value = 'PIN-ul trebuie să conțină cel puțin 4 cifre.'
  }

  if (usernameError.value || pinError.value) {
    return
  }

  try {
    await authStore.login({ username: username.value.trim(), pin: pin.value })
    pin.value = ''
    await router.replace({ name: 'home' })
  } catch (error) {
    if (error instanceof ApiError) {
      usernameError.value = error.errors.username?.[0] ?? ''
      pinError.value = error.errors.pin?.[0] ?? ''
      formError.value = usernameError.value || pinError.value ? '' : error.message
    } else {
      formError.value = 'A apărut o problemă. Încearcă din nou.'
    }
  }
}
</script>

<template>
  <main class="login-view">
    <div class="login-view__content">
      <header class="login-view__intro">
        <img
          class="login-view__logo"
          src="@/assets/jucans_logo.png"
          alt="Jucans"
          width="527"
          height="521"
        />
        <div>
          <h1>Bun venit!</h1>
          <p>Intră în spațiul familiei.</p>
        </div>
      </header>

      <form class="login-view__form" novalidate @submit.prevent="submit">
        <AppInput
          v-model="username"
          label="Username"
          autocomplete="username"
          :error="usernameError"
          :disabled="authStore.isLoggingIn"
          @update:model-value="usernameError = ''"
        />
        <AppInput
          :model-value="pin"
          label="PIN"
          type="password"
          inputmode="numeric"
          autocomplete="current-password"
          :error="pinError"
          :disabled="authStore.isLoggingIn"
          :maxlength="32"
          @update:model-value="updatePin"
        />

        <p v-if="formError" class="login-view__error" role="alert">{{ formError }}</p>

        <AppButton type="submit" block :loading="authStore.isLoggingIn" :disabled="!canSubmit">
          Intră
        </AppButton>
      </form>
    </div>
  </main>
</template>

<style scoped>
.login-view {
  --login-padding-top: max(
    var(--space-5),
    var(--safe-area-inset-top, env(safe-area-inset-top, 0px))
  );
  --login-padding-bottom: max(
    var(--space-6),
    var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px))
  );
  display: grid;
  min-height: 100vh;
  min-height: 100dvh;
  place-items: center;
  padding: var(--login-padding-top)
    max(var(--space-5), var(--safe-area-inset-right, env(safe-area-inset-right, 0px)))
    var(--login-padding-bottom)
    max(var(--space-5), var(--safe-area-inset-left, env(safe-area-inset-left, 0px)));
  background: linear-gradient(
    180deg,
    var(--color-background-strong) 0,
    var(--color-background) 42%,
    var(--color-background) 100%
  );
}

.login-view__content {
  display: grid;
  width: min(100%, 420px);
  gap: var(--space-6);
}

.login-view__intro {
  display: grid;
  justify-items: center;
  gap: var(--space-2);
  text-align: center;
}

.login-view__logo {
  width: min(64vw, 260px);
  /* Reserve space for the unchanged form and heading when the WebView is shorter. */
  width: min(
    64vw,
    260px,
    max(140px, calc(100dvh - var(--login-padding-top) - var(--login-padding-bottom) - 390px))
  );
  height: auto;
  filter: drop-shadow(0 12px 14px rgb(21 36 61 / 11%));
}

.login-view__intro h1 {
  margin: 0;
  font-size: 2.15rem;
  line-height: 1.08;
  letter-spacing: 0;
}

.login-view__intro p {
  margin: var(--space-2) 0 0;
  color: var(--color-text-muted);
  font-size: 1rem;
}

.login-view__form {
  display: grid;
  gap: var(--space-4);
  padding: var(--space-5);
  border: 1px solid rgb(230 225 216 / 75%);
  border-radius: var(--radius-lg);
  background: rgb(255 255 255 / 92%);
  box-shadow: var(--shadow-md);
}

.login-view__form :deep(input[inputmode='numeric']) {
  letter-spacing: 0.22em;
}

.login-view__error {
  margin: 0;
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-sm);
  color: var(--color-error);
  font-size: 0.88rem;
  font-weight: 620;
  background: var(--color-error-soft);
}

@media (max-height: 680px) {
  .login-view {
    place-items: start center;
  }

  .login-view__logo {
    width: min(
      170px,
      max(140px, calc(100dvh - var(--login-padding-top) - var(--login-padding-bottom) - 390px))
    );
  }
}

@media (max-width: 359px) {
  .login-view__intro h1 {
    font-size: 1.8rem;
  }
}
</style>
