import { onScopeDispose, ref } from 'vue'
import { defineStore } from 'pinia'

export type NotificationType = 'success' | 'error' | 'warning' | 'info'
export interface Notification {
  id: number
  type: NotificationType
  message: string
  duration: number
}

const durations: Record<NotificationType, number> = {
  success: 3000,
  info: 3000,
  warning: 4500,
  error: 5000,
}

export const useNotificationStore = defineStore('notifications', () => {
  const notifications = ref<Notification[]>([])
  const timers = new Map<number, ReturnType<typeof setTimeout>>()
  const recent = new Map<string, { id: number; time: number }>()
  let nextId = 0

  function dismiss(id: number): void {
    clearTimeout(timers.get(id))
    timers.delete(id)
    notifications.value = notifications.value.filter((notification) => notification.id !== id)
  }

  function show(type: NotificationType, message: string, duration = durations[type]): number {
    const now = Date.now()
    for (const [key, entry] of recent) {
      if (now - entry.time >= 1000) recent.delete(key)
    }
    const key = JSON.stringify([type, message])
    const duplicate = recent.get(key)
    if (duplicate) return duplicate.id

    const id = ++nextId
    const timeout = Number.isFinite(duration) && duration >= 0 ? duration : durations[type]
    notifications.value.push({ id, type, message, duration: timeout })
    recent.set(key, { id, time: now })
    // A zero duration keeps a notification visible until it is dismissed.
    if (timeout > 0)
      timers.set(
        id,
        setTimeout(() => dismiss(id), timeout),
      )
    return id
  }

  onScopeDispose(() => {
    timers.forEach(clearTimeout)
    timers.clear()
    recent.clear()
  })

  return {
    notifications,
    dismiss,
    success: (message: string, duration?: number) => show('success', message, duration),
    error: (message: string, duration?: number) => show('error', message, duration),
    warning: (message: string, duration?: number) => show('warning', message, duration),
    info: (message: string, duration?: number) => show('info', message, duration),
  }
})
