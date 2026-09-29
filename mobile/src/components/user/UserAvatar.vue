<script setup lang="ts">
import { computed, type CSSProperties } from 'vue'

import avatarSpriteUrl from '@/assets/avatars.png'
import { AVATAR_SPRITE, avatarBackgroundPosition, findAvatar } from '@/config/avatars'

const props = withDefaults(
  defineProps<{
    avatar: string | null
    username?: string
    size?: 'small' | 'medium' | 'large'
  }>(),
  {
    username: '',
    size: 'medium',
  },
)

const avatarOption = computed(() => findAvatar(props.avatar))
const fallbackInitial = computed(() => props.username.trim().slice(0, 1).toUpperCase() || '?')
const accessibleLabel = computed(() =>
  props.username ? `Avatar ${props.username}` : 'Avatar utilizator',
)
const spriteStyle = computed<CSSProperties | undefined>(() => {
  if (!avatarOption.value) return undefined

  return {
    backgroundImage: `url(${avatarSpriteUrl})`,
    backgroundPosition: avatarBackgroundPosition(avatarOption.value),
    backgroundSize: `${AVATAR_SPRITE.columns * 100}% auto`,
  }
})
</script>

<template>
  <span
    class="user-avatar"
    :class="[`user-avatar--${size}`, { 'user-avatar--fallback': !avatarOption }]"
    :style="spriteStyle"
    role="img"
    :aria-label="accessibleLabel"
    :data-avatar-id="avatarOption?.id ?? undefined"
  >
    <span v-if="!avatarOption" aria-hidden="true">{{ fallbackInitial }}</span>
  </span>
</template>

<style scoped>
.user-avatar {
  display: inline-grid;
  flex: 0 0 auto;
  place-items: center;
  overflow: hidden;
  border: 2px solid var(--color-surface);
  border-radius: 50%;
  background-color: var(--color-primary-soft);
  background-repeat: no-repeat;
  box-shadow: 0 0 0 1px var(--color-border);
}

.user-avatar--small {
  width: 30px;
  height: 30px;
}

.user-avatar--medium {
  width: 42px;
  height: 42px;
}

.user-avatar--large {
  width: 74px;
  height: 74px;
}

.user-avatar--fallback {
  color: var(--color-primary-strong);
  font-weight: 850;
}

.user-avatar--small.user-avatar--fallback {
  font-size: 0.75rem;
}

.user-avatar--large.user-avatar--fallback {
  font-size: 1.45rem;
}
</style>
