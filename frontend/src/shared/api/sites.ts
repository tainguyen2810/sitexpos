import { apiBaseUrl } from '@/shared/config/runtime'
import { api } from './client'
import type { Envelope, Paginated } from './types'

export type SiteStatus = 'pending_pairing' | 'connected' | 'degraded' | 'revoked'

export interface Site {
  id: string
  name: string
  url: string
  status: SiteStatus
  content_language: string
  versions: { wordpress: string | null; php: string | null; plugin: string | null }
  publish_author: { wp_user_id: number; display_name: string | null } | null
  features: Partial<Record<'woocommerce' | 'yoast' | 'rank_math' | 'pretty_permalinks' | 'https', boolean>> & { seo_adapter?: string }
  issues: string[]
  settings: Record<string, unknown>
  last_error: { code: string; message: string | null } | null
  last_seen_at: string | null
  last_health_at: string | null
  last_synced_at: string | null
  connected_at: string | null
  revoked_at: string | null
  revoked_by: 'cloud' | 'wordpress' | null
  counts?: { content: number; taxonomies: number }
  created_at: string
}

export interface PairingCode {
  pairing_code: string
  expires_at: string
  cloud_url: string
}

export interface SiteTaxonomy {
  id: string
  taxonomy: 'category' | 'post_tag' | 'product_cat' | 'product_tag'
  wp_term_id: number
  name: string
  slug: string
  parent_wp_id: number | null
  count: number
}

export interface SiteContentItem {
  id: string
  post_type: 'post' | 'page' | 'product'
  wp_post_id: number
  status: string
  title: string
  url: string | null
  excerpt: string
  product: { sku?: string; price?: string; regular_price?: string; sale_price?: string; currency?: string; stock_status?: string } | null
  is_managed: boolean
  published_at: string | null
  modified_at: string | null
}

export interface SiteActivityLog {
  id: string
  direction: 'in' | 'out'
  operation: string
  level: 'info' | 'warning' | 'error'
  http_status: number | null
  error_code: string | null
  message: string | null
  latency_ms: number | null
  created_at: string
}

export interface SiteHealthCheck {
  id: string
  ok: boolean
  http_status: number | null
  latency_ms: number | null
  error_code: string | null
  checked_at: string
}

export interface JobRun {
  id: string
  type: string
  status: 'queued' | 'running' | 'succeeded' | 'failed'
  progress: number
  step: string | null
  error: { code: string; message: string | null } | null
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export type SiteJob = 'health-check' | 'sync' | 'test-publish'

const W = (ws: string) => `/api/v1/workspaces/${encodeURIComponent(ws)}`
const S = (ws: string, site: string) => `${W(ws)}/sites/${encodeURIComponent(site)}`

export const siteApi = {
  list: (ws: string) => api.get<Envelope<Site[]>>(`${W(ws)}/sites`).then((r) => r.data),
  create: (ws: string, data: { name: string; url: string; content_language?: string }) =>
    api.post<Envelope<Site> & { meta: { pairing_code: string; pairing_expires_at: string; cloud_url: string } }>(`${W(ws)}/sites`, data),
  get: (ws: string, site: string) => api.get<Envelope<Site>>(S(ws, site)).then((r) => r.data),
  update: (ws: string, site: string, data: { name?: string; content_language?: string }) => api.patch<Envelope<Site>>(S(ws, site), data).then((r) => r.data),
  remove: (ws: string, site: string) => api.delete<void>(S(ws, site)),

  pairingCode: (ws: string, site: string) => api.post<Envelope<PairingCode>>(`${S(ws, site)}/pairing-code`).then((r) => r.data),
  queue: (ws: string, site: string, job: SiteJob) => api.post<Envelope<{ job_run: Pick<JobRun, 'id' | 'status' | 'type'> }>>(`${S(ws, site)}/${job}`).then((r) => r.data.job_run),
  rotateKeys: (ws: string, site: string) => api.post<Envelope<Site>>(`${S(ws, site)}/rotate-keys`).then((r) => r.data),
  revoke: (ws: string, site: string) => api.post<Envelope<Site> & { meta: { plugin_notified: boolean } }>(`${S(ws, site)}/revoke`),

  taxonomies: (ws: string, site: string) => api.get<Envelope<SiteTaxonomy[]>>(`${S(ws, site)}/taxonomies`).then((r) => r.data),
  content: (ws: string, site: string, query: { cursor?: string | null; 'filter[post_type]'?: string; q?: string }) =>
    api.get<Paginated<SiteContentItem>>(`${S(ws, site)}/content`, { query }),
  logs: (ws: string, site: string, query: { cursor?: string | null; 'filter[level]'?: string }) =>
    api.get<Paginated<SiteActivityLog>>(`${S(ws, site)}/logs`, { query }),
  healthChecks: (ws: string, site: string) => api.get<Envelope<SiteHealthCheck[]>>(`${S(ws, site)}/health-checks`).then((r) => r.data),

  jobRun: (ws: string, id: string) => api.get<Envelope<JobRun>>(`${W(ws)}/job-runs/${encodeURIComponent(id)}`).then((r) => r.data),
}

/** Badge tone for a site status (UI only). */
export function siteStatusTone(status: SiteStatus): 'success' | 'warning' | 'danger' | 'neutral' {
  return { connected: 'success', degraded: 'warning', revoked: 'danger', pending_pairing: 'neutral' }[status] as 'success' | 'warning' | 'danger' | 'neutral'
}

/**
 * Polls a job run until it finishes. Resolves with the final run; a run that
 * is still unfinished after `timeoutMs` resolves as-is (the UI then says it
 * is still running in the background).
 */
export async function waitForJob(
  load: () => Promise<JobRun>,
  { intervalMs = 1000, timeoutMs = 60000, sleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms)) } = {},
): Promise<JobRun> {
  const until = Date.now() + timeoutMs
  let run = await load()
  while ((run.status === 'queued' || run.status === 'running') && Date.now() < until) {
    await sleep(intervalMs)
    run = await load()
  }
  return run
}

/** Public download of the WordPress plugin, pre-configured with this Cloud's URL. */
export function pluginDownloadUrl(): string {
  return `${apiBaseUrl()}/api/v1/plugin/sitex-post.zip`
}
