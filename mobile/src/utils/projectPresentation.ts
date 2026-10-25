import { ApiError } from '@/services/api'
import { actionErrorMessage } from '@/utils/actionError'
import type { ProjectExecutionType, ProjectProgressType, ProjectStatus } from '@/types/projects'

export const projectStatuses: Record<ProjectStatus, string> = {
  active: 'Activ',
  completed: 'Finalizat',
  archived: 'Arhivat',
}
export const projectProgressLabels: Record<ProjectProgressType, string> = {
  completion: 'Număr de taskuri finalizate',
  daily_completion: 'Finalizări zilnice',
  scored: 'Evaluări zilnice',
}
export const projectExecutionLabels: Record<ProjectExecutionType, string> = {
  one_time: 'O singură dată',
  recurring: 'Recurent',
  persistent: 'Persistent',
}
export const projectIcons = [
  { value: 'folder', label: '📁 Dosar', symbol: '📁' },
  { value: 'heart', label: '❤️ Sănătate', symbol: '❤️' },
  { value: 'leaf', label: '🌿 Stare de bine', symbol: '🌿' },
  { value: 'wallet', label: '💰 Finanțe', symbol: '💰' },
  { value: 'star', label: '⭐ Pasiuni', symbol: '⭐' },
  { value: 'target', label: '🎯 Obiectiv', symbol: '🎯' },
]
export function projectIcon(value: string): string {
  return projectIcons.find((icon) => icon.value === value)?.symbol ?? '📁'
}
export function projectError(
  failure: unknown,
  fallback = 'Nu am putut încărca proiectele. Încearcă din nou.',
): string {
  if (failure instanceof ApiError) {
    if (failure.status === 401) return 'Sesiunea a expirat. Autentifică-te din nou.'
    if (failure.status === 403)
      return 'Nu ai permisiune pentru această acțiune sau proiectul este acum doar pentru citire.'
    if (failure.status === 404) return 'Proiectul nu mai este disponibil sau nu ai acces la el.'
    if (failure.status === 409)
      return 'Starea proiectului s-a schimbat. Reîncarcă proiectul și încearcă din nou.'
  }
  return actionErrorMessage(failure, fallback)
}
