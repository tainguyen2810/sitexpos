import { api } from './client'
import type { Envelope } from './types'

/**
 * Phase 8 — SEO Analytics & Content Intelligence. Numbers come from Google
 * Search Console as reported (2–3 day delay, partially hidden queries); the
 * UI never extrapolates, forecasts traffic or promises rankings. `null`
 * means "no data" and is rendered as "Chưa có dữ liệu".
 */
export const CLASSES = ['GROWING', 'STABLE', 'DECLINING', 'UNDERPERFORMING', 'INSUFFICIENT_DATA', 'RECENTLY_PUBLISHED'] as const
export type ContentClass = (typeof CLASSES)[number]
export const CONTENT_LABELS = ['high_impr_low_ctr', 'near_top', 'decay_candidate', 'needs_refresh'] as const
export const RULES = [
  'query_high_impr_low_ctr', 'page_traffic_decline', 'page_position_improving', 'new_query', 'content_missing_info',
  'outdated_info', 'keyword_cannibalization', 'internal_link_opportunity', 'orphan_content', 'broken_internal_links',
  'duplicate_title_meta', 'missing_metadata', 'content_gap', 'cluster_coverage_gap', 'refresh_candidate',
] as const
export type RuleCode = (typeof RULES)[number]
export const SEVERITIES = ['low', 'medium', 'high', 'critical'] as const
export const OPPORTUNITY_STATUSES = ['open', 'accepted', 'dismissed', 'resolved', 'expired'] as const
/** Statuses a person can set on an opportunity. */
export const OPPORTUNITY_DECISIONS = ['open', 'accepted', 'dismissed', 'resolved'] as const
export const DECAY_STATUSES = ['DECAY_SUSPECTED', 'NEEDS_INVESTIGATION', 'CONFIRMED_ISSUE', 'NO_ACTION'] as const
export type DecayStatus = (typeof DECAY_STATUSES)[number]
export const RECOMMENDATION_TYPES = [
  'rewrite_seo_title', 'improve_meta_description', 'add_missing_sections', 'update_outdated_facts', 'improve_internal_links',
  'merge_overlapping_articles', 'refresh_content', 'create_supporting_article', 'expand_topic_cluster', 'fix_broken_links', 'improve_article_structure',
] as const
export const RECOMMENDATION_STATUSES = ['proposed', 'approved', 'in_progress', 'applied', 'rejected', 'dismissed'] as const
export const REFRESH_STATUSES = [
  'queued', 'checking_wordpress', 'analyzing', 'brief_ready', 'drafting', 'fact_checking', 'awaiting_approval', 'approved', 'awaiting_publish',
  'publishing', 'monitoring', 'completed', 'conflict', 'seo_blocked', 'failed', 'cancelled',
] as const
export type RefreshStatus = (typeof REFRESH_STATUSES)[number]
export const CHANGE_TYPES = ['seo_title', 'meta_description', 'structure', 'internal_links', 'content_refresh', 'new_sections'] as const
export const EXPERIMENT_STATUSES = ['observing', 'completed', 'insufficient_data', 'cancelled'] as const
export const EXPERIMENT_OUTCOMES = ['improved', 'declined', 'no_clear_change', 'insufficient_data'] as const
export const HEALTH_COMPONENTS = [
  'content_freshness', 'metadata_completeness', 'internal_linking', 'broken_links', 'content_coverage', 'technical_signals', 'publishing_consistency', 'fact_check_issues',
] as const
export const SEARCH_TYPES = ['web', 'image', 'video', 'news', 'discover', 'googleNews'] as const
export const SORTS = ['clicks', 'impressions', 'ctr', 'position', 'clicks_change'] as const

export interface Metrics {
  clicks: number
  impressions: number
  ctr: number | null
  position: number | null
}
export interface RankedRow extends Metrics {
  key: string
  label: string
  article_id?: string | null
  previous: Metrics
  clicks_change: number | null
  position_change: number | null
}
export interface Filters {
  site_id?: string
  from?: string
  to?: string
  search_type?: string
  device?: string
  country?: string
  q?: string
  sort?: string
  limit?: number
  mapped_only?: boolean
}
export interface DashMeta {
  filters: Record<string, unknown>
  latest_date: string | null
  source: string
  note: string
}
export interface SearchConsoleStatus {
  connected: boolean
  status: string
  property_url: string | null
  google_email: string | null
  last_synced_at: string | null
  last_error_code: string | null
}
export interface PropertyState {
  status: string
  first_date: string | null
  last_complete_date: string | null
  last_sync_at: string | null
  last_sync_status: string | null
  last_error_code: string | null
  consecutive_failures?: number
}
export interface AnalyticsSite {
  id: string
  name: string
  url: string
  status: string
  paired: boolean
  search_console: SearchConsoleStatus
  analytics: PropertyState | null
  health: { score: number; date: string } | null
}
/** Report body as stored by the backend; every block is optional (missing data → "Chưa có dữ liệu"). */
export interface ReportPayload {
  data_note?: string
  performance?: { current?: Partial<Metrics>; clicks_change_pct?: number | null; impressions_change_pct?: number | null } | null
  growing_pages?: { page_url: string; clicks: number }[]
  declining_pages?: { page_url: string; clicks: number }[]
  opportunities?: { id: string; rule_code: string; target_label: string; severity: string }[]
  completed_optimizations?: { refreshes?: number; recommendations_applied?: number }
  agent?: { runs?: number; tasks_succeeded?: number; tasks_failed?: number; tasks_awaiting_approval?: number }
  pending_approvals?: { recommendations?: number; refreshes?: number; agent_tasks?: number }
  next_actions?: { id: string; type: string; target_label: string }[]
}
export interface Overview {
  period: { from: string; to: string }
  previous_period: { from: string; to: string }
  totals: Metrics
  previous: Metrics
  change: { clicks: number | null; impressions: number | null }
  series: (Metrics & { date: string })[]
  latest_date: string | null
  has_data: boolean
  opportunities_open: number
  health: HealthScore | null
  top_queries: RankedRow[]
  top_pages: RankedRow[]
}
export interface SyncRun {
  id: string
  site_id: string
  kind: 'incremental' | 'backfill'
  status: string
  range_start: string | null
  range_end: string | null
  checkpoint_date: string | null
  rows_upserted: number
  api_requests: number
  attempts: number
  error_code: string | null
  error_message: string | null
  next_retry_at: string | null
  started_at: string | null
  finished_at: string | null
  is_demo: boolean
  created_at: string | null
}
export interface Snapshot extends Metrics {
  id: string
  site_id: string
  page_url: string
  article_id: string | null
  period_end: string
  window_days: number
  prev_clicks: number | null
  prev_impressions: number | null
  prev_position: number | null
  baseline_clicks: number | null
  yoy_clicks: number | null
  days_with_data: number
  classification: ContentClass
  labels: string[]
  reasons: string[]
  published_at: string | null
  is_demo: boolean
}
export interface Opportunity {
  id: string
  site_id: string
  rule_code: RuleCode
  rule_version: string
  target_type: string
  target_ref: string
  target_label: string
  article_id: string | null
  evidence: Record<string, unknown>
  detection_rule: string
  severity: (typeof SEVERITIES)[number]
  impact: string
  impact_basis: string
  confidence: number
  confidence_explanation: string
  recommended_action: string
  estimated_ai_cost_micro_usd: number | null
  status: (typeof OPPORTUNITY_STATUSES)[number]
  review_note: string | null
  reviewed_at: string | null
  first_detected_at: string | null
  last_detected_at: string | null
  detections: number
  is_demo: boolean
}
export interface DecayEvent {
  id: string
  site_id: string
  page_url: string
  article_id: string | null
  status: DecayStatus
  decline_pct: number
  recent: Record<string, unknown>
  previous: Record<string, unknown>
  baseline: Record<string, unknown> | null
  seasonal: Record<string, unknown> | null
  query_changes: unknown[] | null
  url_changes: unknown[] | null
  content_changes: unknown[] | null
  signals: string[] | Record<string, unknown>
  detected_at: string | null
  last_evaluated_at: string | null
  decided_at: string | null
  decision_note: string | null
  is_demo: boolean
}
export interface Recommendation {
  id: string
  site_id: string
  opportunity_id: string | null
  type: (typeof RECOMMENDATION_TYPES)[number]
  target_type: string
  target_ref: string
  target_label: string
  article_id: string | null
  evidence: Record<string, unknown>
  rationale: string
  priority: number
  benefit_hypothesis: string
  estimated_cost_micro_usd: number | null
  risk: string
  risk_notes: string | null
  required_approval: string
  status: (typeof RECOMMENDATION_STATUSES)[number]
  suggested_value: Record<string, unknown> | null
  source: 'rule' | 'ai' | 'agent'
  agent_run_id: string | null
  decided_at: string | null
  decision_note: string | null
  applied_at: string | null
  is_demo: boolean
  created_at: string | null
}
export interface RefreshJob {
  id: string
  site_id: string
  article_id: string
  recommendation_id: string | null
  agent_task_id: string | null
  status: RefreshStatus
  error_code: string | null
  error_message: string | null
  estimated_cost_micro_usd: number | null
  publishing_job_id: string | null
  experiment_id: string | null
  approved_at: string | null
  is_demo: boolean
  created_at: string | null
  updated_at: string | null
  wp_check?: Record<string, unknown> | null
  base_version_id?: string | null
  new_version_id?: string | null
  analysis?: Record<string, unknown> | null
  brief?: { goal?: string; target_sections?: { key: string; heading: string; action: string; reason: string }[]; questions_to_research?: string[]; keep?: string; limits?: string } | null
  diff?: { sections: { heading: string; changed: boolean; before: string; after: string }[]; changed: number } | null
  fact_check?: Record<string, unknown> | null
  seo_audit?: { id: string; score: number; blocked: number; errors: number; warnings: number } | null
}
export interface Experiment {
  id: string
  site_id: string
  article_id: string | null
  page_url: string
  change_type: (typeof CHANGE_TYPES)[number]
  change_ref: string | null
  method: string
  change_date: string | null
  baseline_start: string
  baseline_end: string
  observation_days: number
  observation_end: string
  target_metrics: string[]
  baseline: Record<string, number | null> | null
  observed: Record<string, number | null> | null
  status: (typeof EXPERIMENT_STATUSES)[number]
  outcome: (typeof EXPERIMENT_OUTCOMES)[number] | null
  outcome_note: string | null
  external_factors: string | null
  is_demo: boolean
  created_at: string | null
}
export interface HealthComponent {
  value: number | null
  weight: number
  available: boolean
  detail: string
}
export interface HealthScore {
  id: string
  site_id: string
  date: string
  score: number | null
  components: Record<string, HealthComponent>
  weights: Record<string, number>
  is_demo: boolean
}
export interface Report {
  id: string
  site_id: string | null
  period_type: 'weekly' | 'monthly' | 'custom'
  period_start: string
  period_end: string
  status: string
  error_code: string | null
  emailed_at: string | null
  recipients_count: number
  is_demo: boolean
  created_at: string | null
  payload?: Record<string, unknown> | null
}
export interface CopilotAnswer {
  summary: string | null
  facts: string[]
  inferences: string[]
  recommendations: string[]
  data_freshness: string | null
  disclaimer: string
}
export interface CopilotQuery {
  id: string
  site_id: string | null
  question: string
  intent: string
  answer: CopilotAnswer
  mode: 'rules' | 'ai_pending' | 'ai'
  cost_micro_usd: number
  created_at: string | null
}
export interface ArticlePerformance {
  article: { id: string; title: string; status: string; site_id: string; primary_keyword: string | null }
  urls: { url: string; source: string; is_current: boolean }[]
  totals: Metrics
  series: (Metrics & { date: string })[]
  queries: (Metrics & { query: string })[]
  queries_period_end: string | null
  snapshot: Snapshot | null
  opportunities: Opportunity[]
  experiments: Experiment[]
  refresh_jobs: RefreshJob[]
  mapping_note: string | null
}
export interface ArticleRow {
  id: string
  title: string
  site_id: string
  primary_keyword: string | null
  performance: RankedRow | null
  classification: { value: ContentClass; labels: string[]; reasons: string[] } | null
}
export interface AnalyticsSettings {
  thresholds: Record<string, number>
  default_thresholds: Record<string, number>
  health_weights: Record<string, number>
  default_health_weights: Record<string, number>
  backfill_days: number
  raw_retention_days: number
  lock_version: number
}

const base = (ws: string) => `/api/v1/workspaces/${ws}/analytics`

/** Query string from filters; empty values are dropped. */
export function qs(f: Record<string, unknown> = {}): string {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(f)) {
    if (v === undefined || v === null || v === '' || v === false) continue
    p.set(k, v === true ? '1' : String(v))
  }
  const s = p.toString()
  return s ? `?${s}` : ''
}

export const analyticsApi = {
  sites: (ws: string) => api.get<{ data: AnalyticsSite[]; meta: { oauth_configured: boolean; ga4_available: boolean } }>(`${base(ws)}/sites`),
  connection: (ws: string, site: string) =>
    api.get<Envelope<{ site_id: string; oauth_configured: boolean; search_console: SearchConsoleStatus; analytics: PropertyState | null; ga4: { available: boolean } }>>(`${base(ws)}/sites/${site}/connection`),
  connect: (ws: string, site: string) => api.post<Envelope<{ authorization_url: string }>>(`${base(ws)}/sites/${site}/connect`),
  properties: (ws: string, site: string) => api.get<Envelope<{ site_url: string; permission: string }[]>>(`${base(ws)}/sites/${site}/properties`),
  selectProperty: (ws: string, site: string, propertyUrl: string) => api.put<Envelope<unknown>>(`${base(ws)}/sites/${site}/property`, { property_url: propertyUrl }),
  disconnect: (ws: string, site: string) => api.delete<Envelope<{ disconnected: boolean; revoked_at_google: boolean }>>(`${base(ws)}/sites/${site}/connection`),
  sync: (ws: string, site: string, kind: 'incremental' | 'backfill', idempotencyKey?: string) =>
    api.post<Envelope<SyncRun>>(`${base(ws)}/sites/${site}/sync`, { kind, idempotency_key: idempotencyKey }),
  syncRuns: (ws: string, site?: string) => api.get<Envelope<SyncRun[]>>(`${base(ws)}/sync-runs${qs({ site_id: site })}`),
  settings: (ws: string) => api.get<Envelope<AnalyticsSettings>>(`${base(ws)}/settings`),
  updateSettings: (ws: string, body: Partial<AnalyticsSettings> & { lock_version: number }) => api.put<Envelope<AnalyticsSettings>>(`${base(ws)}/settings`, body),

  overview: (ws: string, f: Filters) => api.get<{ data: Overview; meta: DashMeta }>(`${base(ws)}/overview${qs({ ...f })}`),
  queries: (ws: string, f: Filters) => api.get<{ data: RankedRow[]; meta: DashMeta }>(`${base(ws)}/queries${qs({ ...f })}`),
  pages: (ws: string, f: Filters) => api.get<{ data: RankedRow[]; meta: DashMeta }>(`${base(ws)}/pages${qs({ ...f })}`),
  dimensions: (ws: string, f: Filters, by: 'device' | 'country') =>
    api.get<{ data: (Metrics & { value: string })[]; meta: DashMeta }>(`${base(ws)}/dimensions${qs({ ...f, by })}`),
  articles: (ws: string, f: Filters) => api.get<{ data: ArticleRow[]; meta: DashMeta }>(`${base(ws)}/articles${qs({ ...f })}`),
  article: (ws: string, id: string, f: Filters = {}) => api.get<{ data: ArticlePerformance; meta: DashMeta }>(`${base(ws)}/articles/${id}${qs({ ...f })}`),
  clusters: (ws: string, site: string) => api.get<Envelope<{ name: string; ideas: number; published: number }[]>>(`${base(ws)}/clusters${qs({ site_id: site })}`),
  content: (ws: string, site?: string, cls?: string) =>
    api.get<{ data: Snapshot[]; meta: { counts: Record<string, number>; period_end: string | null; classes: string[] } }>(`${base(ws)}/content${qs({ site_id: site, classification: cls })}`),
  health: (ws: string, site?: string) =>
    api.get<Envelope<{ latest: HealthScore | null; history: { site_id: string; date: string; score: number }[]; disclaimer: string }>>(`${base(ws)}/health${qs({ site_id: site })}`),

  opportunities: (ws: string, f: Record<string, string | undefined> = {}) => api.get<Envelope<Opportunity[]>>(`${base(ws)}/opportunities${qs(f)}`),
  opportunity: (ws: string, id: string) => api.get<Envelope<Opportunity>>(`${base(ws)}/opportunities/${id}`),
  decideOpportunity: (ws: string, id: string, status: string, note?: string) => api.patch<Envelope<Opportunity>>(`${base(ws)}/opportunities/${id}`, { status, note }),
  decay: (ws: string, f: Record<string, string | undefined> = {}) => api.get<Envelope<DecayEvent[]>>(`${base(ws)}/decay${qs(f)}`),
  decideDecay: (ws: string, id: string, status: DecayStatus, note?: string) => api.patch<Envelope<DecayEvent>>(`${base(ws)}/decay/${id}`, { status, note }),
  recommendations: (ws: string, f: Record<string, string | undefined> = {}) => api.get<Envelope<Recommendation[]>>(`${base(ws)}/recommendations${qs(f)}`),
  decideRecommendation: (ws: string, id: string, status: 'approved' | 'rejected' | 'dismissed', note?: string) =>
    api.patch<Envelope<Recommendation>>(`${base(ws)}/recommendations/${id}`, { status, note }),
  experiments: (ws: string, site?: string) => api.get<Envelope<Experiment[]>>(`${base(ws)}/experiments${qs({ site_id: site })}`),
  experiment: (ws: string, id: string) => api.get<Envelope<Experiment>>(`${base(ws)}/experiments/${id}`),
  createExperiment: (ws: string, body: { site_id: string; article_id?: string | null; page_url: string; change_type: string; change_date: string; note?: string }) =>
    api.post<Envelope<Experiment>>(`${base(ws)}/experiments`, body),
  cancelExperiment: (ws: string, id: string) => api.post<Envelope<Experiment>>(`${base(ws)}/experiments/${id}/cancel`),

  refreshJobs: (ws: string, f: Record<string, string | undefined> = {}) => api.get<Envelope<RefreshJob[]>>(`${base(ws)}/refresh-jobs${qs(f)}`),
  refreshJob: (ws: string, id: string) => api.get<Envelope<RefreshJob>>(`${base(ws)}/refresh-jobs/${id}`),
  createRefresh: (ws: string, articleId: string, recommendationId?: string | null, idempotencyKey?: string) =>
    api.post<Envelope<RefreshJob>>(`${base(ws)}/refresh-jobs`, { article_id: articleId, recommendation_id: recommendationId ?? null, idempotency_key: idempotencyKey }),
  approveRefresh: (ws: string, id: string, note?: string) => api.post<Envelope<RefreshJob>>(`${base(ws)}/refresh-jobs/${id}/approve`, { note }),
  publishRefresh: (ws: string, id: string) => api.post<Envelope<RefreshJob>>(`${base(ws)}/refresh-jobs/${id}/publish`),
  cancelRefresh: (ws: string, id: string) => api.post<Envelope<RefreshJob>>(`${base(ws)}/refresh-jobs/${id}/cancel`),
  pollRefresh: (ws: string, id: string) => api.post<Envelope<RefreshJob>>(`${base(ws)}/refresh-jobs/${id}/poll`),

  reports: (ws: string, site?: string) => api.get<Envelope<Report[]>>(`${base(ws)}/reports${qs({ site_id: site })}`),
  report: (ws: string, id: string) => api.get<Envelope<Report>>(`${base(ws)}/reports/${id}`),
  createReport: (ws: string, body: { site_id?: string | null; period_type: string; period_start?: string; period_end?: string }) => api.post<Envelope<Report>>(`${base(ws)}/reports`, body),
  emailReport: (ws: string, id: string, recipientIds: string[]) => api.post<Envelope<Report>>(`${base(ws)}/reports/${id}/email`, { recipient_ids: recipientIds }),
  csvUrl: (ws: string, id: string) => `${base(ws)}/reports/${id}/csv`,

  ask: (ws: string, question: string, siteId?: string | null, articleId?: string | null) =>
    api.post<Envelope<CopilotQuery>>(`${base(ws)}/copilot`, { question, site_id: siteId ?? null, article_id: articleId ?? null }),
  copilotHistory: (ws: string) => api.get<Envelope<CopilotQuery[]>>(`${base(ws)}/copilot`),
  copilotQuery: (ws: string, id: string) => api.get<Envelope<CopilotQuery>>(`${base(ws)}/copilot/${id}`),
}

/** Format a number for display; `null` → "—" (the page shows "Chưa có dữ liệu" around it). */
export function num(v: number | null | undefined, digits = 0): string {
  if (v === null || v === undefined || Number.isNaN(v)) return '—'
  return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(v)
}
/** CTR from the API is already a percentage. */
export function pct(v: number | null | undefined, digits = 1): string {
  return v === null || v === undefined ? '—' : `${num(v, digits)}%`
}
export function signedPct(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return `${v > 0 ? '+' : ''}${num(v, 1)}%`
}
export function micro(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return `$${(v / 1_000_000).toFixed(v < 10_000 ? 4 : 2)}`
}

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger'
export function classTone(c: string | null | undefined): Tone {
  return ({ GROWING: 'success', STABLE: 'brand', DECLINING: 'danger', UNDERPERFORMING: 'warning' } as Record<string, Tone>)[c ?? ''] ?? 'neutral'
}
export function severityTone(s: string): Tone {
  return ({ critical: 'danger', high: 'warning', medium: 'brand' } as Record<string, Tone>)[s] ?? 'neutral'
}
export function refreshTone(s: string): Tone {
  if (['awaiting_approval', 'awaiting_publish'].includes(s)) return 'warning'
  if (['monitoring', 'completed'].includes(s)) return 'success'
  if (['conflict', 'seo_blocked', 'failed'].includes(s)) return 'danger'
  if (s === 'cancelled') return 'neutral'
  return 'brand'
}
export function decayTone(s: string): Tone {
  return ({ CONFIRMED_ISSUE: 'danger', NEEDS_INVESTIGATION: 'warning', DECAY_SUSPECTED: 'brand' } as Record<string, Tone>)[s] ?? 'neutral'
}
export function healthTone(score: number | null | undefined): Tone {
  if (score === null || score === undefined) return 'neutral'
  return score >= 80 ? 'success' : score >= 60 ? 'brand' : score >= 40 ? 'warning' : 'danger'
}

/** Default dashboard period: the last 28 complete days ending at the GSC lag. */
export function defaultPeriod(today = new Date(), lagDays = 3, days = 28): { from: string; to: string } {
  const end = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() - lagDays))
  const start = new Date(end.getTime() - (days - 1) * 86_400_000)
  return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) }
}

export function idempotencyKey(prefix = 'ui'): string {
  return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
}
