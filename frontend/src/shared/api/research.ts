import { api } from './client'
import type { Envelope, Paginated } from './types'

/**
 * Phase 4 — SEO Planner (topic discovery) API. Metrics are only ever shown
 * when a verified source returned them: `null` means "Chưa có dữ liệu".
 */
export type Intent = 'informational' | 'commercial' | 'transactional' | 'navigational'
export type ContentType = Intent | 'tutorial' | 'comparison' | 'troubleshooting' | 'faq' | 'pillar'
export type CampaignStatus = 'active' | 'paused' | 'archived'
export type RunStatus = 'queued' | 'running' | 'succeeded' | 'partial' | 'failed' | 'canceled'
export type Goal = 'traffic' | 'sales' | 'leads' | 'awareness' | 'support' | 'local'

export const INTENTS: Intent[] = ['informational', 'commercial', 'transactional', 'navigational']
export const CONTENT_TYPES: ContentType[] = ['informational', 'commercial', 'transactional', 'navigational', 'tutorial', 'comparison', 'troubleshooting', 'faq', 'pillar']
export const GOALS: Goal[] = ['traffic', 'sales', 'leads', 'awareness', 'support', 'local']
export const KNOWLEDGE_KINDS = ['industry', 'brand', 'positioning', 'product_line', 'service', 'audience', 'region', 'usp', 'covered_topic', 'uncertainty'] as const
export type KnowledgeKind = (typeof KNOWLEDGE_KINDS)[number]

export interface Campaign {
  id: string
  site_id: string
  name: string
  industry: string
  market: string
  language: 'vi' | 'en'
  products: string[]
  services: string[]
  audiences: string[]
  goals: Goal[]
  brand_terms: string[]
  status: CampaignStatus
  is_demo: boolean
  counts?: { keywords: number; ideas: number; clusters: number; competitors: number }
  last_run_at: string | null
  created_at: string
}

export interface CampaignInput {
  site_id?: string
  name?: string
  industry?: string
  market?: string
  language?: string
  products?: string[]
  services?: string[]
  audiences?: string[]
  goals?: Goal[]
  brand_terms?: string[]
  status?: CampaignStatus
}

export interface CampaignSettings {
  ideas_per_run: number
  scope_keywords: string[]
  excluded_topics: string[]
  max_site_pages: number
  max_competitor_pages: number
  use_ai_expansion: boolean
  auto_discover: boolean
  auto_calendar: boolean
  auto_create_content: boolean
  auto_publish: boolean
  posts_per_week: number
  publish_weekdays: number[]
  max_ai_cost_per_run_micro_usd: number
  notify_opportunities: boolean
  notify_min_score: number
}

export interface Schedule {
  is_enabled: boolean
  frequency: 'daily' | 'weekly' | 'monthly'
  day_of_week: number | null
  day_of_month: number | null
  hour_utc: number
  next_run_at: string | null
  last_run_at: string | null
}

export interface ResearchSource {
  id: string
  type: string
  label: string
  url: string | null
  status: 'ok' | 'skipped' | 'failed' | 'blocked'
  http_status: number | null
  robots_allowed: boolean | null
  items_count: number
  error: { code: string; message: string | null } | null
  snapshots_count?: number
  fetched_at: string | null
}

export interface ResearchRun {
  id: string
  campaign_id: string
  workflow_run_id: string | null
  trigger: 'manual' | 'scheduled'
  status: RunStatus
  stage: string | null
  stage_label: string | null
  progress: number
  stats: Partial<Record<'keywords_total' | 'keywords_added' | 'clusters' | 'gaps_new' | 'ideas_created' | 'ideas_ai' | 'ideas_rule' | 'calendar_scheduled' | 'pages_crawled' | 'competitor_findings', number>>
  warnings: string[]
  cost_micro_usd: number
  error: { code: string; message: string | null } | null
  sources?: ResearchSource[]
  steps?: { key: string; status: string; attempts: number; error: { code: string; message: string | null } | null; started_at: string | null; finished_at: string | null }[]
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export interface Snapshot {
  id: string
  source_id: string
  url: string
  http_status: number | null
  title: string | null
  meta_description: string | null
  headings: { level: number; text: string }[]
  questions: string[]
  internal_links_count: number
  word_count: number | null
  fetched_at: string | null
}

export interface ScoreComponent {
  key: string
  points: number | null
  max: number
  available: boolean
  note: string
}

export interface Keyword {
  id: string
  keyword: string
  intent: Intent
  intent_source: 'rule' | 'ai' | 'user'
  content_type: ContentType | null
  topic_category: string | null
  cluster_id: string | null
  country: string
  language: string
  sources: string[]
  primary_source: string
  evidence: Record<string, unknown>[]
  status: 'suggested' | 'approved' | 'rejected'
  metrics: {
    search_volume: number | null
    difficulty: number | null
    cpc_micro_usd: number | null
    provider: { source: string; search_volume: number | null; difficulty: number | null; cpc_micro_usd: number | null; trend: { month: string; volume: number }[] | null; collected_at: string | null } | null
    search_console: { impressions: number; clicks: number; ctr: number | null; position: number | null; period: [string | null, string | null]; collected_at: string | null } | null
  }
  opportunity: { score: number | null; kind: 'internal_estimate'; version: string | null; components: ScoreComponent[]; confidence: number | null; penalty: number; coverage: string | null }
  covered_by: { id: string; title: string; url?: string | null; similarity?: number; same_intent?: boolean }[]
  first_seen_at: string | null
  data_collected_at: string | null
}

export interface Idea {
  id: string
  title: string
  search_intent: Intent
  content_type: ContentType
  audience: string | null
  angle: string | null
  priority: 'high' | 'medium' | 'low'
  is_pillar: boolean
  status: 'suggested' | 'approved' | 'rejected' | 'briefed'
  source: 'ai' | 'rule' | 'user' | 'demo'
  cluster_id: string | null
  cluster_name: string | null
  research_run_id: string | null
  primary_keyword: { id: string; keyword: string } | null
  secondary_keywords: { id: string; keyword: string }[]
  rationale: string | null
  evidence: { keyword_sources?: string[]; gaps?: { type: string; topic: string }[]; related_existing?: { id: string; title: string; url?: string | null }[]; outline?: string[]; score_measured?: boolean }
  opportunity: { score: number | null; kind: 'internal_estimate'; components: ScoreComponent[]; measured: boolean }
  overlap: { content_item_id: string; score: number | null } | null
  brief: Record<string, unknown> | null
  calendar: { id: string; scheduled_for: string | null; status: string } | null
  decided_at: string | null
  created_at: string
}

export interface Cluster {
  id: string
  name: string
  intent: Intent
  status: 'active' | 'archived'
  description: string | null
  pillar_keyword: { id: string; keyword: string } | null
  pillar_content_item_id: string | null
  opportunity_score: number | null
  keywords_count: number
  ideas_count?: number
  coverage: { covered?: number; partial?: number; missing?: number }
  keywords?: Keyword[]
  ideas?: Idea[]
  updated_at: string | null
}

export type GapType = 'missing_topic' | 'competitor_topic' | 'unanswered_question' | 'cannibalization' | 'overlap' | 'striking_distance'
export interface Gap {
  id: string
  gap_type: GapType
  topic: string
  description: string | null
  evidence: Record<string, unknown>
  cluster_id: string | null
  competitor_website_id: string | null
  related_content_item_ids: string[]
  score: number | null
  status: 'open' | 'planned' | 'dismissed'
  research_run_id: string | null
  created_at: string
}

export interface LinkSuggestion {
  id: string
  cluster_id: string | null
  from: { type: string; id: string; label: string | null }
  to: { type: string; id: string; label: string | null }
  anchor_text: string
  reason: string | null
  score: number | null
  status: string
}

export interface Competitor {
  id: string
  name: string
  url: string
  status: 'active' | 'paused'
  robots_allowed: boolean | null
  pages_crawled: number
  findings_count?: number
  error: { code: string; message: string | null } | null
  last_crawled_at: string | null
  created_at: string
}

export interface CompetitorFinding {
  id: string
  kind: string
  title: string
  url: string | null
  headings: string[]
  fetched_at: string | null
}

export type CalendarStatus = 'proposed' | 'approved' | 'paused' | 'canceled' | 'done'
export interface CalendarItem {
  id: string
  campaign_id: string
  cluster_id: string | null
  scheduled_for: string
  position: number
  status: CalendarStatus
  source: string
  notes: string | null
  idea: { id: string; title: string; content_type: ContentType; search_intent: Intent; priority: string; is_pillar: boolean; status: string; opportunity_score: number | null } | null
  approved_at: string | null
}

export interface Knowledge {
  id: string
  kind: KnowledgeKind
  value: string
  source: 'website' | 'ai' | 'user' | 'demo'
  confidence: number | null
  evidence: Record<string, unknown>[]
  status: 'suggested' | 'confirmed' | 'rejected'
  edited_at: string | null
}

export interface Intelligence {
  profile: {
    title: string | null
    tagline: string | null
    description: string | null
    language: string | null
    region: string | null
    sitemap_url: string | null
    sitemap_url_count: number
    stats: Record<string, number>
    covered_topics: string[]
    status: string
    last_error_code: string | null
    last_crawled_at: string | null
    analyzed_at: string | null
  } | null
  knowledge: Knowledge[]
}

export interface Overview {
  campaign: Campaign
  last_run: ResearchRun | null
  ideas_by_status: Record<string, number>
  keywords_by_intent: Record<string, number>
  open_gaps: number
  upcoming: number
  usage: Usage
  schedule: Schedule | null
}

export interface Usage {
  runs: { used: number; reserved: number; limit: number | null }
  ideas: { used: number; reserved: number; limit: number | null }
  ai_cost_micro_usd: { used: number; reserved: number; limit: number | null }
}

export interface WorkspaceUsage {
  usage: Usage
  limits: Record<string, number>
  campaigns: number
  sources: { source: string; label: string; enabled: boolean; requires_credentials: boolean; configured: boolean }[]
}

export interface SearchConsoleState {
  configured: boolean
  connected: boolean
  status: string
  property_url: string | null
  google_email: string | null
  last_synced_at: string | null
  last_error_code: string | null
}

export interface ResearchNotification {
  id: string
  campaign_id: string | null
  type: string
  title: string
  body: string | null
  data: Record<string, unknown>
  read_at: string | null
  created_at: string
}

type Query = Record<string, string | number | boolean | undefined | null>

const R = (ws: string) => `/api/v1/workspaces/${encodeURIComponent(ws)}/research`
const C = (ws: string, c: string) => `${R(ws)}/campaigns/${encodeURIComponent(c)}`
const e = encodeURIComponent

export const researchApi = {
  usage: (ws: string) => api.get<Envelope<WorkspaceUsage>>(`${R(ws)}/usage`).then((r) => r.data),
  notifications: (ws: string, query: Query = {}) => api.get<Paginated<ResearchNotification> & { meta: { unread: number } }>(`${R(ws)}/notifications`, { query }),
  markRead: (ws: string, id: string) => api.post<Envelope<ResearchNotification>>(`${R(ws)}/notifications/${e(id)}/read`).then((r) => r.data),
  workspaceCalendar: (ws: string, query: Query) => api.get<Envelope<CalendarItem[]>>(`${R(ws)}/calendar`, { query }).then((r) => r.data),

  campaigns: (ws: string, query: Query = {}) => api.get<Paginated<Campaign>>(`${R(ws)}/campaigns`, { query }),
  createCampaign: (ws: string, data: CampaignInput) => api.post<Envelope<Campaign>>(`${R(ws)}/campaigns`, data).then((r) => r.data),
  campaign: (ws: string, c: string) => api.get<Envelope<Campaign>>(C(ws, c)).then((r) => r.data),
  updateCampaign: (ws: string, c: string, data: CampaignInput) => api.patch<Envelope<Campaign>>(C(ws, c), data).then((r) => r.data),
  deleteCampaign: (ws: string, c: string) => api.delete<void>(C(ws, c)),
  overview: (ws: string, c: string) => api.get<Envelope<Overview>>(`${C(ws, c)}/overview`).then((r) => r.data),
  settings: (ws: string, c: string) => api.get<Envelope<CampaignSettings>>(`${C(ws, c)}/settings`).then((r) => r.data),
  updateSettings: (ws: string, c: string, data: Partial<CampaignSettings>) => api.patch<Envelope<CampaignSettings>>(`${C(ws, c)}/settings`, data).then((r) => r.data),
  schedule: (ws: string, c: string) => api.get<Envelope<Schedule | null>>(`${C(ws, c)}/schedule`).then((r) => r.data),
  updateSchedule: (ws: string, c: string, data: Omit<Schedule, 'next_run_at' | 'last_run_at'>) => api.put<Envelope<Schedule>>(`${C(ws, c)}/schedule`, data).then((r) => r.data),

  intelligence: (ws: string, c: string) => api.get<Envelope<Intelligence>>(`${C(ws, c)}/intelligence`).then((r) => r.data),
  addKnowledge: (ws: string, c: string, data: { kind: KnowledgeKind; value: string }) => api.post<Envelope<Knowledge>>(`${C(ws, c)}/intelligence/knowledge`, data).then((r) => r.data),
  updateKnowledge: (ws: string, c: string, id: string, data: { value?: string; status?: Knowledge['status'] }) =>
    api.patch<Envelope<Knowledge>>(`${C(ws, c)}/intelligence/knowledge/${e(id)}`, data).then((r) => r.data),
  rejectKnowledge: (ws: string, c: string, id: string) => api.delete<void>(`${C(ws, c)}/intelligence/knowledge/${e(id)}`),

  runs: (ws: string, c: string, query: Query = {}) => api.get<Paginated<ResearchRun>>(`${C(ws, c)}/runs`, { query }),
  startRun: (ws: string, c: string, idempotencyKey: string, refreshProfile = false) =>
    api.post<Envelope<{ research_run: ResearchRun; job_run: { id: string | null; status: string; type: string } }>>(`${C(ws, c)}/runs`, { refresh_profile: refreshProfile }, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
  run: (ws: string, c: string, id: string) => api.get<Envelope<ResearchRun>>(`${C(ws, c)}/runs/${e(id)}`).then((r) => r.data),
  snapshots: (ws: string, c: string, id: string) => api.get<Envelope<Snapshot[]>>(`${C(ws, c)}/runs/${e(id)}/snapshots`).then((r) => r.data),
  cancelRun: (ws: string, c: string, id: string) => api.post<Envelope<ResearchRun>>(`${C(ws, c)}/runs/${e(id)}/cancel`).then((r) => r.data),
  retryRun: (ws: string, c: string, id: string) => api.post<Envelope<ResearchRun>>(`${C(ws, c)}/runs/${e(id)}/retry`).then((r) => r.data),

  keywords: (ws: string, c: string, query: Query = {}) => api.get<Paginated<Keyword>>(`${C(ws, c)}/keywords`, { query }),
  addKeyword: (ws: string, c: string, data: { keyword: string; intent?: Intent }) => api.post<Envelope<Keyword>>(`${C(ws, c)}/keywords`, data).then((r) => r.data),
  updateKeyword: (ws: string, c: string, id: string, data: { intent?: Intent; status?: Keyword['status']; topic_category?: string | null }) =>
    api.patch<Envelope<Keyword>>(`${C(ws, c)}/keywords/${e(id)}`, data).then((r) => r.data),
  bulkKeywords: (ws: string, c: string, ids: string[], status: Keyword['status']) => api.post<Envelope<{ updated: number }>>(`${C(ws, c)}/keywords/bulk`, { ids, status }).then((r) => r.data),

  ideas: (ws: string, c: string, query: Query = {}) => api.get<Paginated<Idea>>(`${C(ws, c)}/ideas`, { query }),
  idea: (ws: string, c: string, id: string) => api.get<Envelope<Idea>>(`${C(ws, c)}/ideas/${e(id)}`).then((r) => r.data),
  updateIdea: (ws: string, c: string, id: string, data: Partial<Pick<Idea, 'title' | 'angle' | 'audience' | 'content_type' | 'search_intent' | 'priority'>> & { status?: 'suggested' | 'approved' | 'rejected' }) =>
    api.patch<Envelope<Idea>>(`${C(ws, c)}/ideas/${e(id)}`, data).then((r) => r.data),
  brief: (ws: string, c: string, id: string) => api.post<Envelope<Idea>>(`${C(ws, c)}/ideas/${e(id)}/brief`).then((r) => r.data),

  clusters: (ws: string, c: string) => api.get<Envelope<Cluster[]>>(`${C(ws, c)}/clusters`).then((r) => r.data),
  cluster: (ws: string, c: string, id: string) => api.get<Envelope<Cluster>>(`${C(ws, c)}/clusters/${e(id)}`).then((r) => r.data),
  updateCluster: (ws: string, c: string, id: string, data: { name?: string; description?: string | null; status?: Cluster['status'] }) =>
    api.patch<Envelope<Cluster>>(`${C(ws, c)}/clusters/${e(id)}`, data).then((r) => r.data),
  gaps: (ws: string, c: string, query: Query = {}) => api.get<Paginated<Gap>>(`${C(ws, c)}/gaps`, { query }),
  updateGap: (ws: string, c: string, id: string, status: Gap['status']) => api.patch<Envelope<Gap>>(`${C(ws, c)}/gaps/${e(id)}`, { status }).then((r) => r.data),
  links: (ws: string, c: string, query: Query = {}) => api.get<Envelope<LinkSuggestion[]>>(`${C(ws, c)}/internal-links`, { query }).then((r) => r.data),

  competitors: (ws: string, c: string) => api.get<Envelope<Competitor[]>>(`${C(ws, c)}/competitors`).then((r) => r.data),
  addCompetitor: (ws: string, c: string, data: { url: string; name?: string }) => api.post<Envelope<Competitor>>(`${C(ws, c)}/competitors`, data).then((r) => r.data),
  updateCompetitor: (ws: string, c: string, id: string, data: { name?: string; status?: Competitor['status'] }) =>
    api.patch<Envelope<Competitor>>(`${C(ws, c)}/competitors/${e(id)}`, data).then((r) => r.data),
  removeCompetitor: (ws: string, c: string, id: string) => api.delete<void>(`${C(ws, c)}/competitors/${e(id)}`),
  findings: (ws: string, c: string, id: string, query: Query = {}) => api.get<Paginated<CompetitorFinding>>(`${C(ws, c)}/competitors/${e(id)}/findings`, { query }),

  calendar: (ws: string, c: string, query: Query) => api.get<Envelope<CalendarItem[]>>(`${C(ws, c)}/calendar`, { query }).then((r) => r.data),
  schedule_item: (ws: string, c: string, data: { content_idea_id: string; scheduled_for: string; position?: number; notes?: string | null }) =>
    api.post<Envelope<CalendarItem>>(`${C(ws, c)}/calendar`, data).then((r) => r.data),
  updateItem: (ws: string, c: string, id: string, data: { scheduled_for?: string; position?: number; status?: CalendarStatus; notes?: string | null }) =>
    api.patch<Envelope<CalendarItem>>(`${C(ws, c)}/calendar/${e(id)}`, data).then((r) => r.data),
  removeItem: (ws: string, c: string, id: string) => api.delete<void>(`${C(ws, c)}/calendar/${e(id)}`),
  autoPlan: (ws: string, c: string, includeSuggested = false) =>
    api.post<Envelope<{ scheduled: number; skipped_limit: number }>>(`${C(ws, c)}/calendar/auto-plan`, { include_suggested: includeSuggested }).then((r) => r.data),

  searchConsole: (ws: string, c: string) => api.get<Envelope<SearchConsoleState>>(`${C(ws, c)}/search-console`).then((r) => r.data),
  connectSearchConsole: (ws: string, c: string) => api.post<Envelope<{ authorization_url: string }>>(`${C(ws, c)}/search-console/connect`).then((r) => r.data),
  searchConsoleProperties: (ws: string, c: string) => api.get<Envelope<{ site_url: string; permission: string }[]>>(`${C(ws, c)}/search-console/properties`).then((r) => r.data),
  setSearchConsoleProperty: (ws: string, c: string, property_url: string) =>
    api.put<Envelope<SearchConsoleState>>(`${C(ws, c)}/search-console/property`, { property_url }).then((r) => r.data),
  disconnectSearchConsole: (ws: string, c: string) => api.delete<void>(`${C(ws, c)}/search-console`),
}

/** A run that has not finished yet. */
export function isActiveRun(run: Pick<ResearchRun, 'status'> | null | undefined): boolean {
  return run?.status === 'queued' || run?.status === 'running'
}

export function runTone(status: RunStatus): 'success' | 'warning' | 'danger' | 'neutral' | 'brand' {
  return ({ succeeded: 'success', partial: 'warning', failed: 'danger', canceled: 'neutral', queued: 'brand', running: 'brand' } as const)[status]
}

export function scoreTone(score: number | null | undefined): 'success' | 'warning' | 'neutral' {
  if (score === null || score === undefined) return 'neutral'
  return score >= 70 ? 'success' : score >= 45 ? 'warning' : 'neutral'
}

/** Comma / newline separated text → unique trimmed list. */
export function splitList(text: string): string[] {
  const out: string[] = []
  for (const part of text.split(/[\n,]/)) {
    const v = part.trim()
    if (v && !out.includes(v)) out.push(v)
  }
  return out
}

export function formatUsd(micro: number | null | undefined): string {
  if (micro === null || micro === undefined) return '—'
  return `$${(micro / 1_000_000).toFixed(micro < 10_000 ? 4 : 2)}`
}

export function formatNumber(n: number | null | undefined): string {
  return n === null || n === undefined ? '—' : new Intl.NumberFormat('vi-VN').format(n)
}

/** Monday-first weeks covering the month of `anchor` (dates as YYYY-MM-DD). */
export function monthGrid(anchor: Date): string[][] {
  const first = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth(), 1))
  const start = new Date(first)
  start.setUTCDate(1 - ((first.getUTCDay() + 6) % 7))
  const weeks: string[][] = []
  const cur = new Date(start)
  do {
    const week: string[] = []
    for (let i = 0; i < 7; i++) {
      week.push(cur.toISOString().slice(0, 10))
      cur.setUTCDate(cur.getUTCDate() + 1)
    }
    weeks.push(week)
  } while (cur.getUTCMonth() === anchor.getUTCMonth())
  return weeks
}
