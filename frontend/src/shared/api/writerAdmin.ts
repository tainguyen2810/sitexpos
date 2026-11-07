import { api } from './client'
import type { Envelope, Paginated } from './types'

/**
 * Super Admin — AI Writer console. Model routing itself is edited in the
 * Phase 3 AI Gateway screen; this module only shows which writer tasks are
 * routed where.
 */
export const WRITER_SETTING_GROUPS = {
  limits: ['max_articles_per_month', 'max_concurrent_workflows', 'max_batch_size', 'max_batch_concurrency', 'default_article_budget_micro_usd', 'max_article_budget_micro_usd', 'autonomous_enabled', 'autonomous_articles_per_day'],
  research: ['max_sources_basic', 'max_sources_standard', 'max_sources_deep', 'source_cache_hours', 'max_source_chars_for_ai', 'min_evidence_items', 'web_search_enabled', 'serp_discovery_enabled', 'serp_results'],
  policy: ['max_revision_rounds', 'high_risk_min_reliability', 'high_risk_min_independent_sources', 'outdated_after_months', 'min_quality_score_for_approval', 'require_ack_for_high_risk', 'auto_approve_enabled', 'auto_approve_min_quality'],
  abuse: ['abuse_articles_per_hour', 'abuse_cost_per_day_micro_usd', 'abuse_failed_ratio_pct'],
} as const
export type WriterSettingKey = (typeof WRITER_SETTING_GROUPS)[keyof typeof WRITER_SETTING_GROUPS][number]
export type WriterSettings = Record<WriterSettingKey, number | boolean> & { blocked_source_domains?: string[] }

/** Money settings are stored in micro-USD and edited in USD. */
export function isMoneyKey(k: string): boolean {
  return k.endsWith('_micro_usd')
}
export function isBoolKey(k: string): boolean {
  return k === 'autonomous_enabled' || k === 'require_ack_for_high_risk' || k === 'auto_approve_enabled' || k === 'web_search_enabled' || k === 'serp_discovery_enabled'
}

export interface RouteRow {
  task_key: string
  configured: boolean
  route_id?: string
  is_enabled?: boolean
  queue?: string
  primary_model?: { id: string; model_key: string; display_name: string } | null
  economy_model?: { id: string; model_key: string; display_name: string } | null
  fallback_model_ids?: string[]
  max_output_tokens?: number | null
  max_attempts?: number
  allow_paid_retry?: boolean
}

export interface WriterCosts {
  days: number
  total_cost_micro_usd: number
  articles: number
  avg_cost_per_article_micro_usd: number
  by_task: { task_key: string; calls: number; cost_micro_usd: number; input_tokens: number; output_tokens: number }[]
  by_model: { model: string | null; calls: number; cost_micro_usd: number }[]
  by_workspace: { workspace_id: string; name: string | null; articles: number; cost_micro_usd: number }[]
  by_day: { day: string; cost_micro_usd: number; articles: number }[]
}

export interface AdminRun {
  id: string
  type: string
  status: string
  current_step: string | null
  attempts: number
  cycle: number
  workspace_id: string
  workspace_name: string | null
  article_id: string | null
  error: { code: string; message: string | null } | null
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export interface WriterFailures {
  workflow_errors: { type: string; error_code: string | null; count: number }[]
  ai_call_errors: { task_key: string; error_code: string | null; count: number }[]
  fact_check_partial_runs: number
  source_retrieval: Record<string, number>
  verdicts: { verdict: string; reviewer_type: string; count: number }[]
  quality: { avg_score: number; below_policy: number }
}

export interface AbuseSignal { workspace_id: string; workspace_name: string | null; signal: string; value: number; threshold: number }

const B = '/api/admin/v1/writer'
type Q = Record<string, string | number | undefined | null>

export const writerAdminApi = {
  settings: () => api.get<Envelope<{ values: WriterSettings; defaults: WriterSettings; workspace_keys: string[] }>>(`${B}/settings`).then((r) => r.data),
  updateSettings: (values: Partial<WriterSettings>) => api.patch<Envelope<unknown>>(`${B}/settings`, values),
  workspaceSettings: (ws: string) =>
    api.get<Envelope<{ workspace: { id: string; name: string }; values: WriterSettings; overrides: Partial<WriterSettings>; workspace_keys: string[] }>>(`${B}/workspaces/${encodeURIComponent(ws)}/settings`).then((r) => r.data),
  updateWorkspaceSettings: (ws: string, values: Partial<WriterSettings>) => api.patch<Envelope<unknown>>(`${B}/workspaces/${encodeURIComponent(ws)}/settings`, values),
  routing: () => api.get<Envelope<RouteRow[]>>(`${B}/routing`).then((r) => r.data),
  costs: (days = 30) => api.get<Envelope<WriterCosts>>(`${B}/costs`, { query: { days } }).then((r) => r.data),
  runs: (query: Q = {}) => api.get<Paginated<AdminRun> & { summary: Record<string, number> }>(`${B}/runs`, { query }),
  failures: (days = 7) => api.get<Envelope<WriterFailures>>(`${B}/failures`, { query: { days } }).then((r) => r.data),
  abuse: () => api.get<Envelope<AbuseSignal[]>>(`${B}/abuse`).then((r) => r.data),
}
