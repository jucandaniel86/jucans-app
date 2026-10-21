import { api } from '@/services/api'
import type { Author } from '@/types/authors'
import type { ResourceCollection, ResourceItem } from '@/types/food'

export const authorsApi = {
  list(): Promise<ResourceCollection<Author>> {
    return api.get('/authors')
  },
  save(id: number | null, data: FormData): Promise<ResourceItem<Author>> {
    if (id) data.append('_method', 'PATCH')
    return api.post(id ? `/admin/authors/${id}` : '/admin/authors', data)
  },
  remove(id: number): Promise<void> {
    return api.delete(`/admin/authors/${id}`)
  },
}
