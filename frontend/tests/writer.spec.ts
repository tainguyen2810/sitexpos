import { describe, expect, it, vi, beforeEach } from 'vitest'
import { computed, defineComponent, h, shallowRef } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import viMessages from '@/locales/vi'
import enMessages from '@/locales/en'
import {
  approvalModeOf, autoApproveResult,
  formatUsd, isUnresolved, nextTab, riskTone, sectionKeyFor, splitLines, statusTone, usdToMicro, verdictNeedsEvidence, verdictTone,
  type ArticleDetail, type Checklist, type Claim, type EditorState, type Preset,
} from '@/shared/api/writer'
import { isSafeHref, sanitizeArticleHtml, wordCount } from '@/shared/utils/articleHtml'
import { isBoolKey, isMoneyKey } from '@/shared/api/writerAdmin'
import { provideArticle } from '@/apps/customer/pages/writer/articleContext'

// ---------------------------------------------------------------- helpers

describe('writer helpers', () => {
  it('maps statuses, verdicts and risk to badge tones', () => {
    expect(statusTone('APPROVED')).toBe('success')
    expect(statusTone('FAILED')).toBe('danger')
    expect(statusTone('NEEDS_HUMAN_REVIEW')).toBe('warning')
    expect(statusTone('WRITING')).toBe('brand')
    expect(verdictTone('VERIFIED')).toBe('success')
    expect(verdictTone('CONTRADICTED')).toBe('danger')
    expect(verdictTone('INSUFFICIENT_EVIDENCE')).toBe('neutral') // "not found" is not "false"
    expect(riskTone('CRITICAL')).toBe('danger')
  })

  it('treats a claim as unresolved unless VERIFIED on the current content', () => {
    expect(isUnresolved({ status: 'VERIFIED', is_stale: false })).toBe(false)
    expect(isUnresolved({ status: 'VERIFIED', is_stale: true })).toBe(true)
    expect(isUnresolved({ status: 'INSUFFICIENT_EVIDENCE', is_stale: false })).toBe(true)
  })

  it('requires evidence only for verdicts that settle a claim', () => {
    expect(verdictNeedsEvidence('VERIFIED')).toBe(true)
    expect(verdictNeedsEvidence('CONTRADICTED')).toBe(true)
    expect(verdictNeedsEvidence('INSUFFICIENT_EVIDENCE')).toBe(false)
    expect(verdictNeedsEvidence('NEEDS_HUMAN_REVIEW')).toBe(false)
  })

  it('finds the section of a selection by its h2 heading', () => {
    const sections = [{ key: 'intro', heading: '', claims_checked: true }, { key: 's1', heading: 'Intel AX210 là gì?', claims_checked: false }]
    expect(sectionKeyFor('  Intel   AX210 là gì? ', sections)).toBe('s1')
    expect(sectionKeyFor(null, sections)).toBe('intro')
    expect(sectionKeyFor('Không tồn tại', sections)).toBeNull()
  })

  it('sends the user to the tab that waits for them', () => {
    expect(nextTab({ status: 'BRIEF_READY', waiting_for: 'brief_approval' })).toBe('brief')
    expect(nextTab({ status: 'OUTLINING', waiting_for: 'outline_approval' })).toBe('outline')
    expect(nextTab({ status: 'NEEDS_HUMAN_REVIEW', waiting_for: 'human_review' })).toBe('fact-check')
    expect(nextTab({ status: 'WRITING', waiting_for: null })).toBe('overview')
  })

  it('formats money and lists without inventing values', () => {
    expect(formatUsd(null)).toBe('—')
    expect(formatUsd(1_000_000)).toBe('$1.00')
    expect(usdToMicro(0.25)).toBe(250_000)
    expect(splitLines('a, b\nc,,a')).toEqual(['a', 'b', 'c'])
    expect(isMoneyKey('max_article_budget_micro_usd')).toBe(true)
    expect(isBoolKey('require_ack_for_high_risk')).toBe(true)
  })
})

// ---------------------------------------------------------------- XSS

describe('auto-approval helpers', () => {
  it('defaults to manual and only trusts a well-formed result', () => {
    expect(approvalModeOf({})).toBe('manual')
    expect(approvalModeOf({ approval_mode: 'yes' })).toBe('manual')
    expect(approvalModeOf({ approval_mode: 'auto' })).toBe('auto')
    expect(autoApproveResult({})).toBeNull()
    expect(autoApproveResult({ auto_approve_result: { approved: false } })).toBeNull()
    expect(autoApproveResult({ auto_approve_result: { approved: false, reasons: ['duplicate_risk'], at: 'x' } })?.reasons).toEqual(['duplicate_risk'])
  })

  it('has a label for every backend reason code', () => {
    const codes = ['manual_mode', 'disabled_by_policy', 'wrong_status', 'no_content', 'workflow_running', 'critical_unresolved', 'high_unresolved', 'sections_not_checked',
      'quality_not_audited', 'quality_below_policy', 'quality_below_auto_threshold', 'contradicted_or_outdated_claims', 'duplicate_risk', 'duplicate_reject']
    for (const m of [viMessages, enMessages]) {
      const reasons = (m as unknown as { writer: { autoApprove: { reason: Record<string, string> } } }).writer.autoApprove.reason
      for (const c of codes) expect(reasons[c], c).toBeTruthy()
    }
  })
})

describe('article HTML sanitizer', () => {
  it('drops scripts, handlers, styles, iframes and javascript: links', () => {
    const dirty = '<h2 onclick="x()" style="color:red">Tiêu đề</h2><script>alert(1)</script><img src=x onerror=alert(1)>'
      + '<p>Đoạn <a href="javascript:alert(1)">xấu</a> và <a href="https://intel.com/ax210">tốt</a></p><iframe src="https://evil.test"></iframe>'
    const clean = sanitizeArticleHtml(dirty)
    expect(clean).not.toMatch(/script|onerror|onclick|style=|iframe|<img|javascript:/i)
    expect(clean).toContain('<h2>Tiêu đề</h2>')
    expect(clean).toContain('<a href="https://intel.com/ax210">tốt</a>')
    expect(clean).toContain('xấu') // text is kept, the link is unwrapped
  })

  it('keeps citation links and allow-listed classes only', () => {
    const clean = sanitizeArticleHtml('<a data-evidence-id="01h" href="https://a.test">[1]</a><div class="callout evil">x</div><p data-foo="1">y</p>')
    expect(clean).toContain('data-evidence-id="01h"')
    expect(clean).toContain('class="callout"')
    expect(clean).not.toContain('evil')
    expect(clean).not.toContain('data-foo')
  })

  it('only accepts http(s), relative and anchor links', () => {
    expect(isSafeHref('https://a.test/x')).toBe(true)
    expect(isSafeHref('/blog/a')).toBe(true)
    expect(isSafeHref('#s1')).toBe(true)
    expect(isSafeHref('//evil.test')).toBe(false)
    expect(isSafeHref('javascript:alert(1)')).toBe(false)
    expect(isSafeHref('data:text/html,x')).toBe(false)
    expect(wordCount('<p>một hai ba</p><script>bốn</script>')).toBe(3)
  })
})

// ---------------------------------------------------------------- locales

const keys = (o: object, p = ''): string[] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' && v !== null ? keys(v, `${p}${k}.`) : [`${p}${k}`]))

describe('writer locales', () => {
  it('has the same writer keys in English and Vietnamese', () => {
    expect(keys(enMessages.writer).sort()).toEqual(keys(viMessages.writer).sort())
    expect(keys(enMessages.writerAdmin).sort()).toEqual(keys(viMessages.writerAdmin).sort())
  })

  it('defines every static writer key used by the writer screens', () => {
    const sources = {
      ...import.meta.glob('../src/apps/customer/pages/writer/**/*.vue', { query: '?raw', import: 'default', eager: true }),
      ...import.meta.glob('../src/apps/admin/pages/WriterAdminPage.vue', { query: '?raw', import: 'default', eager: true }),
    } as Record<string, string>
    expect(Object.keys(sources).length).toBeGreaterThan(15)
    const defined = new Set(keys({ writer: viMessages.writer, writerAdmin: viMessages.writerAdmin }))
    const missing: string[] = []
    for (const [f, src] of Object.entries(sources)) {
      for (const m of src.matchAll(/\bt\('((?:writer|writerAdmin)\.[A-Za-z0-9_.-]+)'/g)) {
        if (!defined.has(m[1]!)) missing.push(`${f.split('/').pop()}: ${m[1]}`)
      }
    }
    expect(missing).toEqual([])
  })
})

// ---------------------------------------------------------------- components

const article = (over: Partial<ArticleDetail> = {}): ArticleDetail => ({
  id: 'a1', title: 'So sánh Intel AX210 và AX200', status: 'NEEDS_HUMAN_REVIEW', next_statuses: [], mode: 'manual', review_mode: 'final_only',
  site_id: 's1', campaign_id: null, content_idea_id: null, writing_preset_id: null, writing_batch_id: null, primary_keyword: 'intel ax210',
  secondary_keywords: [], search_intent: 'informational', content_type: 'comparison', language: 'vi', country: 'VN', settings: {},
  budget: { limit_micro_usd: 1_000_000, spent_micro_usd: 120_000, remaining_micro_usd: 880_000 }, current_version_id: 'v1', approved_version_id: null,
  revision_rounds: 0, unresolved_critical: 1, unresolved_high: 0, quality_score: 72, duplicate_check: null, duplicate_decision: null, blocked_reason: null,
  last_error: null, status_before_failure: null, lock_version: 3, has_autosave: false, autosaved_at: null, is_demo: false, approved_by: null, approved_at: null,
  created_by: 'u1', created_at: '2026-11-01T00:00:00Z', updated_at: '2026-11-01T00:00:00Z', workflow: null, brief: null, outline: null, waiting_for: 'human_review', ...over,
})

function withArticle(component: object, a: ArticleDetail, opts: { canEdit?: boolean; canApprove?: boolean; busy?: boolean } = {}) {
  return defineComponent({
    setup() {
      const ref = shallowRef(a)
      provideArticle({
        workspaceId: 'w1', articleId: a.id, article: ref, reload: async () => undefined,
        busy: computed(() => opts.busy ?? false), canEdit: computed(() => opts.canEdit ?? true), canApprove: computed(() => opts.canApprove ?? false),
      })
      return () => h(component as never)
    },
  })
}

const claim = (over: Partial<Claim>): Claim => ({
  id: 'c1', version_id: 'v1', section_key: 's1', claim: 'Intel AX210 hỗ trợ băng tần 6 GHz', category: 'spec', risk_level: 'HIGH', evidence_requirements: null,
  origin: 'ai', extracted_by_model: 'fake/fake-echo', extracted_at: null, status: 'VERIFIED', is_stale: false, latest_result: null, ...over,
})
const checklist = (over: Partial<Checklist>): Checklist => ({
  ok: false, blocking: [{ code: 'critical_unresolved', message: 'Còn 1 khẳng định CRITICAL chưa được xác minh.' }], warnings: [],
  stats: { unresolved_critical: 1, unresolved_high: 0, quality_score: 72, min_quality: 50 }, can_approve: true, approvals: [], ...over,
})

const api = vi.hoisted(() => ({
  claims: vi.fn(), factChecks: vi.fn(), checklist: vi.fn(), claim: vi.fn(), evidence: vi.fn(), editor: vi.fn(), revisions: vi.fn(), autosave: vi.fn(), save: vi.fn(),
}))
vi.mock('@/shared/api/writer', async (orig) => {
  const mod = await orig<typeof import('@/shared/api/writer')>()
  return { ...mod, writerApi: { ...mod.writerApi, ...api } }
})
const page = <T>(data: T[]) => ({ data, meta: { next_cursor: null, prev_cursor: null, per_page: 50 } })

describe('FactCheckTab', () => {
  beforeEach(() => {
    api.claims.mockResolvedValue(page([claim({ id: 'c1', risk_level: 'CRITICAL', status: 'INSUFFICIENT_EVIDENCE', claim: 'Pin dùng 10 giờ' }), claim({ id: 'c2' })]))
    api.factChecks.mockResolvedValue(page([]))
    api.evidence.mockResolvedValue(page([]))
  })

  it('blocks approval while a CRITICAL claim is unresolved and says why', async () => {
    api.checklist.mockResolvedValue(checklist({}))
    const { default: FactCheckTab } = await import('@/apps/customer/pages/writer/tabs/FactCheckTab.vue')
    const w = mount(withArticle(FactCheckTab, article(), { canApprove: true }), { global: { plugins: [i18n], stubs: { RouterLink: true, Teleport: true } } })
    await flushPromises()
    expect(w.findAll('[data-testid="claim-list"] li')).toHaveLength(2)
    expect(w.get('[data-testid="blocking"]').text()).toContain('CRITICAL')
    expect(w.get('[data-testid="approve-article"]').attributes('disabled')).toBeDefined()
  })

  it('does not offer approval to an editor without the publish permission', async () => {
    api.checklist.mockResolvedValue(checklist({ ok: true, blocking: [], can_approve: false }))
    const { default: FactCheckTab } = await import('@/apps/customer/pages/writer/tabs/FactCheckTab.vue')
    const w = mount(withArticle(FactCheckTab, article({ unresolved_critical: 0 }), { canApprove: false }), { global: { plugins: [i18n], stubs: { RouterLink: true, Teleport: true } } })
    await flushPromises()
    expect(w.find('[data-testid="approve-article"]').exists()).toBe(false)
    expect(w.text()).toContain('Bạn không có quyền duyệt bài.')
  })
})

describe('EditorTab', () => {
  const editorState = (html: string): EditorState => ({
    version: { id: 'v1', number: 2, source: 'ai_draft', title: 't', word_count: 3, content_hash: 'h', parent_version_id: null, change_summary: null, model: null,
      created_by: null, created_at: null, sections: [{ key: 'intro', heading: '', claims_checked: true }], content_html: html },
    lock_version: 3, autosave: null, citations: [], rewrite_actions: ['rewrite'],
  })
  beforeEach(() => {
    api.revisions.mockResolvedValue(page([]))
  })

  it('never renders unsafe HTML coming from the server', async () => {
    api.editor.mockResolvedValue(editorState('<p>An toàn</p><img src=x onerror="window.__xss=1"><script>window.__xss=1</script>'))
    const { default: EditorTab } = await import('@/apps/customer/pages/writer/tabs/EditorTab.vue')
    const w = mount(withArticle(EditorTab, article()), { global: { plugins: [i18n], stubs: { RouterLink: true } } })
    await flushPromises()
    const html = w.get('[data-testid="rich-editor"]').element.innerHTML
    expect(html).toContain('An toàn')
    expect(html).not.toMatch(/onerror|<script|<img/)
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined()
  })

  it('locks the editor while an AI workflow runs so nothing is lost', async () => {
    api.editor.mockResolvedValue(editorState('<p>Nội dung</p>'))
    const { default: EditorTab } = await import('@/apps/customer/pages/writer/tabs/EditorTab.vue')
    const w = mount(withArticle(EditorTab, article(), { busy: true }), { global: { plugins: [i18n], stubs: { RouterLink: true } } })
    await flushPromises()
    expect(w.get('[data-testid="rich-editor"]').attributes('contenteditable')).toBe('false')
    expect(w.find('[data-testid="save-version"]').exists()).toBe(false)
    expect(w.text()).toContain('Đang có tác vụ AI chạy trên bài')
  })
})

describe('DiffView', () => {
  it('renders diff text as text, never as HTML', async () => {
    const { default: DiffView } = await import('@/apps/customer/pages/writer/components/DiffView.vue')
    const w = mount(DiffView, { props: { ops: [{ op: 'eq', text: 'Giữ ' }, { op: 'del', text: 'cũ' }, { op: 'ins', text: '<b onclick="x">mới</b>' }] } })
    expect(w.find('b').exists()).toBe(false)
    expect(w.get('ins').text()).toBe('<b onclick="x">mới</b>')
    expect(w.get('del').text()).toBe('cũ')
  })
})

// ---------------------------------------------------------------- v1.0.9 simple writer & styles

describe('simple writer helpers', () => {
  it('maps word counts to the three lengths and reading time', async () => {
    const { LENGTHS, lengthOf, readingMinutes } = await import('@/shared/api/writer')
    expect(lengthOf(LENGTHS.short)).toBe('short')
    expect(lengthOf(LENGTHS.medium)).toBe('medium')
    expect(lengthOf(LENGTHS.long)).toBe('long')
    expect(lengthOf(3000)).toBe('long')
    expect(readingMinutes(1500)).toBe(7)
    expect(readingMinutes(10)).toBe(1)
  })

  it('names every ready-made style and source in both languages', async () => {
    const { BUILTIN_STYLES, STYLE_ORIGINS } = await import('@/shared/api/writer')
    for (const m of [viMessages, enMessages]) {
      const w = m.writer as unknown as { builtin: Record<string, { name: string; desc: string; for: string }>; styles: { origin: Record<string, string>; originHelp: Record<string, string> } }
      for (const k of BUILTIN_STYLES) expect(w.builtin[k]?.name && w.builtin[k]?.desc && w.builtin[k]?.for).toBeTruthy()
      for (const o of STYLE_ORIGINS) expect(w.styles.origin[o] && w.styles.originHelp[o]).toBeTruthy()
    }
  })

  it('only counts a preset as a learned style when its guide has content', async () => {
    const { hasStyle } = await import('@/shared/api/writer')
    expect(hasStyle({ style_profile: null })).toBe(false)
    expect(hasStyle({ style_profile: { tone: ' ', dos: [] } })).toBe(false)
    expect(hasStyle({ style_profile: { tone: 'Vui' } })).toBe(true)
  })
})

describe('StylePicker', () => {
  const preset = (over: Partial<Preset> = {}): Preset => ({
    id: 'p1', name: 'Giọng Cà phê Mộc', site_id: null, is_default: true, tone: null, writing_style: null, preferred_terms: [], forbidden_phrases: [], facts: [],
    target_customers: [], geographic_context: null, editorial_guidelines: null, cta: null, defaults: {}, is_demo: false,
    style_profile: { summary: 'Thân mật, câu ngắn' }, style_draft: null, style_sources: { used: [{ type: 'site_post', title: 'a', url: null, words: 100 }], origin: { type: 'blog', label: 'caphemoc.vn' } },
    style_learned_at: null, created_at: null, updated_at: null, ...over,
  })

  it('shows your styles with their source, folds ready-made ones and selects one', async () => {
    const { default: StylePicker } = await import('@/apps/customer/pages/writer/components/StylePicker.vue')
    const w = mount(StylePicker, { props: { presets: [preset()], modelValue: null }, global: { plugins: [i18n], stubs: { RouterLink: true } } })
    expect(w.text()).toContain('Giọng Cà phê Mộc')
    expect(w.text()).toContain('Toàn bộ blog · 1 bài')
    expect(w.findAll('[data-testid^="builtin-"]')).toHaveLength(3)
    await w.get('button.text-brand-600').trigger('click') // "Xem thêm 4 kiểu"
    expect(w.findAll('[data-testid^="builtin-"]')).toHaveLength(7)
    await w.get('[data-testid="builtin-sales"]').trigger('click')
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([{ kind: 'builtin', key: 'sales' }])
  })

  it('keeps a chosen ready-made style visible while folded', async () => {
    const { default: StylePicker } = await import('@/apps/customer/pages/writer/components/StylePicker.vue')
    const w = mount(StylePicker, { props: { presets: [], modelValue: { kind: 'builtin', key: 'formal' } }, global: { plugins: [i18n], stubs: { RouterLink: true } } })
    expect(w.get('[data-testid="builtin-formal"]').attributes('aria-checked')).toBe('true')
  })
})
