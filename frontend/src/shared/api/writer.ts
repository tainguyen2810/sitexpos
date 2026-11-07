import { api } from './client'
import type { Envelope, Paginated } from './types'

/**
 * Phase 5 — AI Content Writer, evidence research & fact-check API.
 * Every AI step runs on the queue: start endpoints answer 202 and the UI
 * polls the article. Content HTML is server-sanitized; the UI sanitizes it
 * again before rendering (SafeHtml / DOMPurify).
 */
export const ARTICLE_STATUSES = [
  'IDEA_SELECTED', 'RESEARCHING', 'RESEARCH_COMPLETE', 'BRIEF_READY', 'OUTLINING', 'WRITING', 'FACT_CHECKING',
  'REVISING', 'QUALITY_REVIEW', 'NEEDS_HUMAN_REVIEW', 'APPROVED', 'FAILED', 'CANCELLED',
] as const
export type ArticleStatus = (typeof ARTICLE_STATUSES)[number]

export const CONTENT_TYPES = ['blog_article', 'how_to', 'product_guide', 'comparison', 'troubleshooting', 'faq', 'pillar', 'cluster', 'service', 'buying_guide'] as const
export type WriterContentType = (typeof CONTENT_TYPES)[number]
export type ReviewMode = 'step_by_step' | 'brief_only' | 'final_only'
export type Depth = 'basic' | 'standard' | 'deep'
export type SearchIntent = 'informational' | 'commercial' | 'transactional' | 'navigational'
export type WorkflowKind = 'research' | 'outline' | 'draft' | 'recheck' | 'rewrite'
export type RunStatus = 'pending' | 'running' | 'retry_scheduled' | 'succeeded' | 'failed' | 'canceled'
export const VERDICTS = ['VERIFIED', 'CONTRADICTED', 'INSUFFICIENT_EVIDENCE', 'OUTDATED', 'NEEDS_HUMAN_REVIEW'] as const
export type Verdict = (typeof VERDICTS)[number]
export type ClaimStatus = Verdict | 'PENDING'
export const RISK_LEVELS = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as const
export type RiskLevel = (typeof RISK_LEVELS)[number]
export const REWRITE_ACTIONS = ['rewrite', 'expand', 'shorten', 'tone', 'grammar', 'regenerate_section'] as const
export type RewriteAction = (typeof REWRITE_ACTIONS)[number]
export const PROVENANCES = ['user_provided', 'website_extracted', 'source_verified', 'ai_inferred'] as const
export type Provenance = (typeof PROVENANCES)[number]
export type DuplicateDecision = 'create_new' | 'update_existing' | 'merge' | 'reject'

export interface ErrorRef { code: string; message: string | null }

export interface WorkflowStep {
  key: string
  label: string
  status: string
  attempts: number
  error: ErrorRef | null
  started_at: string | null
  finished_at: string | null
}

export interface Workflow {
  id: string
  kind: WorkflowKind
  type: string
  status: RunStatus
  current_step: string | null
  current_step_label: string | null
  progress: number
  steps: WorkflowStep[]
  error: ErrorRef | null
  started_at: string | null
  finished_at: string | null
  created_at: string | null
}

export interface DuplicateCheck {
  recommendation: 'create_new' | 'update_existing' | 'merge' | 'reject' | string
  reason?: string | null
  checked_at?: string
  matches?: { type: string; id: string | null; title: string; url: string | null; title_similarity: number; content_similarity: number; same_keyword: boolean; risk: string; cluster?: string | null }[]
}

export interface Article {
  id: string
  title: string
  status: ArticleStatus
  next_statuses: ArticleStatus[]
  mode: 'manual' | 'autonomous'
  review_mode: ReviewMode
  site_id: string
  campaign_id: string | null
  content_idea_id: string | null
  writing_preset_id: string | null
  writing_batch_id: string | null
  primary_keyword: string
  secondary_keywords: string[]
  search_intent: SearchIntent
  content_type: WriterContentType
  language: 'vi' | 'en'
  country: string
  settings: Record<string, unknown>
  budget: { limit_micro_usd: number; spent_micro_usd: number; remaining_micro_usd: number }
  current_version_id: string | null
  approved_version_id: string | null
  revision_rounds: number
  unresolved_critical: number
  unresolved_high: number
  quality_score: number | null
  duplicate_check: DuplicateCheck | null
  duplicate_decision: DuplicateDecision | null
  blocked_reason: string | null
  last_error: ErrorRef | null
  status_before_failure: ArticleStatus | null
  lock_version: number
  has_autosave: boolean
  autosaved_at: string | null
  is_demo: boolean
  approved_by: string | null
  approved_at: string | null
  created_by: string | null
  created_at: string
  updated_at: string
}

export interface ArticleDetail extends Article {
  workflow: Workflow | null
  brief: { id: string; version: number; status: string } | null
  outline: { id: string; version: number; status: string } | null
  waiting_for: 'brief_approval' | 'outline_approval' | 'human_review' | null
}

export interface Overview {
  quota: { period: string; used: number; limit: number; remaining: number }
  cost_this_month_micro_usd: number
  active_workflows: number
  status_counts: Partial<Record<ArticleStatus, number>>
  needs_review: number
}

export type ApprovalMode = 'manual' | 'auto'

/** Result of the last auto-approval attempt, kept in article.settings. */
export interface AutoApproveResult { approved: boolean; reasons: string[]; at: string }

export function autoApproveResult(settings: Record<string, unknown>): AutoApproveResult | null {
  const r = settings.auto_approve_result as AutoApproveResult | undefined
  return r && typeof r === 'object' && Array.isArray(r.reasons) ? r : null
}

export function approvalModeOf(settings: Record<string, unknown>): ApprovalMode {
  return settings.approval_mode === 'auto' ? 'auto' : 'manual'
}

export interface WriterOptions {
  content_types: WriterContentType[]
  review_modes: ReviewMode[]
  depths: Depth[]
  limits: {
    default_article_budget_micro_usd: number
    max_article_budget_micro_usd: number
    max_batch_size: number
    max_batch_concurrency: number
    max_sources: Record<Depth, number>
    min_quality_score_for_approval: number
  }
  auto_approve: { enabled: boolean; min_quality: number }
  builtin_styles?: BuiltinStyleKey[]
}

export interface WriterIdea {
  id: string
  title: string
  primary_keyword: string
  secondary_keywords?: string[]
  search_intent?: SearchIntent
  content_type?: string
  audience?: string | null
  angle?: string | null
  status: string
  campaign_id: string
  site_id: string
  article_id: string | null
}

export interface ArticleInput {
  site_id: string
  campaign_id?: string | null
  content_idea_id?: string | null
  writing_preset_id?: string | null
  builtin_style?: BuiltinStyleKey | null
  auto_images?: boolean | null
  topic?: string | null
  title?: string | null
  primary_keyword?: string | null
  secondary_keywords?: string[]
  search_intent?: SearchIntent | null
  content_type?: WriterContentType | null
  audience?: string | null
  tone?: string | null
  target_words?: number
  content_depth?: Depth
  research_depth?: Depth
  internal_links?: { enabled: boolean; max: number }
  brand_rules?: string[]
  source_urls?: string[]
  brand_domains?: string[]
  cta?: string | null
  notes?: string | null
  ai_source_discovery?: boolean
  allow_web_search?: boolean
  ai_review_notes?: boolean
  review_mode?: ReviewMode
  approval_mode?: ApprovalMode
  budget_limit_micro_usd?: number
  language?: 'vi' | 'en'
  country?: string
  start?: boolean
}

export interface Dossier {
  id: string
  status: string
  depth: Depth
  retrieval_available: boolean
  questions_count: number
  sources_count: number
  usable_sources_count: number
  evidence_count: number
  rejected_evidence_count: number
  warnings: string[]
  summary: string | null
  completed_at: string | null
  created_at: string | null
  questions: { id: string; position: number; question: string; purpose: string | null; origin: string; status: string }[]
  sources: EvidenceSource[]
}

export interface EvidenceSource {
  id: string
  url: string
  final_url: string | null
  title: string | null
  publisher: string | null
  author: string | null
  published_at: string | null
  modified_at: string | null
  retrieved_at: string | null
  source_type: string
  discovered_via: string
  retrieval_status: string
  http_status: number | null
  error_code: string | null
  reliability_score: number | null
  reliability: Record<string, unknown> | null
  license_note: string | null
}

export interface Evidence {
  id: string
  ref: number
  source_id: string
  question_id: string | null
  excerpt: string
  statement: string
  date_context: string | null
  version_context: string | null
  relevance: number | null
  extracted_by_model: string | null
  source: { url: string; title: string | null; source_type: string; reliability_score: number | null; published_at: string | null; retrieved_at: string | null } | null
}

export interface BriefData {
  title_suggestions: string[]
  search_intent?: SearchIntent
  audience: string
  main_question: string
  supporting_questions: string[]
  primary_keyword?: string
  secondary_keywords?: string[]
  content_angle: string
  differentiation: string
  structure: { heading: string; level: number; purpose: string }[]
  key_facts: { fact: string; evidence_refs: number[] }[]
  missing_information: string[]
  internal_links: { url: string; anchor: string }[]
  cta: string | null
  faq: string[]
  risks?: string[]
  required_evidence?: unknown[]
  [k: string]: unknown
}

export interface Brief {
  id: string
  version: number
  status: string
  data: BriefData
  warnings: string[]
  research_dossier_id: string | null
  generated_by_model: string | null
  edited_by: string | null
  approved_by: string | null
  approved_at: string | null
  created_at: string | null
}

export interface OutlineSection {
  key: string
  heading: string
  level?: number
  purpose: string
  key_questions: string[]
  required_facts: { fact: string; evidence_refs: number[]; needs_evidence?: boolean }[]
  evidence_refs: number[]
  word_range: [number, number]
  internal_links: string[]
  blocks: string[]
  subheadings: string[]
}

export interface Outline {
  id: string
  version: number
  status: string
  sections: OutlineSection[]
  warnings: string[]
  content_brief_id: string | null
  generated_by_model: string | null
  approved_by: string | null
  approved_at: string | null
  created_at: string | null
}

export interface History { id: string; version: number; status: string; created_at: string | null }

export interface Version {
  id: string
  number: number
  source: string
  title: string
  word_count: number
  content_hash: string
  parent_version_id: string | null
  sections: { key: string; heading: string; claims_checked: boolean }[]
  change_summary: string | null
  model: string | null
  created_by: string | null
  created_at: string | null
  content_html?: string
}

export interface Citation { id: string; ref: number; statement: string; url: string | null; title: string | null; source_type: string | null; reliability_score: number | null }

export interface EditorState {
  version: Version | null
  lock_version: number
  autosave: { content_html: string; base_version_id: string; autosaved_at: string } | null
  citations: Citation[]
  rewrite_actions: RewriteAction[]
}

export interface DiffOp { op: 'eq' | 'ins' | 'del'; text: string }
export interface Diff {
  from: Version
  to: Version
  change_ratio: number
  sections: { key: string; heading: string; change: 'added' | 'modified' | 'removed'; ops: DiffOp[] }[]
}

export interface Revision {
  id: string
  kind: 'editor' | 'auto_fix'
  action: string
  round: number
  status: 'queued' | 'proposed' | 'applied' | 'discarded' | 'failed' | 'stale' | 'rejected'
  section_key: string | null
  from_version_id: string | null
  to_version_id: string | null
  claim_ids: string[]
  request: Record<string, unknown> | null
  diff: DiffOp[]
  proposed_html: string | null
  model: string | null
  error_code: string | null
  created_by: string | null
  created_at: string | null
}

export interface ResultEvidence {
  evidence_id: string
  ref: number
  url: string | null
  title: string | null
  excerpt: string
  source_type: string | null
  reliability: number | null
  date: string | null
  version_context: string | null
}

export interface FactResult {
  id: string
  verdict: Verdict
  supporting: ResultEvidence[]
  contradicting: ResultEvidence[]
  date_context: string | null
  explanation: string | null
  rule_notes: string[]
  reviewer_type: 'ai' | 'rule' | 'human' | 'demo' | string
  reviewer_model: string | null
  reviewer_user_id: string | null
  reviewed_at: string | null
}

export interface Claim {
  id: string
  version_id: string
  section_key: string | null
  claim: string
  category: string
  risk_level: RiskLevel
  evidence_requirements: string | null
  origin: string
  extracted_by_model: string | null
  extracted_at: string | null
  status: ClaimStatus
  is_stale: boolean
  latest_result: FactResult | null
  history?: FactResult[]
}

export interface FactCheckRun {
  id: string
  version_id: string
  round: number
  status: string
  claims_total: number
  counts: Record<string, number>
  consistency_issues: { type: string; message: string; severity: string }[]
  model: string | null
  started_at: string | null
  finished_at: string | null
}

export interface QualityReport {
  id: string
  version_id: string
  rubric_version: string
  score: number
  criteria: { key: string; score: number; max: number; notes: string[] }[]
  issues: { severity: 'error' | 'warning' | 'info' | string; message: string }[]
  duplicates: DuplicateCheck | null
  ai_notes: string[]
  disclaimer: string
  created_at: string | null
}

export interface GateItem { code: string; message: string; requires_ack?: boolean }
export interface Approval {
  id: string
  version_id: string
  decision: 'approved' | 'changes_requested' | string
  note: string | null
  checks: unknown
  user_id: string | null
  invalidated_at: string | null
  invalidated_reason: string | null
  created_at: string | null
}
export interface Checklist {
  ok: boolean
  blocking: GateItem[]
  warnings: GateItem[]
  stats: Record<string, unknown> & { unresolved_critical: number; unresolved_high: number; quality_score: number | null; min_quality: number }
  can_approve: boolean
  approvals: Approval[]
}

export interface Usage {
  budget_limit_micro_usd: number
  spent_micro_usd: number
  remaining_micro_usd: number
  blocked_reason: string | null
  breakdown: { step: string; task_key: string; model: string | null; calls: number; input_tokens: number; output_tokens: number; cost_micro_usd: number; fetches: number; failed: number }[]
}

export interface PresetFact { text: string; provenance: Provenance; source_url?: string | null }
export interface Preset {
  id: string
  name: string
  site_id: string | null
  is_default: boolean
  tone: string | null
  writing_style: string | null
  preferred_terms: string[]
  forbidden_phrases: string[]
  facts: PresetFact[]
  target_customers: string[]
  geographic_context: string | null
  editorial_guidelines: string | null
  cta: string | null
  defaults: {
    content_type?: string; target_words?: number; research_depth?: Depth; content_depth?: Depth; tone?: string; budget_limit_micro_usd?: number
    auto_images?: boolean | null; min_images?: number | null
  }
  is_demo: boolean
  style_profile: StyleProfile | null
  style_draft: (StyleProfile & { generated_at?: string; model?: string; cost_micro_usd?: number; sample_count?: number }) | null
  style_sources: {
    used?: { type: string; title: string; url: string | null; words: number }[]
    skipped?: { title: string; url: string | null; code: string }[]
    origin?: { type: StyleOrigin | 'builtin'; label: string | null } | null
  } | null
  style_learned_at: string | null
  created_at: string | null
  updated_at: string | null
}
export type PresetInput = Partial<Omit<Preset, 'id' | 'is_demo' | 'created_at' | 'updated_at' | 'style_draft' | 'style_sources' | 'style_learned_at'>> & { from_builtin?: BuiltinStyleKey | null }

/** Ready-made styles (names/descriptions in writer.builtin.<key>). Mirrors backend BuiltinStyles::KEYS. */
export const BUILTIN_STYLES = ['friendly', 'expert', 'step_by_step', 'sales', 'storytelling', 'news', 'formal'] as const
export type BuiltinStyleKey = (typeof BUILTIN_STYLES)[number]
export const BUILTIN_STYLE_ICONS: Record<BuiltinStyleKey, string> = {
  friendly: '😊', expert: '🎓', step_by_step: '🧭', sales: '💰', storytelling: '📖', news: '📰', formal: '🏛️',
}
/** Where a learned style's samples came from. */
export const STYLE_ORIGINS = ['post', 'category', 'author', 'blog', 'product', 'paste'] as const
export type StyleOrigin = (typeof STYLE_ORIGINS)[number]
export const STYLE_ORIGIN_ICONS: Record<StyleOrigin, string> = { post: '📄', category: '🗂️', author: '👤', blog: '📚', product: '🛒', paste: '📋' }

/** Article length choices of the simple writer: label key → target words. */
export const LENGTHS = { short: 800, medium: 1500, long: 2500 } as const
export type LengthKey = keyof typeof LENGTHS
export function lengthOf(words: number): LengthKey {
  return words <= 1100 ? 'short' : words <= 2000 ? 'medium' : 'long'
}
/** Rough reading time (Vietnamese ≈ 220 words/minute). */
export function readingMinutes(words: number): number {
  return Math.max(1, Math.round(words / 220))
}
/** A preset counts as a "style of yours" when it has an applied style guide. */
export function hasStyle(p: Pick<Preset, 'style_profile'>): boolean {
  return !!p.style_profile && Object.values(p.style_profile).some((v) => (Array.isArray(v) ? v.length > 0 : !!v && String(v).trim() !== ''))
}

export const STYLE_TEXT_FIELDS = ['summary', 'tone', 'addressing', 'sentence_style', 'paragraph_style', 'intro_pattern', 'conclusion_pattern', 'heading_style', 'formatting'] as const
export const STYLE_LIST_FIELDS = ['vocabulary', 'signature_phrases', 'avoid', 'dos', 'donts'] as const
export type StyleProfile = { [K in (typeof STYLE_TEXT_FIELDS)[number]]?: string } & { [K in (typeof STYLE_LIST_FIELDS)[number]]?: string[] }
export interface LearnStyleInput {
  site_id?: string | null
  content_item_ids?: string[]
  samples?: { title?: string | null; text: string }[]
  origin?: { type: StyleOrigin; label?: string | null } | null
}

export interface BatchItem { id: string; position: number; title: string; content_idea_id: string | null; article_id: string | null; status: string; attempts: number; error: ErrorRef | null }
export interface Batch {
  id: string
  site_id: string
  campaign_id: string | null
  writing_preset_id: string | null
  source: string
  status: 'queued' | 'running' | 'paused' | 'completed' | 'canceled' | 'failed' | string
  total: number
  concurrency: number
  settings: Record<string, unknown>
  per_article_budget_micro_usd: number
  estimated_cost_micro_usd: number
  reserved_cost_micro_usd: number
  spent_micro_usd: number
  counts: Record<string, number>
  is_demo: boolean
  paused_at: string | null
  finished_at: string | null
  created_at: string | null
  items?: BatchItem[]
}
export interface BatchInput {
  campaign_id: string
  content_idea_ids: string[]
  concurrency?: number
  per_article_budget_micro_usd?: number
  writing_preset_id?: string | null
  settings?: { target_words?: number; tone?: string; research_depth?: Depth; review_mode?: 'brief_only' | 'final_only'; approval_mode?: ApprovalMode }
}
export interface BatchPreview {
  campaign: { id: string; name: string; site_id: string }
  items: { id: string; title: string; primary_keyword: string; duplicate: boolean }[]
  not_approved_or_missing: string[]
  creatable: number
  estimated_cost_per_article_micro_usd: number
  estimated_cost_micro_usd: number
  max_cost_micro_usd: number
  quota: Overview['quota']
  limits: { max_batch_size: number; max_concurrency: number }
}

export interface HistoryRow extends Workflow { article: { id: string; title: string; status: ArticleStatus } | null }

type Query = Record<string, string | number | boolean | undefined | null>
const e = encodeURIComponent
const W = (ws: string) => `/api/v1/workspaces/${e(ws)}/writer`
const A = (ws: string, id: string) => `${W(ws)}/articles/${e(id)}`

/** A fresh Idempotency-Key per user intent (a double click reuses it). */
export function idempotencyKey(): string {
  const c = globalThis.crypto
  return c?.randomUUID ? c.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}
const idem = (key?: string) => ({ headers: { 'Idempotency-Key': key ?? idempotencyKey() } })

export const writerApi = {
  overview: (ws: string) => api.get<Envelope<Overview>>(`${W(ws)}/overview`).then((r) => r.data),
  options: (ws: string) => api.get<Envelope<WriterOptions>>(`${W(ws)}/options`).then((r) => r.data),
  ideas: (ws: string, campaignId: string) => api.get<Envelope<WriterIdea[]>>(`${W(ws)}/ideas`, { query: { campaign_id: campaignId } }).then((r) => r.data),
  estimate: (ws: string, targetWords: number) =>
    api.get<Envelope<{ target_words: number; estimated_cost_micro_usd: number; note: string }>>(`${W(ws)}/estimate`, { query: { target_words: targetWords } }).then((r) => r.data),
  history: (ws: string, query: Query = {}) => api.get<Paginated<HistoryRow>>(`${W(ws)}/history`, { query }),

  articles: (ws: string, query: Query = {}) => api.get<Paginated<Article>>(`${W(ws)}/articles`, { query }),
  create: (ws: string, data: ArticleInput, key?: string) => api.post<Envelope<ArticleDetail>>(`${W(ws)}/articles`, data, idem(key)).then((r) => r.data),
  article: (ws: string, id: string) => api.get<Envelope<ArticleDetail>>(A(ws, id)).then((r) => r.data),
  update: (ws: string, id: string, data: { title?: string; review_mode?: ReviewMode; approval_mode?: ApprovalMode; budget_limit_micro_usd?: number; writing_preset_id?: string | null; source_urls?: string[]; allow_web_search?: boolean; ai_source_discovery?: boolean }) =>
    api.patch<Envelope<ArticleDetail>>(A(ws, id), data).then((r) => r.data),
  cancel: (ws: string, id: string) => api.post<Envelope<ArticleDetail>>(`${A(ws, id)}/cancel`).then((r) => r.data),
  workflows: (ws: string, id: string) => api.get<Envelope<Workflow[]>>(`${A(ws, id)}/workflows`).then((r) => r.data),
  start: (ws: string, id: string, kind: Exclude<WorkflowKind, 'rewrite'>, opts: { full?: boolean; auto_revise?: boolean } = {}, key?: string) =>
    api.post<Envelope<{ article: ArticleDetail }>>(`${A(ws, id)}/workflows`, { kind, ...opts }, idem(key)).then((r) => r.data.article),
  resume: (ws: string, id: string) => api.post<Envelope<{ article: ArticleDetail }>>(`${A(ws, id)}/workflows/resume`).then((r) => r.data.article),
  cancelWorkflow: (ws: string, id: string) => api.post<Envelope<ArticleDetail>>(`${A(ws, id)}/workflows/cancel`).then((r) => r.data),

  dossier: (ws: string, id: string) => api.get<Envelope<Dossier | null>>(`${A(ws, id)}/research`).then((r) => r.data),
  evidence: (ws: string, id: string, query: Query = {}) => api.get<Paginated<Evidence>>(`${A(ws, id)}/evidence`, { query }),
  brief: (ws: string, id: string) => api.get<Envelope<{ current: Brief | null; history: History[] }>>(`${A(ws, id)}/brief`).then((r) => r.data),
  approveBrief: (ws: string, id: string, data?: Partial<BriefData>) =>
    api.post<Envelope<Brief>>(`${A(ws, id)}/brief/approve`, data ? { data } : {}).then((r) => r.data),
  outline: (ws: string, id: string) => api.get<Envelope<{ current: Outline | null; history: History[] }>>(`${A(ws, id)}/outline`).then((r) => r.data),
  approveOutline: (ws: string, id: string, sections?: OutlineSection[]) =>
    api.post<Envelope<Outline>>(`${A(ws, id)}/outline/approve`, sections ? { sections } : {}).then((r) => r.data),

  editor: (ws: string, id: string) => api.get<Envelope<EditorState>>(`${A(ws, id)}/editor`).then((r) => r.data),
  versions: (ws: string, id: string, query: Query = {}) => api.get<Paginated<Version>>(`${A(ws, id)}/versions`, { query }),
  version: (ws: string, id: string, versionId: string) => api.get<Envelope<Version>>(`${A(ws, id)}/versions/${e(versionId)}`).then((r) => r.data),
  diff: (ws: string, id: string, from: string, to?: string) => api.get<Envelope<Diff>>(`${A(ws, id)}/diff`, { query: { from, to } }).then((r) => r.data),
  save: (ws: string, id: string, data: { content_html: string; base_version_id: string; summary?: string | null }) =>
    api.post<Envelope<{ version: Version; lock_version: number; status: ArticleStatus; unchanged: boolean }>>(`${A(ws, id)}/versions`, data).then((r) => r.data),
  autosave: (ws: string, id: string, data: { content_html: string; base_version_id: string; lock_version: number }) =>
    api.put<Envelope<{ lock_version: number; autosaved_at: string }>>(`${A(ws, id)}/autosave`, data).then((r) => r.data),
  restore: (ws: string, id: string, versionId: string) =>
    api.post<Envelope<{ version: Version; lock_version: number }>>(`${A(ws, id)}/versions/${e(versionId)}/restore`).then((r) => r.data),
  revisions: (ws: string, id: string, query: Query = {}) => api.get<Paginated<Revision>>(`${A(ws, id)}/revisions`, { query }),
  rewrite: (ws: string, id: string, data: { action: RewriteAction; section_key: string; selection?: string | null; tone?: string | null }, key?: string) =>
    api.post<Envelope<Revision>>(`${A(ws, id)}/revisions`, data, idem(key)).then((r) => r.data),
  revision: (ws: string, id: string, revId: string) => api.get<Envelope<Revision>>(`${A(ws, id)}/revisions/${e(revId)}`).then((r) => r.data),
  applyRevision: (ws: string, id: string, revId: string) =>
    api.post<Envelope<{ version: Version; lock_version: number }>>(`${A(ws, id)}/revisions/${e(revId)}/apply`).then((r) => r.data),
  discardRevision: (ws: string, id: string, revId: string) => api.post<Envelope<Revision>>(`${A(ws, id)}/revisions/${e(revId)}/discard`).then((r) => r.data),

  claims: (ws: string, id: string, query: Query = {}) => api.get<Paginated<Claim>>(`${A(ws, id)}/claims`, { query }),
  claim: (ws: string, id: string, claimId: string) => api.get<Envelope<Claim>>(`${A(ws, id)}/claims/${e(claimId)}`).then((r) => r.data),
  verifyClaim: (ws: string, id: string, claimId: string, data: { verdict: Verdict; note: string; evidence_ids?: string[]; reference_url?: string | null }) =>
    api.post<Envelope<Claim & { article: { unresolved_critical: number; unresolved_high: number; status: ArticleStatus } }>>(`${A(ws, id)}/claims/${e(claimId)}/verify`, data).then((r) => r.data),
  factChecks: (ws: string, id: string, query: Query = {}) => api.get<Paginated<FactCheckRun>>(`${A(ws, id)}/fact-checks`, { query }),
  quality: (ws: string, id: string) => api.get<Envelope<{ current: QualityReport | null; history: { id: string; version_id: string; score: number; created_at: string | null }[] }>>(`${A(ws, id)}/quality`).then((r) => r.data),
  audit: (ws: string, id: string) => api.post<Envelope<QualityReport>>(`${A(ws, id)}/quality/audit`).then((r) => r.data),
  checklist: (ws: string, id: string, ack = false) => api.get<Envelope<Checklist>>(`${A(ws, id)}/approval`, { query: { acknowledge_high_risk: ack ? 1 : undefined } }).then((r) => r.data),
  approve: (ws: string, id: string, data: { version_id: string; acknowledge_high_risk?: boolean; note?: string | null }) =>
    api.post<Envelope<{ approval: Approval; article: Article }>>(`${A(ws, id)}/approve`, data).then((r) => r.data),
  requestChanges: (ws: string, id: string, note: string) => api.post<Envelope<{ approval: Approval; article: Article }>>(`${A(ws, id)}/request-changes`, { note }).then((r) => r.data),
  duplicateDecision: (ws: string, id: string, decision: DuplicateDecision, note?: string | null) =>
    api.post<Envelope<Article>>(`${A(ws, id)}/duplicate-decision`, { decision, note }).then((r) => r.data),
  usage: (ws: string, id: string) => api.get<Envelope<Usage>>(`${A(ws, id)}/usage`).then((r) => r.data),

  presets: (ws: string, query: Query = {}) => api.get<Envelope<Preset[]>>(`${W(ws)}/presets`, { query }).then((r) => r.data),
  preset: (ws: string, id: string) => api.get<Envelope<Preset>>(`${W(ws)}/presets/${e(id)}`).then((r) => r.data),
  createPreset: (ws: string, data: PresetInput) => api.post<Envelope<Preset>>(`${W(ws)}/presets`, data).then((r) => r.data),
  updatePreset: (ws: string, id: string, data: PresetInput) => api.patch<Envelope<Preset>>(`${W(ws)}/presets/${e(id)}`, data).then((r) => r.data),
  deletePreset: (ws: string, id: string) => api.delete<void>(`${W(ws)}/presets/${e(id)}`),
  learnStyle: (ws: string, id: string, data: LearnStyleInput) =>
    api.post<Envelope<{ job_run: { id: string; status: string; type: string } }>>(`${W(ws)}/presets/${e(id)}/learn-style`, data).then((r) => r.data.job_run),
  discardStyleDraft: (ws: string, id: string) => api.delete<Envelope<Preset>>(`${W(ws)}/presets/${e(id)}/style-draft`).then((r) => r.data),

  batches: (ws: string, query: Query = {}) => api.get<Paginated<Batch>>(`${W(ws)}/batches`, { query }),
  previewBatch: (ws: string, data: BatchInput) => api.post<Envelope<BatchPreview>>(`${W(ws)}/batches/preview`, data).then((r) => r.data),
  createBatch: (ws: string, data: BatchInput, key?: string) => api.post<Envelope<Batch>>(`${W(ws)}/batches`, data, idem(key)).then((r) => r.data),
  batch: (ws: string, id: string) => api.get<Envelope<Batch>>(`${W(ws)}/batches/${e(id)}`).then((r) => r.data),
  batchAction: (ws: string, id: string, action: 'pause' | 'resume' | 'cancel' | 'retry') => api.post<Envelope<Batch>>(`${W(ws)}/batches/${e(id)}/${action}`).then((r) => r.data),
}

// ---- helpers (pure; unit-tested) ----

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger'

/** Statuses during which an AI workflow is (or should be) running. */
export const BUSY_STATUSES: ArticleStatus[] = ['RESEARCHING', 'WRITING', 'FACT_CHECKING', 'REVISING', 'QUALITY_REVIEW']

export function statusTone(s: ArticleStatus): Tone {
  if (s === 'APPROVED') return 'success'
  if (s === 'FAILED') return 'danger'
  if (s === 'CANCELLED') return 'neutral'
  if (s === 'NEEDS_HUMAN_REVIEW' || s === 'BRIEF_READY' || s === 'OUTLINING') return 'warning'
  return 'brand'
}

export function verdictTone(v: ClaimStatus | null | undefined): Tone {
  switch (v) {
    case 'VERIFIED': return 'success'
    case 'CONTRADICTED': return 'danger'
    case 'OUTDATED':
    case 'NEEDS_HUMAN_REVIEW': return 'warning'
    default: return 'neutral'
  }
}

export function riskTone(r: RiskLevel): Tone {
  return ({ CRITICAL: 'danger', HIGH: 'warning', MEDIUM: 'brand', LOW: 'neutral' } as const)[r]
}

export function isActiveWorkflow(w: Pick<Workflow, 'status'> | null | undefined): boolean {
  return w?.status === 'pending' || w?.status === 'running' || w?.status === 'retry_scheduled'
}

/** Human (manual) verdicts that settle a claim need evidence or a reference. */
export function verdictNeedsEvidence(v: Verdict): boolean {
  return v === 'VERIFIED' || v === 'CONTRADICTED'
}

/** A claim counts as unresolved until it is VERIFIED on the current version. */
export function isUnresolved(c: Pick<Claim, 'status' | 'is_stale'>): boolean {
  return c.status !== 'VERIFIED' || c.is_stale
}

/**
 * The section (key) the given heading text belongs to: the nearest h2
 * above the selection, matched against the version's section list.
 */
export function sectionKeyFor(heading: string | null, sections: Version['sections']): string | null {
  const norm = (s: string) => s.replace(/\s+/g, ' ').trim().toLowerCase()
  if (heading === null || norm(heading) === '') return sections.find((s) => s.key === 'intro')?.key ?? sections[0]?.key ?? null
  return sections.find((s) => norm(s.heading) === norm(heading))?.key ?? null
}

/** Which article tab the next human action lives on. */
/**
 * Fact-check is a warning (v1.0.11): unresolved CRITICAL/HIGH claims need the
 * approver's acknowledgement, whether the server still lists them as
 * blocking (not yet acknowledged) or already as warnings (acknowledged).
 */
export function needsAcknowledgement(c: Pick<Checklist, 'blocking' | 'warnings'>): boolean {
  return c.blocking.some((b) => b.requires_ack) || c.warnings.some((w) => w.code === 'critical_unresolved' || w.code === 'high_unresolved')
}

export function nextTab(a: Pick<ArticleDetail, 'status' | 'waiting_for'>): 'research' | 'brief' | 'outline' | 'editor' | 'fact-check' | 'overview' {
  if (a.waiting_for === 'brief_approval') return 'brief'
  if (a.waiting_for === 'outline_approval') return 'outline'
  if (a.waiting_for === 'human_review') return 'fact-check'
  if (a.status === 'APPROVED') return 'editor'
  return 'overview'
}

export function formatUsd(micro: number | null | undefined): string {
  if (micro === null || micro === undefined) return '—'
  return `$${(micro / 1_000_000).toFixed(micro < 10_000 ? 4 : 2)}`
}

export function usdToMicro(usd: number): number {
  return Math.round(usd * 1_000_000)
}

/** Comma / newline separated text → unique trimmed list. */
export function splitLines(text: string): string[] {
  const out: string[] = []
  for (const part of text.split(/[\n,]/)) {
    const v = part.trim()
    if (v && !out.includes(v)) out.push(v)
  }
  return out
}
