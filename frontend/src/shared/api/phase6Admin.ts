import { api } from './client'
import type { Envelope } from './types'

/** Super Admin — Phase 6 media and publishing consoles (read-mostly; limits are audited). */
type Count = Record<string, number>

export interface MediaOverview {
  days: number
  route: { task_key: string; enabled: boolean; models: { model_key: string; provider: string | null; enabled: boolean; image_generation: boolean; provider_configured: boolean }[] }
  generation: { jobs: number; by_status: Count; images: number; cost_micro_usd: number; by_model: { model: string; jobs: number; cost: number }[] }
  storage: { bytes: number; assets: number; top_workspaces: { workspace_id: string; bytes: number }[] }
  abuse: { rejected_uploads: { workspace_id: string; error_code: string; n: number }[]; heavy_generators: { workspace_id: string; images: number; cost: number }[] }
}

export interface MediaLimitRow {
  id?: string
  scope_key: string
  max_storage_bytes: number | null
  max_uploads_per_day: number | null
  max_ai_images_per_day: number | null
  max_ai_cost_per_job_micro_usd: number | null
  ai_images_enabled: boolean
}

export interface PublishingOverview {
  days: number
  jobs: { by_status: Count; by_mode: Count; published: number; stuck: number; open_conflicts: number; missed_schedules: number }
  errors: { error_code: string; n: number }[]
  failed_jobs: { id: string; workspace_id: string; site_id: string; mode: string; attempts: number; last_error_code: string | null; last_error_message: string | null; updated_at: string }[]
  connectors: {
    plugin_versions: { plugin_version: string | null; n: number }[]
    wp_versions: { wp_version: string | null; n: number }[]
    connection_errors: { id: string; workspace_id: string; url: string; status: string; last_error_code: string; plugin_version: string | null; wp_version: string | null; last_health_at: string | null }[]
  }
}

export interface PublishingLimitRow {
  id?: string
  scope_key: string
  max_publishes_per_day: number | null
  max_bulk_items: number | null
  max_schedule_days_ahead: number | null
  auto_publish_allowed: boolean
  publishing_enabled: boolean
}

const A = '/api/admin/v1'
export const phase6AdminApi = {
  mediaOverview: (days = 30) => api.get<Envelope<MediaOverview>>(`${A}/media/overview`, { query: { days } }).then((r) => r.data),
  mediaLimits: () => api.get<Envelope<MediaLimitRow[]>>(`${A}/media/limits`).then((r) => r.data),
  updateMediaLimits: (row: MediaLimitRow) => api.put<Envelope<MediaLimitRow>>(`${A}/media/limits`, row).then((r) => r.data),
  publishingOverview: (days = 7) => api.get<Envelope<PublishingOverview>>(`${A}/publishing/overview`, { query: { days } }).then((r) => r.data),
  publishingLimits: () => api.get<Envelope<PublishingLimitRow[]> & { defaults: Record<string, number> }>(`${A}/publishing/limits`),
  updatePublishingLimits: (row: PublishingLimitRow) => api.put<Envelope<PublishingLimitRow>>(`${A}/publishing/limits`, row).then((r) => r.data),
}

/** "global" or "ws:<ULID>" — the only scopes the API accepts. */
export function isValidScope(scope: string): boolean {
  return /^(global|ws:[0-9A-Za-z]{26})$/.test(scope)
}
