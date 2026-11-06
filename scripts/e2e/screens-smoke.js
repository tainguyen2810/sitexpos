// Smoke test: every customer screen renders against a REAL backend seeded with
// DemoSeeder (development only). Checks: no uncaught JS error, no 5xx API
// response, no stuck loading state, Vietnamese error/empty/data state visible.
// It does NOT validate business results (demo data is not real data).
//   SITEX_APP_URL=http://127.0.0.1:5173 node scripts/e2e/screens-smoke.js /tmp/shots
//   SITEX_MODE=admin SITEX_APP_URL=http://127.0.0.1:5174 node scripts/e2e/screens-smoke.js /tmp/shots
//   (admin mode enrols 2FA for the demo Super Admin on first run, like admin.js)
const { chromium } = require('playwright')
const crypto = require('crypto')
const ADMIN = process.env.SITEX_MODE === 'admin'
const BASE = process.env.SITEX_APP_URL ?? (ADMIN ? 'http://127.0.0.1:5174' : 'http://127.0.0.1:5173')
const shots = process.argv[2] ?? '/tmp/sitex-screens'
const ADMIN_PATHS = [
  '', 'workspaces', 'users', 'staff', 'ai/usage', 'ai/settings', 'research', 'writer', 'media', 'publishing',
  'billing', 'billing/plans', 'billing/subscriptions', 'billing/orders', 'billing/reconciliation', 'billing/refunds',
  'billing/credits', 'billing/reports', 'billing/settings', 'analytics', 'setup', 'system/health', 'system/upgrade',
  'system/backups', 'system/alerts', 'jobs', 'audit', 'account',
]

function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const h = crypto.createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 0xf
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0')
}
const CUSTOMER_PATHS = [
  'dashboard', 'sites', 'research', 'writer', 'writer/new', 'writer/bulk', 'writer/presets', 'writer/history',
  'media', 'media/generate', 'media/library', 'media/articles', 'seo/audit', 'seo/metadata', 'seo/links',
  'publishing', 'publishing/calendar', 'publishing/history', 'publishing/conflicts', 'publishing/settings',
  'analytics', 'analytics/connections', 'analytics/queries', 'analytics/pages', 'analytics/articles', 'analytics/content',
  'analytics/opportunities', 'analytics/decay', 'analytics/recommendations', 'analytics/refresh', 'analytics/experiments',
  'analytics/health', 'analytics/reports', 'analytics/copilot', 'analytics/settings',
  'agent', 'agent/approvals', 'agent/runs', 'agent/schedules', 'agent/logs',
  'billing', 'billing/plans', 'billing/credits', 'billing/transactions', 'billing/usage', 'billing/orders', 'billing/history', 'billing/profile',
  'ai', 'members', 'settings',
]
const PATHS = ADMIN ? ADMIN_PATHS : CUSTOMER_PATHS
;(async () => {
  require('fs').mkdirSync(shots, { recursive: true })
  const browser = await chromium.launch()
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
  const pageErrors = []
  const serverErrors = []
  page.on('pageerror', (e) => pageErrors.push(`${page.url()}: ${e.message}`))
  page.on('response', (r) => { if (r.status() >= 500) serverErrors.push(`${r.status()} ${r.url()}`) })

  const password = process.env.SITEX_PASSWORD ?? 'SiteXDemo2026'
  await page.goto(BASE + '/login')
  await page.getByLabel('Email').fill(process.env.SITEX_USER ?? (ADMIN ? 'superadmin@demo.sitexpost.test' : 'owner@demo.sitexpost.test'))
  await page.getByLabel('Mật khẩu').fill(password)
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  let prefix
  if (ADMIN) {
    await page.waitForURL(/\/(setup-2fa|two-factor)?$/)
    if (page.url().endsWith('/setup-2fa')) {
      await page.getByRole('button', { name: 'Bật 2FA' }).click()
      await page.getByLabel('Nhập mật khẩu để tiếp tục').fill(password)
      await page.getByRole('button', { name: 'Xác nhận' }).click()
      const secret = (await page.getByText('Hoặc nhập khóa thủ công').textContent()).split(':').pop().trim()
      await page.getByLabel('Mã xác thực').fill(totp(secret))
      await page.getByRole('button', { name: 'Xác nhận' }).click()
      await page.getByRole('button', { name: 'Tiếp tục' }).click()
    } else if (page.url().endsWith('/two-factor')) {
      throw new Error('Super Admin already has 2FA: pass a fresh demo database or log in manually')
    }
    prefix = BASE
  } else {
    await page.waitForURL(/\/w\/[0-9a-z]{26}\/dashboard$/)
    prefix = `${BASE}/w/${page.url().match(/\/w\/([0-9a-z]{26})\//)[1]}`
  }

  let ok = 0
  const failed = []
  for (const p of PATHS) {
    const before = pageErrors.length + serverErrors.length
    await page.goto(`${prefix}/${p}`)
    await page.waitForLoadState('networkidle').catch(() => {})
    const h1 = (await page.locator('main h1, h1').first().textContent({ timeout: 8000 }).catch(() => null))?.trim()
    const spinning = await page.locator('[aria-busy="true"]').count()
    const errs = pageErrors.length + serverErrors.length - before
    if (!h1 || errs > 0 || spinning > 0) failed.push(`${p} (h1=${h1 ?? '-'}, errors=${errs}, busy=${spinning})`)
    else ok++
    await page.screenshot({ path: `${shots}/${ADMIN ? 'admin_' : ''}${p.replace(/\//g, '_') || 'home'}.png` })
  }
  await page.setViewportSize({ width: 390, height: 844 })
  const overflow = []
  for (const p of PATHS) {
    await page.goto(`${prefix}/${p}`)
    await page.waitForLoadState('networkidle').catch(() => {})
    const w = await page.evaluate(() => document.documentElement.scrollWidth)
    if (w > 390) overflow.push(`${p}: ${w}px`)
  }
  console.log(`screens ok: ${ok}/${PATHS.length}`)
  if (failed.length) console.log('failed:\n  ' + failed.join('\n  '))
  console.log(overflow.length ? 'mobile overflow:\n  ' + overflow.join('\n  ') : 'mobile 390px: no horizontal overflow on any screen')
  if (pageErrors.length) console.log('page errors:\n  ' + pageErrors.join('\n  '))
  if (serverErrors.length) console.log('5xx:\n  ' + serverErrors.join('\n  '))
  await browser.close()
  process.exit(failed.length || overflow.length ? 1 : 0)
})().catch((e) => { console.error('✗', e.message); process.exit(1) })
