import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import viMessages from '@/locales/vi'
import { request } from '@/shared/api/client'
import { formatBytes, mediaStatusTone, placementAllowsAsset, placementReady, type MediaAsset, type Placement } from '@/shared/api/media'
import { jobTone, localInputToIso, newIdempotencyKey, resolutionAvailable } from '@/shared/api/publishing'
import { lengthTone, severityTone, sortIssues } from '@/shared/api/seo'
import { isValidScope } from '@/shared/api/phase6Admin'
import SimulationFrame from '@/apps/customer/pages/phase6/SimulationFrame.vue'
import { SECTION_NAV, sectionLocaleNs, type Phase6Section } from '@/apps/customer/pages/phase6/sectionNav'

afterEach(() => vi.restoreAllMocks())

const asset = (over: Partial<MediaAsset> = {}): MediaAsset => ({
  id: 'a', site_id: 's', source_type: 'upload', status: 'ready', mime: 'image/webp', width: 10, height: 10, bytes: 10, license: 'owned', attribution: null,
  is_ai_generated: false, title: 't', alt_text: 'alt', caption: null, wp_media_id: null, source_url: null, preview_url: null, thumbnail_url: null, ...over,
})
const placement = (over: Partial<Placement> = {}): Placement => ({
  id: null, slot: 'section', section_key: 'x1', section_heading: 'H', position: 0, status: 'accepted', alt_text: null, caption: null, asset: asset(), ...over,
})

describe('media rules (UI mirror of the API)', () => {
  it('never offers an AI illustration or infographic as a product photo', () => {
    expect(placementAllowsAsset('product', asset({ is_ai_generated: true, source_type: 'ai', license: 'ai_generated' }))).toBe(false)
    expect(placementAllowsAsset('product', asset({ source_type: 'infographic' }))).toBe(false)
    expect(placementAllowsAsset('product', asset({ source_type: 'woocommerce', license: 'wordpress_site' }))).toBe(true)
    expect(placementAllowsAsset('section', asset({ is_ai_generated: true, source_type: 'ai', license: 'ai_generated' }))).toBe(true)
  })

  it('rejects assets that are not ready or have no known license', () => {
    expect(placementAllowsAsset('section', asset({ status: 'processing' }))).toBe(false)
    expect(placementAllowsAsset('section', asset({ license: 'unknown' }))).toBe(false)
  })

  it('needs an image and alt text for an accepted placement', () => {
    expect(placementReady(placement())).toBe(true)
    expect(placementReady(placement({ asset: asset({ alt_text: '' }) }))).toBe(false)
    expect(placementReady(placement({ asset: null }))).toBe(false)
    expect(placementReady(placement({ status: 'rejected', asset: null }))).toBe(true)
  })

  it('formats sizes and statuses', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
    expect(formatBytes(null)).toBe('—')
    expect(mediaStatusTone('rejected')).toBe('danger')
  })
})

describe('SEO helpers', () => {
  it('orders issues so the ones that stop publishing come first', () => {
    const sorted = sortIssues([
      { severity: 'PASS', weight: 5 }, { severity: 'WARNING', weight: 3 }, { severity: 'BLOCKED', weight: 1 }, { severity: 'ERROR', weight: 9 },
    ])
    expect(sorted.map((i) => i.severity)).toEqual(['BLOCKED', 'ERROR', 'WARNING', 'PASS'])
  })

  it('maps severities and lengths to tones', () => {
    expect(severityTone('BLOCKED')).toBe('danger')
    expect(severityTone('PASS')).toBe('success')
    expect(lengthTone(0, 60)).toBe('danger')
    expect(lengthTone(70, 60)).toBe('warning')
    expect(lengthTone(50, 60)).toBe('success')
  })
})

describe('publishing helpers', () => {
  it('never offers overwrite when the post is gone or the domain changed', () => {
    expect(resolutionAvailable('overwrite', ['post_missing'])).toBe(false)
    expect(resolutionAvailable('overwrite', ['domain_changed'])).toBe(false)
    expect(resolutionAvailable('overwrite', ['manual_edit'])).toBe(true)
    expect(resolutionAvailable('new_draft', ['post_missing'])).toBe(true)
  })

  it('builds idempotency keys the API accepts', () => {
    const k = newIdempotencyKey('job')
    expect(k).toMatch(/^[A-Za-z0-9_\-:.]{8,120}$/)
    expect(newIdempotencyKey('job')).not.toBe(k)
  })

  it('converts datetime-local values to UTC ISO', () => {
    expect(localInputToIso('')).toBeNull()
    expect(localInputToIso('2026-11-05T10:00')).toMatch(/^2026-11-0[45]T\d{2}:00:00\.000Z$/)
  })

  it('maps statuses to tones and validates admin scopes', () => {
    expect(jobTone('PUBLISHED')).toBe('success')
    expect(jobTone('UPDATE_PENDING')).toBe('warning')
    expect(isValidScope('global')).toBe(true)
    expect(isValidScope('ws:01HZZZZZZZZZZZZZZZZZZZZZZZ')).toBe(true)
    expect(isValidScope('ws:abc')).toBe(false)
  })
})

describe('API client multipart', () => {
  it('lets the browser set the multipart boundary for uploads', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: {} }), { status: 202, headers: { 'Content-Type': 'application/json' } }))
    document.cookie = 'XSRF-TOKEN=x'
    const form = new FormData()
    form.append('file', new Blob(['x'], { type: 'image/png' }), 'a.png')
    await request('POST', '/api/v1/workspaces/w/media/assets', form)
    const init = fetchMock.mock.calls[0]![1] as RequestInit
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect(init.body).toBe(form)
  })
})

describe('preview is a sandboxed simulation', () => {
  it('renders sanitized HTML in an iframe without scripts or same-origin', () => {
    const w = mount(SimulationFrame, { props: { html: '<h1>Tiêu đề</h1><script>alert(1)</script><img src=x onerror="alert(1)">', title: 'p' } })
    const frame = w.get('iframe')
    expect(frame.attributes('sandbox')).toBe('')
    const doc = frame.attributes('srcdoc') ?? ''
    expect(doc).toContain('<h1>Tiêu đề</h1>')
    expect(doc).not.toContain('<script>')
    expect(doc).not.toContain('onerror')
  })
})

describe('Phase 6 i18n', () => {
  const sources = import.meta.glob(['/src/apps/customer/pages/phase6/*.{vue,ts}', '/src/apps/admin/pages/{Media,Publishing}AdminPage.vue'], { query: '?raw', import: 'default', eager: true }) as Record<string, string>
  function has(obj: unknown, path: string): boolean {
    return path.split('.').reduce<unknown>((o, k) => (o && typeof o === 'object' ? (o as Record<string, unknown>)[k] : undefined), obj) !== undefined
  }

  it('defines every static p6.* key used by the screens', () => {
    const missing: string[] = []
    expect(Object.keys(sources).length).toBeGreaterThan(12)
    for (const [f, src] of Object.entries(sources)) {
      for (const m of src.matchAll(/t\('(p6\.[A-Za-z0-9_.]+)'/g)) {
        if (!has(viMessages, m[1]!)) missing.push(`${f}: ${m[1]}`)
      }
    }
    expect(missing).toEqual([])
  })

  it('labels every publishing status, gate and conflict type', () => {
    for (const s of ['DRAFT', 'READY_FOR_REVIEW', 'APPROVED', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'UPDATE_PENDING', 'FAILED', 'CANCELLED']) expect(has(viMessages, `p6.pub.status.${s}`)).toBe(true)
    for (const g of ['site', 'article_approved', 'fact_check', 'seo', 'media', 'policy', 'quota', 'conflict']) expect(has(viMessages, `p6.pub.gates.${g}`)).toBe(true)
    for (const c of ['post_missing', 'domain_changed', 'manual_edit', 'slug_changed', 'seo_modified', 'revision_changed']) {
      expect(has(viMessages, `p6.pub.conflictType.${c}`)).toBe(true)
      expect(has(viMessages, `p6.pub.conflictHint.${c}`)).toBe(true)
    }
  })
  it('labels every tab of the media / SEO / publishing navigation', () => {
    const missing: string[] = []
    for (const section of Object.keys(SECTION_NAV) as Phase6Section[]) {
      const ns = sectionLocaleNs(section)
      if (!has(viMessages, `p6.${ns}.title`)) missing.push(`p6.${ns}.title`)
      for (const i of SECTION_NAV[section]) if (!has(viMessages, `p6.${ns}.nav.${i.key}`)) missing.push(`p6.${ns}.nav.${i.key}`)
    }
    expect(missing).toEqual([])
  })

  it('labels every value the API can return for dynamic keys', () => {
    const sets: Record<string, string[]> = {
      'p6.pub.mode': ['wp_draft', 'publish_now', 'scheduled', 'auto', 'update'],
      'p6.pub.resolution': ['keep_wordpress', 'overwrite', 'merge_manually', 'new_draft', 'cancel_update'],
      'p6.pub.resolutionHint': ['keep_wordpress', 'overwrite', 'merge_manually', 'new_draft', 'cancel_update'],
      'p6.pub.syncState': ['in_sync', 'manual_edit', 'slug_changed', 'seo_modified', 'domain_changed', 'missing', 'unreachable'],
      'p6.pub.batchStatus': ['running', 'paused', 'completed', 'canceled'],
      'p6.media.status': ['processing', 'ready', 'failed', 'rejected'],
      'p6.media.source': ['wordpress', 'woocommerce', 'upload', 'ai', 'stock', 'infographic'],
      'p6.media.license': ['owned', 'customer_provided', 'wordpress_site', 'ai_generated', 'licensed_stock', 'unknown'],
      'p6.media.slot': ['featured', 'intro', 'section', 'comparison', 'infographic', 'product'],
      'p6.media.jobStatus': ['queued', 'running', 'succeeded', 'failed', 'blocked', 'canceled'],
      'p6.media.placementStatus': ['suggested', 'accepted', 'rejected'],
      'p6.media.sourceStatus': ['idle', 'syncing', 'failed', 'unavailable'],
      'p6.seo.severity': ['PASS', 'WARNING', 'ERROR', 'BLOCKED'],
      'p6.seo.category': ['structure', 'metadata', 'content', 'links', 'media', 'schema', 'indexing', 'wordpress'],
      'p6.seo.links.status': ['suggested', 'accepted', 'rejected'],
    }
    const missing: string[] = []
    for (const [prefix, values] of Object.entries(sets)) for (const v of values) if (!has(viMessages, `${prefix}.${v}`)) missing.push(`${prefix}.${v}`)
    expect(missing).toEqual([])
  })
})
