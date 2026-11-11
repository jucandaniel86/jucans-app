import { Capacitor } from '@capacitor/core'
import { CapacitorCalendar } from '@ebarooni/capacitor-calendar'
import { projectTasksApi } from '@/services/projectTasksApi'
import type { ProjectTask, TaskOccurrence } from '@/types/projectTasks'

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

/** Exports one saved task/occurrence; callers may supply an already loaded snapshot. */
export async function addSavedTaskToPhoneCalendar(
  projectId: number,
  task: ProjectTask,
  occurrenceDate?: string,
  loadedOccurrence?: TaskOccurrence,
): Promise<'saved' | 'cancelled' | 'unconfirmed'> {
  if (!phoneCalendarAvailable())
    throw new Error('Calendarul telefonului nu este disponibil în acest build.')
  if (presenting)
    throw new Error(
      'Un editor de calendar este deja deschis. Nu a fost inițiată o a doua adăugare.',
    )
  presenting = true
  try {
    let date =
      task.execution_type === 'one_time'
        ? task.scheduled_date
        : (occurrenceDate ?? task.scheduled_date)
    let time = task.scheduled_time
    let dueDate = task.due_date
    let dueTime = task.due_time
    let selected = loadedOccurrence
    if (task.execution_type === 'recurring') {
      if (!date)
        throw new Error(
          'Nu există o apariție concretă selectată. Regula recurentă nu a fost exportată în calendar.',
        )
      if (selected && !selected.id && selected.is_scheduled !== true)
        throw new Error('Nu există o apariție eligibilă pentru această zi.')
      // Add/Edit verifies its saved rule. TaskCard supplies the batch-loaded snapshot
      // and never performs an additional read to export an occurrence.
      selected ??= (await projectTasksApi.occurrence(projectId, task.id, date)).data
      if (selected.occurrence_key !== date || selected.scheduled_date !== date)
        throw new Error('Apariția recurentă nu a putut fi identificată fără ambiguitate.')
    }
    if (selected) {
      const expectedKey = task.execution_type === 'one_time' ? 'single' : date
      if (selected.occurrence_key !== expectedKey)
        throw new Error('Apariția selectată nu corespunde datei exportate.')
      date = selected.scheduled_date ?? date
      time = selected.scheduled_time
      dueDate = selected.due_date === undefined ? task.due_date : selected.due_date
      dueTime = selected.due_time === undefined ? task.due_time : selected.due_time
    }
    if (!date) throw new Error('Taskul nu are dată programată; nu a fost adăugat în calendar.')
    const start = localStart(date, time)
    const allDay = time === null
    let startDate = start.getTime()
    let endDate: number
    if (allDay) {
      // due_date is an inclusive last day, never the recurring series end_date.
      const lastDay = dueDate ? localStart(dueDate, null) : new Date(start)
      if (lastDay < start) lastDay.setTime(start.getTime())
      if (Capacitor.getPlatform() === 'android') {
        // INSERT expects UTC date components for all-day exclusive boundaries.
        const utc = new Date(0)
        utc.setUTCFullYear(start.getFullYear(), start.getMonth(), start.getDate())
        utc.setUTCHours(0, 0, 0, 0)
        startDate = utc.getTime()
        utc.setUTCFullYear(lastDay.getFullYear(), lastDay.getMonth(), lastDay.getDate())
        utc.setUTCDate(utc.getUTCDate() + 1)
        endDate = utc.getTime()
      } else {
        lastDay.setDate(lastDay.getDate() + 1)
        endDate = lastDay.getTime()
      }
    } else {
      let end: Date | null = null
      if (dueTime !== null) end = localStart(dueDate ?? date, dueTime)
      else if (dueDate) {
        // A date-only deadline includes that full calendar day.
        end = localStart(dueDate, null)
        end.setDate(end.getDate() + 1)
      }
      // Without a usable end, prefill a one-hour draft, editable in the native UI.
      endDate = end && end > start ? end.getTime() : startDate + 60 * 60 * 1000
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
