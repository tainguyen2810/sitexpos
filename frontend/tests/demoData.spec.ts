import { describe, expect, it } from 'vitest'
import { generateDemoDb } from '@/demo/data/seed'

// The demo dataset is a product requirement (counts + links between modules).
describe('demo dataset', () => {
  const db = generateDemoDb(Date.UTC(2026, 10, 5, 8))

  it('has the requested volumes', () => {
    expect(db.workspaces).toHaveLength(5)
    expect(db.sites).toHaveLength(10)
    expect(db.campaigns).toHaveLength(8)
    expect(db.ideas).toHaveLength(150)
    expect(db.articles).toHaveLength(50)
    expect(db.factChecks).toHaveLength(20)
    expect(db.keywords).toHaveLength(100)
    expect(db.media).toHaveLength(30)
    expect(db.invoices.length).toBeGreaterThan(10)
    expect(db.aiUsage.length).toBeGreaterThan(100)
  })

  it('is deterministic', () => {
    const again = generateDemoDb(Date.UTC(2026, 10, 5, 8))
    expect(JSON.stringify(again)).toBe(JSON.stringify(db))
  })

  it('is clearly labelled as demo data', () => {
    expect(db.workspaces.every((w) => w.isDemo && w.name.endsWith('(Demo)'))).toBe(true)
    expect(db.sites.every((s) => new URL(s.url).hostname.endsWith('.example'))).toBe(true)
    expect(db.keywords.every((k) => k.metrics === null || k.metrics.provider === 'demo')).toBe(true)
    const urls = db.factChecks.flatMap((f) => f.claims.flatMap((c) => c.evidence.map((e) => e.url)))
    expect(urls.every((u) => new URL(u).hostname.endsWith('.example'))).toBe(true)
  })

  it('keeps references consistent across modules', () => {
    const ids = (list: { id: string }[]) => new Set(list.map((x) => x.id))
    const sites = ids(db.sites)
    const ws = ids(db.workspaces)
    const articles = ids(db.articles)
    const keywords = ids(db.keywords)
    const ideas = ids(db.ideas)
    for (const a of db.articles) {
      expect(sites.has(a.siteId)).toBe(true)
      expect(ws.has(a.workspaceId)).toBe(true)
      if (a.keywordId) expect(keywords.has(a.keywordId)).toBe(true)
      if (a.ideaId) expect(ideas.has(a.ideaId)).toBe(true)
      expect(db.sites.find((s) => s.id === a.siteId)?.workspaceId).toBe(a.workspaceId)
    }
    for (const f of db.factChecks) expect(articles.has(f.articleId)).toBe(true)
    for (const p of db.publishJobs) expect(articles.has(p.articleId)).toBe(true)
    for (const c of db.campaigns) expect(sites.has(c.siteId)).toBe(true)
    for (const m of db.media) m.articleIds.forEach((id) => expect(articles.has(id)).toBe(true))
  })

  it('never marks AI images as real product photos and never schedules unverified articles', () => {
    expect(db.media.filter((m) => m.isAiGenerated).every((m) => !m.representsRealProduct)).toBe(true)
    const scheduled = db.articles.filter((a) => ['scheduled', 'publishing', 'published'].includes(a.status))
    expect(scheduled.every((a) => a.verificationStatus === 'passed' || a.verificationStatus === 'overridden')).toBe(true)
  })
})
