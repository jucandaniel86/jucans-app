export type FieldSeriesInterval = 'raw' | 'day' | 'week' | 'month'
export interface FieldSeriesPoint {
  id: string
  date: string
  value: string | null
  count: number
  note_id?: number
  bucket_from?: string
  bucket_to?: string
  included_from?: string
  included_to?: string
  partial?: boolean
}
export interface FieldSeries {
  field: { id: number; name: string; unit: string | null; archived_at: string | null }
  aggregation: 'average' | 'sum' | 'min' | 'max' | 'latest'
  interval: FieldSeriesInterval
  timezone: string
  range: { from: string; to: string; max_days: number }
  observation_count: number
  pagination: { page: number; per_page: number; total: number; last_page: number } | null
  points: FieldSeriesPoint[]
}
