import { api } from './client'
import type { Envelope, Paginated } from './types'
import type { JobRunRef } from './media'

/**
 * Phase 6 — WordPress publisher. Every job passes the same gates (approved
 * content, fact-check, SEO, media, policy, quota, conflicts) when it is
 * created, approved and again right before it is sent. The UI shows the
 * gates; the API enforces them.
 */
export const PUBLISHING_STATUSES = ['DRAFT', 'READY_FOR_REVIEW', 'APPROVED', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'UPDATE_PENDING', 'FAILED', 'CANCELLED'] as const
export type PublishingStatus = (typeof PUBLISHING_STATUSES)[number]
export const JOB_MODES = ['wp_draft', 'publish_now', 'scheduled', 'update'] as const
export type JobMode = (typeof JOB_MODES)[number] | 'auto'
export const RESOLUTIONS = ['keep_wordpress', 'overwrite', 'merge_manually', 'new_draft', 'cancel_update'] as const
export type Resolution = (typeof RESOLUTIONS)[number]

export interface GateResult {
  gate: string
  ok: boolean
  code: string | null
  message: string
  details: Record<string, unknown> | unknown[]
}

export interface PublishingArticle {
  id: string
  title: string
  site_id: string
  campaign_id: string | null
  primary_keyword: string | null
  approved_at: string | null
  unresolved_critical: number
  unresolved_high: number
  is_demo: boolean
  active_job: { id: string; status: PublishingStatus; mode: JobMode } | null
  wordpress: { wp_post_id: number; url: string | null; status: string | null } | null
}

export interface Conflict {
  id: string
  site_id: string
  article_id: string
  article_title?: string | null
  mapping_id: string
  publishing_job_id: string | null
  types: string[]
  status: 'open' | 'resolved'
  resolution: Resolution | null
  wp_state: Record<string, unknown> | null
  expected_state: Record<string, unknown> | null
  note: string | null
  resolved_by: string | null
  resolved_at: string | null
  created_at: string | null
  is_demo: boolean
}

export interface PublishingJob {
  id: string
  site_id: string
  article_id: string
  article_title: string | null
  campaign_id: string | null
  publishing_batch_id: string | null
  mode: JobMode
  status: PublishingStatus
  wp_target_status: string
  scheduled_at: string | null
  attempts: number
  max_attempts: number
  next_attempt_at: string | null
  last_error_code: string | null
  last_error_message: string | null
  blocked_reason: string | null
  gate_results: GateResult[]
  approval_source: string | null
  approved_by: string | null
  approved_at: string | null
  published_at: string | null
  article_version_id: string | null
  wp_post_id: number | null
  url: string | null
  timezone: string
  allowed_transitions: PublishingStatus[]
  is_demo: boolean
  created_at: string | null
  updated_at: string | null
  local_time?: string | null
  schedule_status?: string | null
  attempts_log?: { attempt_no: number; operation: string; outcome: string; error_code: string | null; http_status: number | null; retryable: boolean; wp_post_id: number | null; duration_ms: number | null; details: Record<string, unknown> | null; created_at: string | null }[]
  schedule?: { status: string; timezone: string; local_time: string; note: string | null; scheduled_at: string | null } | null
  conflicts?: Conflict[]
  has_rendered_html?: boolean
}

export interface Mapping {
  id: string
  site_id: string
  article_id: string
  article_title?: string | null
  external_content_id: string
  is_current: boolean
  wp_post_id: number | null
  wp_status: string | null
  url: string | null
  slug: string | null
  site_host: string
  last_published_version_id: string | null
  last_published_at: string | null
  last_checked_at: string | null
  sync: { state: string; checked_at: string | null; details: Record<string, unknown> | null } | null
}

export interface SitePolicy {
  site_id: string
  default_mode: 'manual' | 'wp_draft'
  auto_publish_enabled: boolean
  auto_publish_enabled_by: string | null
  auto_publish_enabled_at: string | null
  require_seo_pass: boolean
  require_media_ready: boolean
  allow_update_published: boolean
  max_posts_per_day: number
  max_posts_per_campaign_per_day: number
  min_minutes_between_posts: number
  window_start_hour: number
  window_end_hour: number
  window_days: number[] | null
  max_schedule_days_ahead: number
  max_attempts: number
  missed_grace_minutes: number
  updated_at: string | null
}

export interface PolicyView extends SitePolicy {
  site: { id: string; url: string; host: string; status: string; paired: boolean; timezone: string; seo_adapter: string | null; yoast: boolean; rank_math: boolean; can_publish: boolean; can_upload: boolean; plugin_version: string | null; wp_version: string | null; last_error_code: string | null }
  platform: { publishing_enabled: boolean; auto_publish_allowed: boolean; max_publishes_per_day: number; max_bulk_items: number; max_schedule_days_ahead: number }
}

export interface Preview {
  simulation: true
  disclaimer: string
  approved: boolean
  title: string
  html: string
  featured: { url?: string | null; alt?: string | null } | null
  images: unknown[]
  links: unknown[]
  stale_links: unknown[]
  snippet: { title: string; url: string; description: string }
  seo_adapter: string | null
}

export interface BatchPreviewItem { article_id: string; title: string | null; ok: boolean; code: string | null; message: string | null; scheduled_at: string | null; local_time: string | null }
export interface Batch {
  id: string
  site_id: string
  mode: 'wp_draft' | 'publish_now' | 'scheduled'
  status: 'running' | 'paused' | 'completed' | 'canceled'
  interval_minutes: number | null
  total: number
  start_at: string | null
  counts: Record<string, number>
  created_at: string | null
  items?: PublishingJob[]
}

export interface CalendarView {
  jobs: PublishingJob[]
  planned: { id: string; campaign_id: string | null; content_idea_id: string | null; title: string; scheduled_for: string | null; status: string; [k: string]: unknown }[]
}

const W = (ws: string) => `/api/v1/workspaces/${encodeURIComponent(ws)}/publishing`
const e = encodeURIComponent

export const publishingApi = {
  articles: (ws: string, siteId?: string) => api.get<Envelope<PublishingArticle[]>>(`${W(ws)}/articles`, { query: { site_id: siteId } }).then((r) => r.data),
  preview: (ws: string, article: string) => api.get<Envelope<Preview>>(`${W(ws)}/articles/${e(article)}/preview`).then((r) => r.data),
  jobs: (ws: string, query: { cursor?: string | null; status?: string; site_id?: string; article_id?: string; publishing_batch_id?: string }) =>
    api.get<Paginated<PublishingJob>>(`${W(ws)}/jobs`, { query }),
  job: (ws: string, id: string) => api.get<Envelope<PublishingJob>>(`${W(ws)}/jobs/${e(id)}`).then((r) => r.data),
  create: (ws: string, data: { article_id: string; mode: string; scheduled_at?: string | null; approve?: boolean }, idempotencyKey: string) =>
    api.post<Envelope<PublishingJob>>(`${W(ws)}/jobs`, data, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
  html: (ws: string, id: string) => api.get<Envelope<{ html: string; note: string }>>(`${W(ws)}/jobs/${e(id)}/html`).then((r) => r.data),
  recheck: (ws: string, id: string) => api.post<Envelope<PublishingJob>>(`${W(ws)}/jobs/${e(id)}/recheck`).then((r) => r.data),
  approve: (ws: string, id: string, scheduledAt?: string | null) =>
    api.post<Envelope<PublishingJob>>(`${W(ws)}/jobs/${e(id)}/approve`, scheduledAt ? { scheduled_at: scheduledAt } : {}).then((r) => r.data),
  reschedule: (ws: string, id: string, scheduledAt: string) => api.post<Envelope<PublishingJob>>(`${W(ws)}/jobs/${e(id)}/reschedule`, { scheduled_at: scheduledAt }).then((r) => r.data),
  cancel: (ws: string, id: string) => api.post<Envelope<PublishingJob>>(`${W(ws)}/jobs/${e(id)}/cancel`).then((r) => r.data),
  retry: (ws: string, id: string) => api.post<Envelope<PublishingJob>>(`${W(ws)}/jobs/${e(id)}/retry`).then((r) => r.data),
  calendar: (ws: string, from: string, to: string, siteId?: string) =>
    api.get<Envelope<CalendarView>>(`${W(ws)}/calendar`, { query: { from, to, site_id: siteId } }).then((r) => r.data),
  conflicts: (ws: string, query: { cursor?: string | null; status?: string }) => api.get<Paginated<Conflict>>(`${W(ws)}/conflicts`, { query }),
  conflict: (ws: string, id: string) => api.get<Envelope<Conflict>>(`${W(ws)}/conflicts/${e(id)}`).then((r) => r.data),
  resolve: (ws: string, id: string, resolution: Resolution, note?: string) =>
    api.post<Envelope<Conflict>>(`${W(ws)}/conflicts/${e(id)}/resolve`, { resolution, note: note || undefined, confirm: true }).then((r) => r.data),
  mappings: (ws: string, query: { cursor?: string | null; site_id?: string }) => api.get<Paginated<Mapping>>(`${W(ws)}/mappings`, { query }),
  checkMapping: (ws: string, id: string) => api.post<{ job_run: JobRunRef }>(`${W(ws)}/mappings/${e(id)}/check`).then((r) => r.job_run),
  policy: (ws: string, site: string) => api.get<Envelope<PolicyView>>(`${W(ws)}/sites/${e(site)}/policy`).then((r) => r.data),
  updatePolicy: (ws: string, site: string, data: Partial<SitePolicy> & { confirm_auto_publish?: boolean }) =>
    api.put<Envelope<PolicyView>>(`${W(ws)}/sites/${e(site)}/policy`, data).then((r) => r.data),
  batches: (ws: string, query: { cursor?: string | null }) => api.get<Paginated<Batch>>(`${W(ws)}/batches`, { query }),
  previewBatch: (ws: string, data: { site_id: string; article_ids: string[]; mode: string; start_at?: string | null; interval_minutes?: number }) =>
    api.post<Envelope<{ items: BatchPreviewItem[]; [k: string]: unknown }>>(`${W(ws)}/batches/preview`, data).then((r) => r.data),
  createBatch: (ws: string, data: { site_id: string; article_ids: string[]; mode: string; start_at?: string | null; interval_minutes?: number }, idempotencyKey: string) =>
    api.post<Envelope<Batch>>(`${W(ws)}/batches`, data, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
  batch: (ws: string, id: string) => api.get<Envelope<Batch>>(`${W(ws)}/batches/${e(id)}`).then((r) => r.data),
  batchAction: (ws: string, id: string, action: 'pause' | 'resume' | 'cancel') => api.post<Envelope<Batch>>(`${W(ws)}/batches/${e(id)}/${action}`).then((r) => r.data),
}

export function jobTone(s: string): 'success' | 'warning' | 'danger' | 'neutral' | 'brand' {
  switch (s) {
    case 'PUBLISHED': return 'success'
    case 'FAILED': return 'danger'
    case 'UPDATE_PENDING': case 'READY_FOR_REVIEW': return 'warning'
    case 'CANCELLED': case 'DRAFT': return 'neutral'
    default: return 'brand'
  }
}

export const ACTIVE_STATUSES: PublishingStatus[] = ['APPROVED', 'SCHEDULED', 'PUBLISHING']

/** Overwrite needs an existing WordPress post on the same domain. */
export function resolutionAvailable(r: Resolution, types: string[]): boolean {
  if (r === 'overwrite') return !types.includes('post_missing') && !types.includes('domain_changed')
  return true
}

/** A fresh, URL-safe idempotency key for one user action (retries reuse it). */
export function newIdempotencyKey(prefix = 'ui'): string {
  const rnd = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${rnd}`.slice(0, 120)
}

/** A datetime-local input value (browser time zone) → ISO 8601 UTC for the API. */
export function localInputToIso(value: string): string | null {
  if (!value) return null
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? null : d.toISOString()
}

export function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
