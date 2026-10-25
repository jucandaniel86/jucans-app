import { api } from '@/services/api'
import type { ResourceItem } from '@/types/food'
import type { DailyTasks } from '@/types/projectDailyTasks'

export const projectDailyTasksApi = {
  list(page = 1, completed = false): Promise<ResourceItem<DailyTasks>> {
    return api.get(
      `/projects/daily-tasks?section=${completed ? 'completed' : 'active'}&page=${page}&per_page=20`,
    )
  },
}
