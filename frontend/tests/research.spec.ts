import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import { formatUsd, isActiveRun, monthGrid, runTone, scoreTone, splitList, type Campaign, type Keyword } from '@/shared/api/research'

describe('research helpers', () => {
  it('builds Monday-first month grids that cover the whole month', () => {
    const weeks = monthGrid(new Date('2026-11-15T00:00:00Z'))
    expect(weeks[0]?.[0]).toBe('2026-10-26') // Monday before Nov 1st (a Sunday)
    expect(weeks.every((w) => w.length === 7)).toBe(true)
    const days = weeks.flat()
    expect(days).toContain('2026-11-01')
    expect(days).toContain('2026-11-30')
    expect(days[days.length - 1]).toBe('2026-12-06')
  })

  it('splits comma/newline lists into unique trimmed values', () => {
    expect(splitList('tai nghe, bàn phím\nchuột ,tai nghe,, ')).toEqual(['tai nghe', 'bàn phím', 'chuột'])
  })

  it('never invents a score tone or cost when data is missing', () => {
    expect(scoreTone(null)).toBe('neutral')
    expect(scoreTone(80)).toBe('success')
    expect(scoreTone(50)).toBe('warning')
    expect(formatUsd(null)).toBe('—')
    expect(formatUsd(1_250_000)).toBe('$1.25')
  })

  it('knows which runs are still active', () => {
    expect(isActiveRun({ status: 'queued' })).toBe(true)
    expect(isActiveRun({ status: 'partial' })).toBe(false)
    expect(isActiveRun(null)).toBe(false)
    expect(runTone('failed')).toBe('danger')
  })
})

// ---- UI: metrics without a verified source must read "Chưa có dữ liệu" ----

const keyword = (over: Partial<Keyword>): Keyword => ({
  id: 'k1', keyword: 'tai nghe gaming', intent: 'commercial', intent_source: 'rule', content_type: 'commercial', topic_category: null, cluster_id: null,
  country: 'VN', language: 'vi', sources: ['wordpress'], primary_source: 'wordpress', evidence: [], status: 'suggested',
  metrics: { search_volume: null, difficulty: null, cpc_micro_usd: null, provider: null, search_console: null },
  opportunity: { score: 62, kind: 'internal_estimate', version: 'v1', components: [], confidence: 0.8, penalty: 0, coverage: 'none' },
  covered_by: [], first_seen_at: null, data_collected_at: null, ...over,
})

vi.mock('@/shared/api/research', async (orig) => {
  const mod = await orig<typeof import('@/shared/api/research')>()
  return {
    ...mod,
    researchApi: {
      ...mod.researchApi,
      keywords: vi.fn(async () => ({
        data: [
          keyword({}),
          keyword({ id: 'k2', keyword: 'bàn phím cơ', sources: ['dataforseo'], metrics: { search_volume: 1900, difficulty: 34, cpc_micro_usd: null, provider: null, search_console: null } }),
        ],
        meta: { next_cursor: null, prev_cursor: null, per_page: 50 },
      })),
      clusters: vi.fn(async () => []),
    },
  }
})

describe('KeywordsTab', () => {
  it('shows "Chưa có dữ liệu" instead of a number when no provider returned metrics', async () => {
    const { default: KeywordsTab } = await import('@/apps/customer/pages/research/tabs/KeywordsTab.vue')
    const campaign = { id: 'c1' } as Campaign
    const w = mount(KeywordsTab, { props: { workspaceId: 'w1', campaign, canOperate: false }, global: { plugins: [i18n] } })
    await flushPromises()
    const rows = w.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Chưa có dữ liệu')
    expect(rows[1]!.text()).toContain('1.900')
    expect(rows[1]!.text()).not.toContain('Chưa có dữ liệu')
  })
})
