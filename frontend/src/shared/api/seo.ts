import { api } from './client'
import type { Envelope } from './types'
import type { JobRunRef } from './media'

/**
 * Phase 6 — SEO metadata, technical audit (PASS / WARNING / ERROR / BLOCKED),
 * schema preview and internal links. The score is a checklist that explains
 * every point; it is never a ranking guarantee.
 */
export const SEVERITIES = ['BLOCKED', 'ERROR', 'WARNING', 'PASS'] as const
export type Severity = (typeof SEVERITIES)[number]
export const AUDIT_CATEGORIES = ['metadata', 'content', 'structure', 'links', 'media', 'indexing', 'schema', 'wordpress'] as const
export const SCHEMA_TYPES = ['Article', 'BlogPosting', 'FAQPage', 'HowTo', 'BreadcrumbList'] as const
export type SchemaType = (typeof SCHEMA_TYPES)[number]

export interface SeoArticleRow {
  id: string
  title: string
  status: string
  site_id: string
  primary_keyword: string | null
  latest_audit: AuditRun | null
}

export interface SiteSeoPolicy {
  injection_enabled: boolean
  enabled_types: SchemaType[]
  organization_name: string | null
  logo_url: string | null
  breadcrumbs_supported: boolean
  allow_canonical_override: boolean
  allow_robots_override: boolean
  updated_at: string | null
}

export interface SiteSeoConfig extends SiteSeoPolicy {
  site_id: string
  seo_adapter: 'yoast' | 'rank_math' | 'native' | string | null
  yoast: boolean
  rank_math: boolean
}

export interface SeoMetadata {
  id: string
  article_id: string
  site_id: string
  seo_title: string | null
  meta_description: string | null
  slug: string | null
  canonical_url: string | null
  focus_keyword: string | null
  secondary_keywords: string[] | null
  og_title: string | null
  og_description: string | null
  social_image_asset_id: string | null
  robots_noindex: boolean
  robots_nofollow: boolean
  schema_types: SchemaType[] | null
  manual_fields: string[] | null
  source: string
  lock_version: number
  updated_at: string | null
  site_policy?: SiteSeoPolicy
}

export interface AuditIssue {
  id: string
  rule: string
  category: string
  severity: Severity
  weight: number
  points: number
  message: string
  explanation: string
  fix: string | null
  context: Record<string, unknown> | null
}

export interface AuditRun {
  id: string
  article_id: string
  site_id: string
  article_version_id: string | null
  rules_version: string
  status: string
  score: number
  pass_count: number
  warning_count: number
  error_count: number
  blocked_count: number
  links_checked: boolean
  summary: Record<string, unknown> | null
  is_demo: boolean
  created_at: string | null
  disclaimer: string
  issues?: AuditIssue[]
}

export interface SchemaPreview {
  enabled: boolean
  types: string[]
  skipped: Record<string, string>
  graph: Record<string, unknown>[]
  disclaimer: string
}

export interface LinkSuggestion {
  id: string
  article_id: string
  target_wp_post_id: number
  target_post_type: string
  target_url: string
  target_title: string
  anchor_text: string
  section_key: string | null
  paragraph_index: number
  relevance: number
  signals: { semantic: number; same_cluster: boolean; intent_fit: number; anchor_quality: number } | null
  reason: string | null
  status: 'suggested' | 'accepted' | 'rejected' | 'stale'
  verified_at: string | null
}

export type MetadataPatch = Partial<Omit<SeoMetadata, 'id' | 'article_id' | 'site_id' | 'manual_fields' | 'source' | 'updated_at' | 'site_policy'>> & { lock_version: number }

const W = (ws: string) => `/api/v1/workspaces/${encodeURIComponent(ws)}/seo`
const A = (ws: string, article: string) => `${W(ws)}/articles/${encodeURIComponent(article)}`

export const seoApi = {
  articles: (ws: string, siteId?: string) => api.get<Envelope<SeoArticleRow[]>>(`${W(ws)}/articles`, { query: { site_id: siteId } }).then((r) => r.data),
  metadata: (ws: string, article: string) => api.get<Envelope<SeoMetadata>>(`${A(ws, article)}/metadata`).then((r) => r.data),
  generateMetadata: (ws: string, article: string) => api.post<Envelope<SeoMetadata>>(`${A(ws, article)}/metadata/generate`).then((r) => r.data),
  updateMetadata: (ws: string, article: string, data: MetadataPatch) => api.put<Envelope<SeoMetadata>>(`${A(ws, article)}/metadata`, data).then((r) => r.data),
  audits: (ws: string, article: string) => api.get<Envelope<AuditRun[]>>(`${A(ws, article)}/audits`).then((r) => r.data),
  runAudit: (ws: string, article: string) => api.post<Envelope<AuditRun>>(`${A(ws, article)}/audits`).then((r) => r.data),
  checkLinks: (ws: string, article: string) => api.post<{ job_run: JobRunRef }>(`${A(ws, article)}/audits/link-check`).then((r) => r.job_run),
  audit: (ws: string, run: string) => api.get<Envelope<AuditRun>>(`${W(ws)}/audits/${encodeURIComponent(run)}`).then((r) => r.data),
  schema: (ws: string, article: string) => api.get<Envelope<SchemaPreview>>(`${A(ws, article)}/schema`).then((r) => r.data),
  links: (ws: string, article: string) => api.get<Envelope<LinkSuggestion[]>>(`${A(ws, article)}/links`).then((r) => r.data),
  suggestLinks: (ws: string, article: string) => api.post<Envelope<LinkSuggestion[]> & { suggested: number }>(`${A(ws, article)}/links/suggest`),
  decideLink: (ws: string, id: string, status: 'accepted' | 'rejected') => api.patch<Envelope<LinkSuggestion>>(`${W(ws)}/links/${encodeURIComponent(id)}`, { status }).then((r) => r.data),
  siteConfig: (ws: string, site: string) => api.get<Envelope<SiteSeoConfig>>(`${W(ws)}/sites/${encodeURIComponent(site)}/config`).then((r) => r.data),
  updateSiteConfig: (ws: string, site: string, data: Partial<SiteSeoPolicy>) =>
    api.put<Envelope<SiteSeoConfig>>(`${W(ws)}/sites/${encodeURIComponent(site)}/config`, data).then((r) => r.data),
}

export function severityTone(s: string): 'success' | 'warning' | 'danger' | 'neutral' {
  return s === 'PASS' ? 'success' : s === 'WARNING' ? 'warning' : s === 'ERROR' || s === 'BLOCKED' ? 'danger' : 'neutral'
}

/** Sort issues so the ones that stop publishing come first. */
export function sortIssues<T extends { severity: string; weight: number }>(issues: T[]): T[] {
  const rank = (s: string) => Math.max(0, SEVERITIES.indexOf(s as Severity))
  return [...issues].sort((a, b) => rank(a.severity) - rank(b.severity) || b.weight - a.weight)
}

/** Google truncates around these lengths; they are hints, not rules. */
export const TITLE_HINT = 60
export const DESCRIPTION_HINT = 155

export function lengthTone(len: number, hint: number): 'success' | 'warning' | 'danger' {
  if (len === 0) return 'danger'
  return len > hint ? 'warning' : 'success'
}
