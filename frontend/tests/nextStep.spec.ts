import { describe, expect, it, vi, beforeEach } from 'vitest'
import { computed, defineComponent, h, shallowRef } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import { needsAcknowledgement, type ArticleDetail, type Checklist } from '@/shared/api/writer'
import type { PublishingJob } from '@/shared/api/publishing'
import { provideArticle } from '@/apps/customer/pages/writer/articleContext'

const writer = vi.hoisted(() => ({ checklist: vi.fn(), approve: vi.fn() }))
const pub = vi.hoisted(() => ({ jobs: vi.fn(), create: vi.fn(), approve: vi.fn(), recheck: vi.fn() }))
const seo = vi.hoisted(() => ({ audits: vi.fn(), runAudit: vi.fn() }))
vi.mock('@/shared/api/writer', async (orig) => {
  const mod = await orig<typeof import('@/shared/api/writer')>()
  return { ...mod, writerApi: { ...mod.writerApi, ...writer } }
})
vi.mock('@/shared/api/publishing', async (orig) => {
  const mod = await orig<typeof import('@/shared/api/publishing')>()
  return { ...mod, publishingApi: { ...mod.publishingApi, ...pub } }
})
vi.mock('@/shared/api/seo', async (orig) => {
  const mod = await orig<typeof import('@/shared/api/seo')>()
  return { ...mod, seoApi: { ...mod.seoApi, ...seo } }
})

const article = (over: Partial<ArticleDetail> = {}): ArticleDetail => ({
  id: 'a1', title: 'Giấy phép Windows Retail', status: 'NEEDS_HUMAN_REVIEW', next_statuses: [], mode: 'manual', review_mode: 'final_only',
  site_id: 's1', campaign_id: null, content_idea_id: null, writing_preset_id: null, writing_batch_id: null, primary_keyword: 'windows retail',
  secondary_keywords: [], search_intent: 'informational', content_type: 'blog_article', language: 'vi', country: 'VN', settings: {},
  budget: { limit_micro_usd: 2_000_000, spent_micro_usd: 1_210_000, remaining_micro_usd: 790_000 }, current_version_id: 'v3', approved_version_id: null,
  revision_rounds: 0, unresolved_critical: 4, unresolved_high: 20, quality_score: 80, duplicate_check: null, duplicate_decision: null, blocked_reason: null,
  last_error: null, status_before_failure: null, lock_version: 3, has_autosave: false, autosaved_at: null, is_demo: false, approved_by: null, approved_at: null,
  created_by: 'u1', created_at: '2026-11-07T00:00:00Z', updated_at: '2026-11-07T00:00:00Z', workflow: null, brief: null, outline: null, waiting_for: 'human_review', ...over,
})
const checklist = (over: Partial<Checklist> = {}): Checklist => ({
  ok: false, blocking: [{ code: 'critical_unresolved', message: 'Còn 4 nhận định rất quan trọng chưa có nguồn xác minh.', requires_ack: true }], warnings: [],
  stats: { unresolved_critical: 4, unresolved_high: 20, quality_score: 80, min_quality: 50 }, can_approve: true, approvals: [], ...over,
})
const job = (over: Partial<PublishingJob> = {}) => ({
  id: 'j1', site_id: 's1', article_id: 'a1', status: 'PUBLISHED', mode: 'wp_draft', wp_target_status: 'draft', url: 'https://example.com/?p=12',
  blocked_reason: null, gate_results: [], last_error_message: null, scheduled_at: null, ...over,
}) as PublishingJob
const page = <T>(data: T[]) => ({ data, meta: { next_cursor: null, prev_cursor: null, per_page: 50 } })

function host(component: object, a: ArticleDetail, opts: { canApprove?: boolean } = {}) {
  return defineComponent({
    setup() {
      provideArticle({
        workspaceId: 'w1', articleId: a.id, article: shallowRef(a), reload: async () => undefined,
        busy: computed(() => false), canEdit: computed(() => true), canApprove: computed(() => opts.canApprove ?? true),
      })
      return () => h(component as never)
    },
  })
}
const global = { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' }, Teleport: true } }

describe('needsAcknowledgement', () => {
  it('asks for an acknowledgement before and after it is given', () => {
    expect(needsAcknowledgement({ blocking: [{ code: 'critical_unresolved', message: '', requires_ack: true }], warnings: [] })).toBe(true)
    expect(needsAcknowledgement({ blocking: [], warnings: [{ code: 'critical_unresolved', message: '' }] })).toBe(true)
    expect(needsAcknowledgement({ blocking: [], warnings: [{ code: 'unsupported_claims', message: '' }] })).toBe(false)
  })
})

describe('NextStepBar', () => {
  beforeEach(() => vi.clearAllMocks())

  it('treats unverified claims as a warning: approve after ticking the acknowledgement', async () => {
    writer.checklist.mockImplementation((_w: string, _a: string, ack: boolean) => Promise.resolve(ack
      ? checklist({ ok: true, blocking: [], warnings: [{ code: 'critical_unresolved', message: 'x' }] })
      : checklist()))
    writer.approve.mockResolvedValue({})
    const { default: NextStepBar } = await import('@/apps/customer/pages/writer/components/NextStepBar.vue')
    const w = mount(host(NextStepBar, article()), { global })
    await flushPromises()
    expect(w.get('[data-testid="next-claims-warning"]').text()).toContain('24')
    expect(w.get('[data-testid="next-approve"]').attributes('disabled')).toBeDefined()
    await w.get('[data-testid="next-ack"]').setValue(true)
    await flushPromises()
    expect(w.find('[data-testid="next-ack"]').exists()).toBe(true) // stays visible once acknowledged
    expect(w.get('[data-testid="next-approve"]').attributes('disabled')).toBeUndefined()
    await w.get('[data-testid="next-approve"]').trigger('click')
    await flushPromises()
    expect(writer.approve).toHaveBeenCalledWith('w1', 'a1', { version_id: 'v3', acknowledge_high_risk: true, note: null })
  })

  it('shows real blockers (not fact-check) and sends the user to the details', async () => {
    writer.checklist.mockResolvedValue(checklist({ blocking: [{ code: 'quality_not_audited', message: 'Chưa chạy đánh giá chất lượng cho phiên bản hiện tại.' }] }))
    const { default: NextStepBar } = await import('@/apps/customer/pages/writer/components/NextStepBar.vue')
    const w = mount(host(NextStepBar, article()), { global })
    await flushPromises()
    expect(w.get('[data-testid="next-blocking"]').text()).toContain('đánh giá chất lượng')
    expect(w.find('[data-testid="next-approve"]').exists()).toBe(false)
    await w.get('[data-testid="next-details"]').trigger('click')
    expect(w.findComponent(NextStepBar).emitted('goto')?.[0]).toEqual(['fact-check'])
  })

  it('offers "Đăng lên WordPress" on an approved article and creates an approved WordPress draft by default', async () => {
    pub.jobs.mockResolvedValue(page([]))
    pub.create.mockResolvedValue(job({ status: 'APPROVED' }))
    const { default: NextStepBar } = await import('@/apps/customer/pages/writer/components/NextStepBar.vue')
    const w = mount(host(NextStepBar, article({ status: 'APPROVED', approved_version_id: 'v3', waiting_for: null })), { global })
    await flushPromises()
    await w.get('[data-testid="next-publish"]').trigger('click')
    await w.get('[data-testid="next-submit"]').trigger('click')
    await flushPromises()
    expect(pub.create).toHaveBeenCalledWith('w1', { article_id: 'a1', mode: 'wp_draft', scheduled_at: null, approve: true }, expect.any(String))
    expect(w.text()).toContain('Đang gửi bài lên WordPress')
  })

  it('links to the post once published and shows why a job is stuck', async () => {
    pub.jobs.mockResolvedValue(page([job()]))
    const { default: NextStepBar } = await import('@/apps/customer/pages/writer/components/NextStepBar.vue')
    const ok = mount(host(NextStepBar, article({ status: 'APPROVED', waiting_for: null })), { global })
    await flushPromises()
    expect(ok.get('[data-testid="next-view"]').attributes('href')).toBe('https://example.com/?p=12')

    pub.jobs.mockResolvedValue(page([job({ status: 'DRAFT', url: null, blocked_reason: 'site_disconnected', gate_results: [{ gate: 'site', ok: false, code: 'site_disconnected', message: 'Website chưa kết nối.', details: {} }] })]))
    const bad = mount(host(NextStepBar, article({ status: 'APPROVED', waiting_for: null })), { global })
    await flushPromises()
    expect(bad.get('[data-testid="next-fail"]').text()).toBe('Website chưa kết nối.')
  })

  it('hides the publish button from members without the publish permission', async () => {
    pub.jobs.mockResolvedValue(page([]))
    const { default: NextStepBar } = await import('@/apps/customer/pages/writer/components/NextStepBar.vue')
    const w = mount(host(NextStepBar, article({ status: 'APPROVED', waiting_for: null }), { canApprove: false }), { global })
    await flushPromises()
    expect(w.find('[data-testid="next-publish"]').exists()).toBe(false)
  })
})

describe('SeoScoreCard', () => {
  beforeEach(() => vi.clearAllMocks())
  const run = (over: Record<string, unknown> = {}) => ({ id: 'r1', article_id: 'a1', site_id: 's1', article_version_id: 'v3', rules_version: '1', status: 'completed', score: 86, pass_count: 20, warning_count: 2, error_count: 1, blocked_count: 0, links_checked: false, summary: null, is_demo: false, created_at: null, disclaimer: '', ...over })

  it('shows the latest score without re-running it', async () => {
    seo.audits.mockResolvedValue([run()])
    const { default: SeoScoreCard } = await import('@/apps/customer/pages/writer/components/SeoScoreCard.vue')
    const w = mount(host(SeoScoreCard, article()), { global })
    await flushPromises()
    expect(w.get('[data-testid="seo-score"]').text()).toContain('86')
    expect(seo.runAudit).not.toHaveBeenCalled()
  })

  it('scores the current version by itself when it has no audit yet', async () => {
    seo.audits.mockResolvedValue([run({ article_version_id: 'v2', score: 40 })])
    seo.runAudit.mockResolvedValue(run({ score: 72 }))
    const { default: SeoScoreCard } = await import('@/apps/customer/pages/writer/components/SeoScoreCard.vue')
    const w = mount(host(SeoScoreCard, article()), { global })
    await flushPromises()
    expect(seo.runAudit).toHaveBeenCalledTimes(1)
    expect(w.text()).toContain('72')
  })
})
