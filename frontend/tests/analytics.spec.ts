import { describe, expect, it } from 'vitest'
import viMessages from '@/locales/vi'
import {
  CHANGE_TYPES, CLASSES, CONTENT_LABELS, DECAY_STATUSES, decayTone, defaultPeriod, EXPERIMENT_OUTCOMES, EXPERIMENT_STATUSES, HEALTH_COMPONENTS, healthTone,
  num, OPPORTUNITY_STATUSES, pct, qs, RECOMMENDATION_STATUSES, RECOMMENDATION_TYPES, REFRESH_STATUSES, refreshTone, RULES, SEARCH_TYPES, SEVERITIES, signedPct, SORTS,
} from '@/shared/api/analytics'
import { blockedAtLevel, LEVELS, needsApproval, PHASES, RUN_KINDS, RUN_STATUSES, SCHEDULE_STATUSES, TASK_STATUSES, TOOLS, type ToolInfo } from '@/shared/api/agent'
import { AGENT_NAV, ANALYTICS_NAV } from '@/apps/customer/pages/analytics/useAnalytics'

function has(obj: unknown, path: string): boolean {
  return typeof path.split('.').reduce<unknown>((o, k) => (o && typeof o === 'object' ? (o as Record<string, unknown>)[k] : undefined), obj) === 'string'
}

describe('analytics formatting never invents numbers', () => {
  it('shows a dash for missing values instead of zero', () => {
    expect(num(null)).toBe('—')
    expect(pct(undefined)).toBe('—')
    expect(signedPct(null)).toBe('—')
    expect(num(1234)).toMatch(/1\.234/)
    expect(pct(3.456)).toMatch(/3,5%/)
    expect(signedPct(12.5)).toMatch(/^\+12,5%$/)
    expect(signedPct(-4)).toMatch(/^-4,0%$/)
  })

  it('builds query strings without empty filters', () => {
    expect(qs({ a: '', b: null, c: undefined, d: false })).toBe('')
    expect(qs({ site_id: 's1', mapped_only: true, page: 2 })).toBe('?site_id=s1&mapped_only=1&page=2')
  })

  it('defaults to the last 28 complete days before the Search Console lag', () => {
    const p = defaultPeriod(new Date(Date.UTC(2026, 10, 6)))
    expect(p).toEqual({ from: '2026-10-07', to: '2026-11-03' })
  })

  it('maps states to tones', () => {
    expect(healthTone(null)).toBe('neutral')
    expect(healthTone(85)).toBe('success')
    expect(healthTone(30)).toBe('danger')
    expect(refreshTone('conflict')).toBe('danger')
    expect(refreshTone('awaiting_publish')).toBe('warning')
    expect(decayTone('CONFIRMED_ISSUE')).toBe('danger')
  })
})

describe('agent approval hints mirror the server policy', () => {
  const tool = (name: ToolInfo['name'], min_level: number, writes = true): ToolInfo => ({ name, min_level, writes, permission: 'content.edit', content: false, cost: 0, description: '' })

  it('requires approval below the tool level and for publishing unless level 3 is enabled', () => {
    expect(needsApproval(tool('create_refresh_brief', 2), 1)).toBe(true)
    expect(needsApproval(tool('create_refresh_brief', 2), 2)).toBe(false)
    expect(needsApproval(tool('publish_via_workflow', 3), 3, { approval_requirements: {}, level3_enabled: false })).toBe(true)
    expect(needsApproval(tool('publish_via_workflow', 3), 3, { approval_requirements: {}, level3_enabled: true })).toBe(false)
    expect(needsApproval(tool('create_proposal', 1), 2, { approval_requirements: { create_proposal: true }, level3_enabled: false })).toBe(true)
  })

  it('blocks every writing tool at level 0', () => {
    expect(blockedAtLevel(tool('create_proposal', 1), 0)).toBe(true)
    expect(blockedAtLevel(tool('read_analytics', 0, false), 0)).toBe(false)
  })
})

describe('Phase 8 i18n', () => {
  const sources = import.meta.glob(['/src/apps/customer/pages/{analytics,agent}/*.{vue,ts}', '/src/apps/admin/pages/AnalyticsAdminPage.vue'], { query: '?raw', import: 'default', eager: true }) as Record<string, string>

  it('defines every static an/ag/anAdmin key used by the screens', () => {
    const missing: string[] = []
    expect(Object.keys(sources).length).toBeGreaterThan(25)
    for (const [f, src] of Object.entries(sources)) {
      for (const m of src.matchAll(/\bt\(\s*'((?:an|ag|anAdmin)\.[A-Za-z0-9_.]+)'/g)) {
        if (!has(viMessages, m[1]!)) missing.push(`${f}: ${m[1]}`)
      }
    }
    expect(missing).toEqual([])
  })

  it('labels every enum value the API can return', () => {
    const missing: string[] = []
    const check = (prefix: string, values: readonly (string | number)[], suffix = '') => {
      for (const v of values) if (!has(viMessages, `${prefix}.${v}${suffix}`)) missing.push(`${prefix}.${v}${suffix}`)
    }
    check('an.class', CLASSES)
    check('an.classHint', CLASSES)
    check('an.labels', CONTENT_LABELS)
    check('an.rules', RULES, '.name')
    check('an.rules', RULES, '.desc')
    check('an.severity', SEVERITIES)
    check('an.opportunities.status', OPPORTUNITY_STATUSES)
    check('an.decay.status', DECAY_STATUSES)
    check('an.decay.mark', DECAY_STATUSES)
    check('an.actions', [...RECOMMENDATION_TYPES, 'rewrite_title_meta', 'fix_metadata', 'investigate'])
    check('an.recommendations.status', RECOMMENDATION_STATUSES)
    check('an.refresh.status', REFRESH_STATUSES)
    check('an.experiments.change', CHANGE_TYPES)
    check('an.experiments.status', EXPERIMENT_STATUSES)
    check('an.experiments.outcome', EXPERIMENT_OUTCOMES)
    check('an.health.c', HEALTH_COMPONENTS)
    check('an.searchType', SEARCH_TYPES)
    check('an.metrics', SORTS)
    check('an.nav', ANALYTICS_NAV.map((i) => i.key))
    check('ag.nav', AGENT_NAV.map((i) => i.key))
    check('ag.tools', TOOLS)
    check('ag.levels', LEVELS, '.name')
    check('ag.levels', LEVELS, '.desc')
    check('ag.kinds', RUN_KINDS)
    check('ag.runStatus', RUN_STATUSES)
    check('ag.taskStatus', TASK_STATUSES)
    check('ag.phases', PHASES)
    check('ag.schedules.status', SCHEDULE_STATUSES)
    check('ag.weekdays', [1, 2, 3, 4, 5, 6, 7])
    check('ag.reasons', ['policy_allows', 'level_requires_approval', 'site_requires_approval', 'platform_kill_switch', 'emergency_stop', 'publish_requires_level_3', 'outside_publish_window', 'monthly_budget_exceeded'])
    check('nav', ['analytics', 'agent', 'analyticsAdmin'])
    expect(missing).toEqual([])
  })

  it('never renders raw HTML and never says A/B test', () => {
    for (const [f, src] of Object.entries(sources)) {
      expect(src.includes('v-html'), f).toBe(false)
    }
    const an = (viMessages as unknown as { an: { experiments: { subtitle: string }; health: { disclaimer: string } } }).an
    expect(an.experiments.subtitle).toMatch(/không phải A\/B/)
    expect(an.health.disclaimer).toMatch(/không phải điểm của Google/)
  })
})
