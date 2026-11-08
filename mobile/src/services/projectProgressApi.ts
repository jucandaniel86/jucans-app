import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type {
  EvaluationRevision,
  NoteFilters,
  NotePayload,
  ProgressNote,
  ProgressPage,
  TaskEvaluation,
} from '@/types/projectProgress'

const root = (project: number) => `/projects/${project}`
const evaluations = (project: number, task: number) => `${root(project)}/tasks/${task}/evaluations`
const noteDeletedListeners = new Set<(project: number, note: number) => void>()

export function onProgressNoteDeleted(
  listener: (project: number, note: number) => void,
): () => void {
  noteDeletedListeners.add(listener)
  return () => {
    noteDeletedListeners.delete(listener)
  }
}

export const projectProgressApi = {
  reviews(
    project: number,
    task: number,
    key: string,
    page = 1,
  ): Promise<
    ProgressPage<ProgressNote> & {
      review_context: { mode: 'single' | 'multiple'; can_create: boolean }
    }
  > {
    const query = new URLSearchParams({ occurrence_key: key, page: String(page) })
    return api.get(`${root(project)}/tasks/${task}/reviews?${query}`)
  },
  notes(
    project: number,
    filters: NoteFilters = {},
    trash = false,
  ): Promise<ProgressPage<ProgressNote>> {
    const query = new URLSearchParams({ per_page: '20' })
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== '')
        query.set(key, typeof value === 'boolean' ? String(Number(value)) : String(value))
    })
    return api.get(`${root(project)}/notes${trash ? '/trash' : ''}?${query}`)
  },
  note(project: number, note: number): Promise<ResourceItem<ProgressNote>> {
    return api.get(`${root(project)}/notes/${note}`)
  },
  createNote(project: number, payload: NotePayload): Promise<ResourceItem<ProgressNote>> {
    return api.post(`${root(project)}/notes`, payload)
  },
  updateNote(
    project: number,
    note: number,
    payload: NotePayload,
  ): Promise<ResourceItem<ProgressNote>> {
    return api.patch(`${root(project)}/notes/${note}`, payload)
  },
  async deleteNote(project: number, note: number): Promise<void> {
    await api.delete(`${root(project)}/notes/${note}`)
    for (const listener of noteDeletedListeners) listener(project, note)
  },
  restoreNote(project: number, note: number): Promise<ResourceItem<ProgressNote>> {
    return api.post(`${root(project)}/notes/${note}/restore`)
  },
  preview(
    project: number,
    date: string,
    tasks: number[],
  ): Promise<ResourceItem<{ content: string }>> {
    return api.post(`${root(project)}/note-template/preview`, { date, task_ids: tasks })
  },
  evaluations(project: number, task: number, page = 1): Promise<ProgressPage<TaskEvaluation>> {
    return api.get(`${evaluations(project, task)}?page=${page}&per_page=20`)
  },
  evaluation(project: number, task: number, id: number): Promise<ResourceItem<TaskEvaluation>> {
    return api.get(`${evaluations(project, task)}/${id}`)
  },
  createEvaluation(
    project: number,
    task: number,
    payload: { score: number; evaluation_date: string; note_id?: number | null },
  ): Promise<ResourceItem<TaskEvaluation>> {
    return api.post(evaluations(project, task), payload)
  },
  updateEvaluation(
    project: number,
    task: number,
    id: number,
    payload: { score: number; lock_version: number },
  ): Promise<ResourceItem<TaskEvaluation>> {
    return api.patch(`${evaluations(project, task)}/${id}`, payload)
  },
  withdrawEvaluation(project: number, task: number, id: number, version: number): Promise<void> {
    return api.delete(`${evaluations(project, task)}/${id}`, { lock_version: version })
  },
  revisions(
    project: number,
    task: number,
    id: number,
    page = 1,
  ): Promise<ProgressPage<EvaluationRevision>> {
    return api.get(`${evaluations(project, task)}/${id}/revisions?page=${page}&per_page=20`)
  },
}
