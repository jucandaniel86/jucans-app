import { Capacitor } from '@capacitor/core'
import { CapacitorCalendar } from '@ebarooni/capacitor-calendar'
import { projectTasksApi } from '@/services/projectTasksApi'
import type { ProjectTask } from '@/types/projectTasks'

export function phoneCalendarAvailable(): boolean {
  return (
    Capacitor.isNativePlatform() &&
    ['ios', 'android'].includes(Capacitor.getPlatform()) &&
    Capacitor.isPluginAvailable('CapacitorCalendar')
  )
}

let presenting = false

function localStart(date: string, time: string | null): Date {
  const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date)
  const clock = time === null ? null : /^(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(time)
  if (!day || (time !== null && !clock)) throw new Error('Data sau ora programată nu este validă.')
  const year = Number(day[1]),
    month = Number(day[2]),
    number = Number(day[3])
  const hours = Number(clock?.[1] ?? 0),
    minutes = Number(clock?.[2] ?? 0),
    seconds = Number(clock?.[3] ?? 0)
  const value = new Date(0)
  value.setFullYear(year, month - 1, number)
  value.setHours(hours, minutes, seconds, 0)
  if (
    value.getFullYear() !== year ||
    value.getMonth() !== month - 1 ||
    value.getDate() !== number ||
    (clock &&
      (value.getHours() !== hours ||
        value.getMinutes() !== minutes ||
        value.getSeconds() !== seconds))
  ) {
    throw new Error('Data sau ora nu poate fi reprezentată în fusul orar local al telefonului.')
  }
  return value
}

/** Called only after the task AND any changed recurrence rule have been saved. */
export async function addSavedTaskToPhoneCalendar(
  projectId: number,
  task: ProjectTask,
  occurrenceDate?: string,
): Promise<'saved' | 'cancelled' | 'unconfirmed'> {
  if (!phoneCalendarAvailable())
    throw new Error('Calendarul telefonului nu este disponibil în acest build.')
  if (presenting)
    throw new Error(
      'Un editor de calendar este deja deschis. Nu a fost inițiată o a doua adăugare.',
    )
  presenting = true
  try {
    let date = task.scheduled_date
    let time = task.scheduled_time
    if (task.execution_type === 'recurring') {
      date = occurrenceDate || task.scheduled_date
      if (!date)
        throw new Error(
          'Nu există o apariție concretă selectată. Regula recurentă nu a fost exportată în calendar.',
        )
      const { data } = await projectTasksApi.occurrence(projectId, task.id, date)
      if (data.occurrence_key !== date || data.scheduled_date !== date) {
        throw new Error('Apariția recurentă nu a putut fi identificată fără ambiguitate.')
      }
      time = data.scheduled_time
    }
    if (!date) throw new Error('Taskul nu are dată programată; nu a fost adăugat în calendar.')
    const start = localStart(date, time)
    const allDay = time === null
    let startDate = start.getTime()
    let endDate = startDate // No duration is stored for tasks; the native editor can adjust it.
    if (allDay) {
      if (Capacitor.getPlatform() === 'android') {
        // Android's calendar intent encodes date-only boundaries at UTC midnight.
        // Copy the date components; never convert a local midnight to a UTC date.
        const utc = new Date(0)
        utc.setUTCFullYear(start.getFullYear(), start.getMonth(), start.getDate())
        utc.setUTCHours(0, 0, 0, 0)
        startDate = utc.getTime()
        utc.setUTCDate(utc.getUTCDate() + 1)
        endDate = utc.getTime()
      } else {
        const end = new Date(start)
        end.setDate(end.getDate() + 1)
        endDate = end.getTime()
      }
    }
    if (Capacitor.getPlatform() === 'ios') {
      const { result } = await CapacitorCalendar.requestWriteOnlyCalendarAccess()
      if (result !== 'granted')
        throw new Error('Accesul la calendar a fost refuzat; evenimentul nu a fost creat.')
    }
    const result = await CapacitorCalendar.createEventWithPrompt({
      title: task.title,
      ...(task.description ? { description: task.description } : {}),
      startDate,
      endDate,
      isAllDay: allDay,
      // No recurrence: this is always one event, including for recurring tasks.
    })
    // Android's INSERT activity does not report whether the user actually saved.
    if (Capacitor.getPlatform() === 'android') return 'unconfirmed'
    return result.id ? 'saved' : 'cancelled'
  } finally {
    presenting = false
  }
}
