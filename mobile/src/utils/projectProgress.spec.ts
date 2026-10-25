import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api'
import { projectProgressApi } from '@/services/projectProgressApi'
import { notePayload, saveNoteAndScores, type NoteSaveState } from '@/utils/projectProgress'
import type { ProgressNote, TaskEvaluation } from '@/types/projectProgress'

vi.mock('@/services/projectProgressApi', () => ({
  projectProgressApi: {
    createNote: vi.fn(),
    updateNote: vi.fn(),
    createEvaluation: vi.fn(),
    updateEvaluation: vi.fn(),
  },
}))
const note: ProgressNote = {
  id: 40,
  project_id: 1,
  created_by: 1,
  updated_by: null,
  title: 'Progres',
  content: 'Text',
  noted_at: '2026-10-26',
  task_ids: [1, 2],
  tasks: [],
  evaluations: [],
  deleted_at: null,
}
const evaluation: TaskEvaluation = {
  id: 7,
  task_id: 2,
  score: 4,
  evaluation_date: '2026-10-01',
  created_by: 1,
  note_id: 40,
  lock_version: 3,
  is_active: true,
  withdrawn_at: null,
}
const state = (): NoteSaveState => ({ note: null, noteSaved: false, finished: new Set() })
describe('Progress note and score writes', () => {
  beforeEach(() => vi.resetAllMocks())
  it('retains note and successful score checkpoints after a partial failure, without duplicate creation or upsert', async () => {
    vi.mocked(projectProgressApi.createNote).mockResolvedValue({ data: note })
    vi.mocked(projectProgressApi.createEvaluation)
      .mockResolvedValueOnce({ data: { ...evaluation, task_id: 1 } })
      .mockRejectedValueOnce(new ApiError('Already exists', 409))
      .mockResolvedValueOnce({ data: evaluation })
    const checkpoint = state()
    const payload = notePayload('', ' Text ', '2026-10-26', [1, 2, 1])
    expect(payload).toEqual({
      title: 'Progres · 26.10.2026',
      content: 'Text',
      noted_at: '2026-10-26',
      task_ids: [1, 2],
    })
    const scores = [
      { task_id: 1, score: 5 },
      { task_id: 2, score: 8 },
    ]
    await expect(
      saveNoteAndScores(1, undefined, payload, scores, checkpoint),
    ).rejects.toMatchObject({ status: 409 })
    expect(checkpoint.note?.id).toBe(40)
    expect([...checkpoint.finished]).toEqual([1])
    expect(projectProgressApi.updateEvaluation).not.toHaveBeenCalled()
    await saveNoteAndScores(1, undefined, payload, scores, checkpoint)
    expect(projectProgressApi.createNote).toHaveBeenCalledTimes(1)
    expect(projectProgressApi.createEvaluation).toHaveBeenCalledTimes(3)
    expect(vi.mocked(projectProgressApi.createEvaluation).mock.calls[2]).toEqual([
      1,
      2,
      { score: 8, evaluation_date: '2026-10-26', note_id: 40 },
    ])
  })
  it('updates only an explicitly selected evaluation with its expected version, never moving its date or retrying stale writes', async () => {
    vi.mocked(projectProgressApi.updateNote).mockResolvedValue({ data: note })
    vi.mocked(projectProgressApi.updateEvaluation).mockRejectedValue(new ApiError('Stale', 409))
    const checkpoint = state()
    await expect(
      saveNoteAndScores(
        1,
        40,
        notePayload('Note', 'Text', '2026-10-26'),
        [{ task_id: 2, score: 8, existing: evaluation }],
        checkpoint,
      ),
    ).rejects.toMatchObject({ status: 409 })
    expect(projectProgressApi.updateEvaluation).toHaveBeenCalledExactlyOnceWith(1, 2, 7, {
      score: 8,
      lock_version: 3,
    })
    expect(projectProgressApi.createEvaluation).not.toHaveBeenCalled()
    expect(checkpoint.finished.size).toBe(0)
  })
})
