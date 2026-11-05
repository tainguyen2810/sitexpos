import { api } from './client'
import type { Envelope, Paginated } from './types'
import type { ResearchRun } from './research'

/**
 * Super Admin — SEO research console. Secrets are write-only: the API only
 * says whether one is set (`has_secret`), never its value.
 */
export const LIMIT_KEYS = [
  'max_campaigns', 'max_runs_per_month', 'max_ideas_per_run', 'max_competitors_per_campaign',
  'max_calendar_items_per_month', 'max_concurrent_runs', 'max_ai_cost_per_run_micro_usd',
] as const
export type LimitKey = (typeof LIMIT_KEYS)[number]
export type Limits = Record<LimitKey, number>

export const GLOBAL_KEYS = [
  'user_agent', 'crawl_delay_ms', 'max_page_bytes', 'max_sitemap_urls',
  'site_crawl_enabled', 'competitor_crawl_enabled', 'snapshot_retention_days', 'profile_refresh_days',
] as const
export type GlobalKey = (typeof GLOBAL_KEYS)[number]
export type GlobalSettings = Record<GlobalKey, string | number | boolean>

export interface AdminSource {
  source: string
  label: string
  requires_credentials: boolean
  is_enabled: boolean
  has_secret: boolean
  secret_managed_by: 'env' | 'admin'
  config: Record<string, unknown>
  rate_limit_per_minute: number
  timeout_seconds: number
  failure_count: number
  last_error_code: string | null
  last_failure_at: string | null
  updated_at: string | null
}

export interface SourceUpdate {
  is_enabled?: boolean
  rate_limit_per_minute?: number
  timeout_seconds?: number
  secret?: string | null
  config?: { location_code?: number }
  reset_failures?: boolean
}

export interface LimitRow extends Limits {
  id: string
  scope: 'global' | 'workspace'
  workspace: { id: string; name: string | null } | null
  updated_at: string | null
}

export type AdminRun = ResearchRun & { workspace: { id: string; name: string | null } | null; campaign_name: string | null }

export interface CostReport {
  period_days: number
  runs: number
  total_micro_usd: number
  avg_micro_usd_per_run: number
  max_micro_usd_per_run: number
  by_workspace: { workspace: { id: string; name: string | null }; runs: number; total_micro_usd: number; avg_micro_usd_per_run: number }[]
}

export interface FailureReport {
  sources: { source: string; failure_count: number; last_error_code: string | null; last_failure_at: string | null }[]
  recent: { id: string; workspace_id: string; research_run_id: string; type: string; label: string; url: string | null; http_status: number | null; error_code: string | null; error_message: string | null; created_at: string | null }[]
}

export interface UsageReport {
  period: string
  workspaces: { workspace: { id: string; name: string | null }; limits: Limits; metrics: Record<string, { used: number; reserved: number }> }[]
}

const B = '/api/admin/v1/research'

export const researchAdminApi = {
  sources: () => api.get<Envelope<AdminSource[]>>(`${B}/sources`).then((r) => r.data),
  updateSource: (source: string, data: SourceUpdate) => api.patch<Envelope<AdminSource>>(`${B}/sources/${encodeURIComponent(source)}`, data).then((r) => r.data),
  settings: () => api.get<Envelope<GlobalSettings>>(`${B}/settings`).then((r) => r.data),
  updateSettings: (data: Partial<GlobalSettings>) => api.patch<Envelope<GlobalSettings>>(`${B}/settings`, data).then((r) => r.data),
  limits: () => api.get<Envelope<{ defaults: Limits; rows: LimitRow[] }>>(`${B}/limits`).then((r) => r.data),
  upsertLimit: (data: Limits & { scope: 'global' | 'workspace'; workspace_id?: string }) => api.put<Envelope<LimitRow>>(`${B}/limits`, data).then((r) => r.data),
  deleteLimit: (id: string) => api.delete<void>(`${B}/limits/${encodeURIComponent(id)}`),
  runs: (query: { cursor?: string | null; status?: string; workspace_id?: string }) => api.get<Paginated<AdminRun>>(`${B}/runs`, { query }),
  costs: () => api.get<Envelope<CostReport>>(`${B}/costs`).then((r) => r.data),
  failures: () => api.get<Envelope<FailureReport>>(`${B}/failures`).then((r) => r.data),
  usage: () => api.get<Envelope<UsageReport>>(`${B}/usage`).then((r) => r.data),
}
