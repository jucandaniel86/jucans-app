import { ApiError } from '@/services/api'

export function actionErrorMessage(failure: unknown, fallback: string): string {
  if (!(failure instanceof ApiError)) {
    return failure instanceof Error &&
      failure.message === 'Alege lista curentă din Liste de cumpărături.'
      ? failure.message
      : fallback
  }

  const message = failure.message.trim()
  if (failure.status === 0 && message.startsWith('Nu ne-am putut conecta la server.'))
    return message
  if (failure.status < 400 || failure.status >= 500 || !message || message.length > 300)
    return fallback
  if (/[<>\r\n]|SQLSTATE|exception|stack trace|HTTP\b|App\\|\/(?:Users|var|home)\//i.test(message))
    return fallback
  return message
}
