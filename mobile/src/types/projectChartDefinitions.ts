import type { ProjectNumericAggregation } from '@/types/projectNoteConfiguration'

export type ProjectChartType = 'pie' | 'line' | 'bar'
export type PieAggregation = 'count' | 'sum'
export type BarGrouping = 'auto' | 'day' | 'week' | 'month'
export interface ChartField {
  id: number
  name: string
  type: string
  aggregation: ProjectNumericAggregation | null
  unit: string | null
  archived: boolean
}
export interface ProjectChartDefinition {
  id: number
  project_id: number
  title: string
  type: ProjectChartType
  priority: number
  active: boolean
  total: string | null
  y_min: string | null
  y_max: string | null
  field_ids: number[]
  fields: ChartField[]
  pie_aggregation?: PieAggregation | null
  pie_value_field_id?: number | null
}
export type ProjectChartPayload = Pick<
  ProjectChartDefinition,
  | 'title'
  | 'type'
  | 'priority'
  | 'active'
  | 'total'
  | 'y_min'
  | 'y_max'
  | 'field_ids'
  | 'pie_aggregation'
  | 'pie_value_field_id'
>
export interface ConfiguredChartPoint {
  date: string
  from: string
  to: string
  value: string | null
  count: number
}
export interface ConfiguredChartSeries {
  field_id: number
  name: string
  unit: string | null
  archived: boolean
  aggregation: ProjectNumericAggregation
  points: ConfiguredChartPoint[]
}
export interface ConfiguredChartData {
  id: number
  title: string
  type: ProjectChartType
  priority: number
  y_min: string | null
  y_max: string | null
  error: string | null
  observation_count: number
  pie_aggregation?: PieAggregation
  pie_message?: string
  group_by?: Exclude<BarGrouping, 'auto'>
  total?: string
  excess?: string
  unit?: string | null
  segments: { label: string; value: string; option_id?: number; percentage?: number }[]
  series: ConfiguredChartSeries[]
}
export interface ConfiguredChartsReport {
  range: { start_date: string; end_date: string; max_days: number }
  charts: ConfiguredChartData[]
}
