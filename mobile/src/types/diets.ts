export enum DietStatus {
  DRAFT = 'draft',
  COMPLETED = 'completed',
  ACTIVE = 'active',
}

export enum DietSourceType {
  WEBSITE = 'website',
  YOUTUBE = 'youtube',
  ARTICLE = 'article',
  BOOK = 'book',
  OTHER = 'other',
}

export interface ResourceItem<T> {
  data: T
}

export interface DietSource {
  id: number
  type: DietSourceType
  is_official: boolean
  title: string
  url: string | null
  notes: string | null
  created_at: string | null
  updated_at: string | null
}

export interface Diet {
  id: number
  name: string
  description: string | null
  notes: string | null
  thumbnail: string | null
  status: DietStatus
  created_at: string | null
  updated_at: string | null
  sources: DietSource[]
}

export interface DailyStructure {
  id: number
  name: string
  slug: string
}

export type DietDetailResponse = ResourceItem<Diet>
export type CreatedDietResponse = ResourceItem<Diet>
export type DailyStructureResponse = ResourceItem<DailyStructure[]>
