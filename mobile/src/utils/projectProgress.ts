import { ApiError } from '@/services/api'
import { projectProgressApi } from '@/services/projectProgressApi'
import type { NotePayload, ProgressNote, TaskEvaluation } from '@/types/projectProgress'
import type { Project } from '@/types/projects'
import { displayDate, taskError } from '@/utils/projectTasks'

export function notePayload(
  title: string,
  content: string,
  date: string,
  taskIds?: number[],
): NotePayload {
  return {
    title: title.trim() || `Progres · ${displayDate(date)}`,
    content: content.trim(),
    noted_at: date,
    ...(taskIds === undefined ? {} : { task_ids: [...new Set(taskIds)] }),
  }
}
export function progressAuthor(
  id: number,
  project: Project,
  current?: { id: number; username: string } | null,
): string {
  return current?.id === id
    ? current.username
    : project.owner?.id === id
      ? project.owner.username
      : `Utilizator #${id}`
}
export function progressError(failure: unknown): string {
  return failure instanceof ApiError && failure.status === 409
    ? 'Există deja o evaluare pentru această zi sau datele s-au modificat. Reîncarcă și verifică evaluarea; nu am suprascris niciun scor.'
    : taskError(failure)
}
export type ScoreIntent = { task_id: number; score: number; existing?: TaskEvaluation }
export interface NoteSaveState {
  note: ProgressNote | null
  noteSaved: boolean
  finished: Set<number>
}
// Checkpoints survive partial failures. Updates are explicit intents with the displayed version.
export async function saveNoteAndScores(
  project: number,
  initialId: number | undefined,
  payload: NotePayload,
  scores: ScoreIntent[],
  state: NoteSaveState,
): Promise<ProgressNote> {
  if (!state.noteSaved) {
    state.note = (
      initialId
        ? await projectProgressApi.updateNote(project, initialId, payload)
        : await projectProgressApi.createNote(project, payload)
    ).data
    state.noteSaved = true
  }
  for (const intent of scores) {
    if (state.finished.has(intent.task_id)) continue
    if (intent.existing)
      await projectProgressApi.updateEvaluation(project, intent.task_id, intent.existing.id, {
        score: intent.score,
        lock_version: intent.existing.lock_version,
      })
    else
      await projectProgressApi.createEvaluation(project, intent.task_id, {
        score: intent.score,
        evaluation_date: payload.noted_at,
        note_id: state.note!.id,
      })
    state.finished.add(intent.task_id)
  }
  return state.note!
}
