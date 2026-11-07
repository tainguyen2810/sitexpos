import { api } from './client'
import type { Envelope, Paginated } from './types'

/**
 * Phase 6 — Media engine. Uploads and AI images are processed on the queue
 * (202 + job_run). AI images are illustrations: they are always labelled and
 * can never be used as a real product photo.
 */
export type MediaSourceType = 'upload' | 'wordpress' | 'woocommerce' | 'ai' | 'infographic' | 'stock'
export type MediaStatus = 'pending' | 'processing' | 'ready' | 'rejected' | 'failed'
export type MediaLicense = 'owned' | 'customer_provided' | 'licensed_stock' | 'ai_generated' | 'wordpress_library' | string
export const IMAGE_TYPES = ['featured', 'section', 'concept', 'infographic_background'] as const
export const ASPECT_RATIOS = ['16:9', '3:2', '4:3', '1:1', '2:3', '9:16'] as const
export const IMAGE_STYLES = ['flat_illustration', 'isometric', 'line_art', 'watercolor', '3d_render', 'editorial_concept'] as const
export const PLACEMENT_SLOTS = ['featured', 'intro', 'section', 'comparison', 'infographic', 'product'] as const
export const INFOGRAPHIC_TYPES = ['comparison', 'steps', 'features', 'timeline', 'bar_chart'] as const
export type PlacementSlot = (typeof PLACEMENT_SLOTS)[number]

export interface MediaAsset {
  id: string
  site_id: string | null
  source_type: MediaSourceType
  status: MediaStatus
  mime: string | null
  width: number | null
  height: number | null
  bytes: number | null
  license: MediaLicense
  attribution: string | null
  is_ai_generated: boolean
  title: string | null
  alt_text: string | null
  caption: string | null
  wp_media_id: number | null
  source_url: string | null
  preview_url: string | null
  thumbnail_url: string | null
  original_filename?: string | null
  error_code?: string | null
  usage_count?: number
  ai_provider?: string | null
  ai_model?: string | null
  ai_prompt?: string | null
  product?: { wp_post_id?: number; title?: string } | null
  infographic?: { type?: string; sources?: { label: string; url?: string | null }[]; customer_provided?: boolean } | null
  near_duplicates?: string[]
  duplicate_of_id?: string | null
  is_demo?: boolean
  versions?: { kind: string; mime: string; width: number | null; height: number | null; bytes: number | null; url: string }[]
  placements?: { id: string; article_id: string; slot: string; status: string }[]
  created_at?: string | null
}

export interface MediaSourceRow {
  site_id: string
  type: MediaSourceType
  available: boolean
  status: string
  items_count: number
  last_synced_at: string | null
  last_error_code: string | null
}

export interface MediaLimitsView {
  ai_images_enabled: boolean
  max_storage_bytes: number
  max_uploads_per_day: number
  max_ai_images_per_day: number
  max_ai_cost_per_job_micro_usd: number
  [k: string]: unknown
}

export interface MediaUsage {
  stored_bytes: number
  assets: number
  by_source: Record<string, number>
  ai_images_today: number
  limits: MediaLimitsView
}

export interface ImageJob {
  id: string
  site_id: string
  article_id: string | null
  parent_job_id: string | null
  version: number
  image_type: string
  section_key: string | null
  aspect_ratio: string
  style: string
  quality: string
  image_count: number
  brand_colors: string[]
  prompt: string
  prompt_edited: boolean
  status: 'queued' | 'running' | 'succeeded' | 'failed' | 'canceled' | string
  budget_cap_micro_usd: number | null
  estimated_cost_micro_usd: number | null
  cost_micro_usd: number | null
  provider: string | null
  model: string | null
  error_code: string | null
  error_message: string | null
  assets: MediaAsset[]
  created_at: string | null
  finished_at: string | null
}

export interface GeneratePayload {
  site_id?: string
  article_id?: string
  section_key?: string
  image_type: string
  aspect_ratio?: string
  style?: string
  quality?: 'standard' | 'high'
  image_count?: number
  brand_colors?: string[]
  context?: string
  title?: string
  prompt?: string
  budget_cap_micro_usd?: number
}

export interface GeneratePreview {
  prompt: string
  estimate_micro_usd: number | null
  models: { model_key: string; provider: string | null; image_generation: boolean; [k: string]: unknown }[]
  limits: MediaLimitsView
  [k: string]: unknown
}

export interface Placement {
  id: string | null
  slot: PlacementSlot
  section_key: string | null
  section_heading: string | null
  position: number
  status: 'suggested' | 'accepted' | 'rejected'
  alt_text: string | null
  caption: string | null
  asset: MediaAsset | null
  delete?: boolean
}

export interface PlacementView {
  article: { id: string; title: string; site_id: string; status: string; primary_keyword: string | null }
  sections: { key: string; heading: string; words: number }[]
  placements: Placement[]
  autofill?: Autofill | null
}

/** Auto images after a draft: library first, AI for the rest. */
export interface Autofill {
  status: 'running' | 'completed' | 'incomplete' | 'failed'
  target: number
  filled: number
  library_count: number
  ai_count: number
  pending: number
  error: { code: string; message: string | null } | null
  updated_at: string | null
}

export interface JobRunRef { id: string; status: string }

const W = (ws: string) => `/api/v1/workspaces/${encodeURIComponent(ws)}/media`
const id = (v: string) => encodeURIComponent(v)

export const mediaApi = {
  list: (ws: string, query: { cursor?: string | null; site_id?: string; source_type?: string; status?: string; license?: string; ai?: boolean; q?: string }) =>
    api.get<Paginated<MediaAsset>>(`${W(ws)}/assets`, { query: { ...query, ai: query.ai ? 1 : undefined } }),
  get: (ws: string, asset: string) => api.get<Envelope<MediaAsset>>(`${W(ws)}/assets/${id(asset)}`).then((r) => r.data),
  upload: (ws: string, file: File, meta: { site_id?: string; title?: string; alt_text?: string; caption?: string; license: string; attribution?: string }) => {
    const form = new FormData()
    form.append('file', file)
    for (const [k, v] of Object.entries(meta)) if (v !== undefined && v !== '') form.append(k, v)
    return api.post<Envelope<MediaAsset> & { job_run: JobRunRef }>(`${W(ws)}/assets`, form)
  },
  update: (ws: string, asset: string, data: Partial<Pick<MediaAsset, 'title' | 'alt_text' | 'caption' | 'attribution' | 'license'>>) =>
    api.patch<Envelope<MediaAsset>>(`${W(ws)}/assets/${id(asset)}`, data).then((r) => r.data),
  remove: (ws: string, asset: string) => api.delete<void>(`${W(ws)}/assets/${id(asset)}`),
  derive: (ws: string, asset: string, data: { operation: 'crop' | 'resize'; ratio?: string; max_width?: number }) =>
    api.post<Envelope<MediaAsset>>(`${W(ws)}/assets/${id(asset)}/derive`, data).then((r) => r.data),
  sources: (ws: string, siteId?: string) => api.get<Envelope<MediaSourceRow[]>>(`${W(ws)}/sources`, { query: { site_id: siteId } }).then((r) => r.data),
  sync: (ws: string, siteId: string) => api.post<{ job_run: JobRunRef }>(`${W(ws)}/sources/sync`, { site_id: siteId }).then((r) => r.job_run),
  usage: (ws: string) => api.get<Envelope<MediaUsage>>(`${W(ws)}/usage`).then((r) => r.data),
  infographic: (ws: string, data: Record<string, unknown>) => api.post<Envelope<MediaAsset>>(`${W(ws)}/infographics`, data).then((r) => r.data),

  previewGeneration: (ws: string, data: GeneratePayload) => api.post<Envelope<GeneratePreview>>(`${W(ws)}/generations/preview`, data).then((r) => r.data),
  generate: (ws: string, data: GeneratePayload, idempotencyKey?: string) =>
    api.post<Envelope<ImageJob> & { job_run: JobRunRef | null }>(`${W(ws)}/generations`, data, idempotencyKey ? { headers: { 'Idempotency-Key': idempotencyKey } } : {}),
  generations: (ws: string, query: { cursor?: string | null; status?: string; article_id?: string }) => api.get<Paginated<ImageJob>>(`${W(ws)}/generations`, { query }),
  generation: (ws: string, job: string) => api.get<Envelope<ImageJob>>(`${W(ws)}/generations/${id(job)}`).then((r) => r.data),
  regenerate: (ws: string, job: string, prompt?: string) => api.post<Envelope<ImageJob>>(`${W(ws)}/generations/${id(job)}/regenerate`, prompt ? { prompt } : {}).then((r) => r.data),

  placements: (ws: string, article: string) => api.get<Envelope<PlacementView>>(`${W(ws)}/articles/${id(article)}/placements`).then((r) => r.data),
  suggestPlacements: (ws: string, article: string) =>
    api.post<Envelope<{ suggested: number; placements: Placement[] }>>(`${W(ws)}/articles/${id(article)}/placements/suggest`).then((r) => r.data),
  savePlacements: (ws: string, article: string, items: Placement[]) =>
    api.put<Envelope<{ placements: Placement[] }>>(`${W(ws)}/articles/${id(article)}/placements`, {
      items: items.map((p) => ({
        id: p.id ?? undefined, delete: p.delete ?? undefined, slot: p.slot, section_key: p.section_key, section_heading: p.section_heading,
        position: p.position, media_asset_id: p.asset?.id ?? null, alt_text: p.alt_text, caption: p.caption, status: p.status,
      })),
    }).then((r) => r.data),
}

export function formatBytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  if (n < 1024 * 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`
  return `${(n / 1024 / 1024 / 1024).toFixed(2)} GB`
}

export function mediaStatusTone(s: string): 'success' | 'warning' | 'danger' | 'neutral' | 'brand' {
  return s === 'ready' ? 'success' : s === 'rejected' || s === 'failed' ? 'danger' : s === 'processing' || s === 'pending' ? 'brand' : 'neutral'
}

/** A placement may only use an AI illustration outside the product slot (UI mirror of the API rule). */
export function placementAllowsAsset(slot: PlacementSlot, asset: Pick<MediaAsset, 'is_ai_generated' | 'source_type' | 'license' | 'status'>): boolean {
  if (asset.status !== 'ready' || asset.license === 'unknown') return false
  if (slot === 'product') {
    return !asset.is_ai_generated && ['woocommerce', 'wordpress', 'upload'].includes(asset.source_type) && ['wordpress_site', 'owned', 'customer_provided'].includes(asset.license)
  }
  return true
}

/** An accepted placement needs alt text before it can be published. */
export function placementReady(p: Placement): boolean {
  if (p.status !== 'accepted') return true
  return !!p.asset && !!(p.alt_text ?? p.asset.alt_text)?.trim()
}

export function microUsd(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return `$${(v / 1_000_000).toFixed(v < 10_000 ? 4 : 2)}`
}
