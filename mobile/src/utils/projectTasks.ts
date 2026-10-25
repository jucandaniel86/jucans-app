import { ApiError } from '@/services/api'
import { actionErrorMessage } from '@/utils/actionError'
import type { ProjectTask, TaskSchedule } from '@/types/projectTasks'

export const taskPriorities = {
  low: 'Scăzută',
  medium: 'Medie',
  high: 'Ridicată',
  urgent: 'Urgentă',
}
export const taskStates = { pending: 'De făcut', done: 'Done', skipped: 'Omisă' }
export const taskTypes = {
  one_time: 'O singură dată',
  recurring: 'Recurente',
  persistent: 'Persistente',
}
export const weekdays = ['Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă', 'Duminică']
export function executionRequestId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) =>
    byte.toString(16).padStart(2, '0'),
  ).join('')
}
// Calendar strings stay calendar strings; UTC is the documented application fallback.
export function today(timezone = 'UTC'): string {
  const parts = new Intl.DateTimeFormat('en', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  return ['year', 'month', 'day']
    .map((type) => parts.find((part) => part.type === type)!.value)
    .join('-')
}
export function shiftDate(date: string, days: number): string {
  const [year, month, day] = date.split('-').map(Number)
  // Arithmetic only; never convert a stored date-only value into a timestamp for display.
  return new Date(Date.UTC(year!, month! - 1, day! + days)).toISOString().slice(0, 10)
}
export function displayDate(date: string | null, time?: string | null): string {
  return date ? `${date.split('-').reverse().join('.')}${time ? ` · ${time}` : ''}` : 'Fără dată'
}
export function taskIsOverdue(task: ProjectTask, timezone: string): boolean {
  const date = task.due_date ?? task.scheduled_date
  const time = task.due_date !== null ? task.due_time : task.scheduled_time
  const currentDate = today(timezone)
  const currentTime = new Intl.DateTimeFormat('en-GB', {
    timeZone: timezone,
    hourCycle: 'h23',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(new Date())
  return (
    task.status === 'active' &&
    date !== null &&
    (date < currentDate || (date === currentDate && time !== null && `${time}:00` < currentTime))
  )
}
export function scheduleLabel(rule: TaskSchedule): string {
  const label =
    rule.recurrence_type === 'daily'
      ? 'Zilnic'
      : rule.recurrence_type === 'every_n_days'
        ? `La fiecare ${rule.interval_days} zile`
        : rule.recurrence_type === 'monthly'
          ? `Lunar, în ziua ${rule.month_day}`
          : rule.weekdays?.map((day) => weekdays[day - 1]).join(', ')
  return `${label}${rule.scheduled_time ? ` · ${rule.scheduled_time}` : ''}`
}
export function taskError(failure: unknown): string {
  if (failure instanceof ApiError) {
    if (failure.status === 401) return 'Sesiunea a expirat. Autentifică-te din nou.'
    if (failure.status === 403)
      return 'Nu mai ai permisiune sau proiectul/taskul este doar pentru citire.'
    if (failure.status === 404) return 'Taskul nu mai este disponibil sau accesul a fost retras.'
    if (failure.status === 409)
      return 'Datele s-au schimbat. Starea va fi reîncărcată; verific-o înainte să repeți acțiunea.'
    if (failure.status === 422)
      return Object.values(failure.errors).flat().join(' ') || failure.message
  }
  return actionErrorMessage(failure, 'Nu am putut efectua acțiunea. Încearcă din nou.')
}
