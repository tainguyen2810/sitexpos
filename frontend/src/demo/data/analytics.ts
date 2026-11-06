// Simulated Google Search Console data, derived deterministically from the site id so it is
// stable across reloads without being stored. Clearly labelled "dữ liệu mô phỏng" in the UI.
import { createRng } from './rng'
import type { DemoArticle, DemoKeyword, DemoSite } from './types'

const DAY = 86_400_000

function hash(s: string): number {
  let h = 2166136261
  for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619)
  return h >>> 0
}

export interface GscDay {
  date: string
  clicks: number
  impressions: number
  ctr: number
  position: number
}

export function gscDaily(site: DemoSite, days = 90): GscDay[] {
  const r = createRng(hash(site.id))
  const base = 20 + (site.postsCount / 4) * (0.6 + r.next())
  const out: GscDay[] = []
  let pos = 18 + r.next() * 10
  for (let d = days - 1; d >= 0; d--) {
    const t = days - d
    const weekday = new Date(Date.now() - d * DAY).getDay()
    const seasonal = weekday === 0 || weekday === 6 ? 0.72 : 1
    const growth = 1 + (t / days) * 0.45
    const impressions = Math.round(base * 28 * growth * seasonal * (0.85 + r.next() * 0.3))
    pos = Math.max(4, pos - r.next() * 0.12 + 0.05)
    const ctr = Math.max(0.6, 5.5 - pos * 0.18 + r.next() * 0.6)
    out.push({
      date: new Date(Date.now() - d * DAY).toISOString().slice(0, 10),
      impressions,
      clicks: Math.round((impressions * ctr) / 100),
      ctr,
      position: pos,
    })
  }
  return out
}

export function sumRange(days: GscDay[]) {
  const clicks = days.reduce((s, d) => s + d.clicks, 0)
  const impressions = days.reduce((s, d) => s + d.impressions, 0)
  return {
    clicks,
    impressions,
    ctr: impressions ? (clicks / impressions) * 100 : 0,
    position: days.length ? days.reduce((s, d) => s + d.position, 0) / days.length : 0,
  }
}

export function gscTopQueries(site: DemoSite, keywords: DemoKeyword[]) {
  const r = createRng(hash(`${site.id}:q`))
  return keywords
    .filter((k) => k.siteId === site.id)
    .map((k) => {
      const impressions = r.int(80, 4200)
      const position = k.position ?? r.int(5, 40)
      const ctr = Math.max(0.3, 9 - position * 0.25 + r.next())
      return { query: k.keyword, impressions, clicks: Math.round((impressions * ctr) / 100), ctr, position, change: Math.round((r.next() - 0.4) * 40) }
    })
    .sort((a, b) => b.clicks - a.clicks)
}

export function gscTopPages(site: DemoSite, articles: DemoArticle[]) {
  const r = createRng(hash(`${site.id}:p`))
  const managed = articles.filter((a) => a.siteId === site.id && a.wpUrl)
  const others = ['/', '/gioi-thieu/', '/lien-he/', '/blog/'].map((p) => ({ url: `${site.url}${p}`, title: p === '/' ? 'Trang chủ' : p.replace(/\//g, ''), managed: false }))
  return [...managed.map((a) => ({ url: a.wpUrl!, title: a.title, managed: true })), ...others]
    .map((p) => {
      const impressions = r.int(120, 6000)
      const position = 3 + r.next() * 25
      const ctr = Math.max(0.4, 8 - position * 0.22 + r.next())
      return { ...p, impressions, clicks: Math.round((impressions * ctr) / 100), ctr, position, trend: Array.from({ length: 12 }, () => r.int(10, 100)) }
    })
    .sort((a, b) => b.clicks - a.clicks)
}
