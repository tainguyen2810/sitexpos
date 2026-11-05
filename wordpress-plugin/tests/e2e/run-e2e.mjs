#!/usr/bin/env node
/**
 * End-to-end test: SiteX Post Cloud <-> real WordPress with the SiteX Post plugin.
 *
 * Prerequisites (see wordpress-plugin/tests/e2e/README.md):
 *   - Cloud API on CLOUD_URL (default http://127.0.0.1:8000) with a queue worker
 *     on the "connector" queue, SITEX_SSRF_ALLOW_HOSTS=127.0.0.1 and
 *     SITEX_SSRF_ALLOW_HTTP=true (local test only).
 *   - WordPress sites installed with install-wp.php and served over HTTP.
 *
 *   node run-e2e.mjs <wp-dir>@<port>[:stubs] [<wp-dir>@<port>[:stubs] ...]
 *
 * Each site is paired through the real WordPress admin wizard (Playwright),
 * then health check, sync, test publish x3, direct signed-write attacks,
 * key rotation and disconnect are checked against the real databases.
 */
import { createRequire } from 'node:module'
import { createHash, createHmac, randomBytes } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
// CommonJS resolution so a globally installed Playwright (NODE_PATH) works too.
const { chromium, request } = createRequire(import.meta.url)('playwright')
const CLOUD = process.env.CLOUD_URL || 'http://127.0.0.1:8000'
const SHOTS = process.env.E2E_SCREENSHOTS || path.join(here, 'screenshots')
mkdirSync(SHOTS, { recursive: true })

const results = []
let failures = 0
function check(name, ok, detail = '') {
  results.push({ name, ok, detail })
  if (!ok) failures++
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`)
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

function inspect(dir, what) {
  return JSON.parse(execFileSync('php', [path.join(here, 'wp-inspect.php'), dir, what], { encoding: 'utf8' }) || 'null')
}

// ---------- Cloud API client (Sanctum SPA cookies) ----------
async function cloudClient() {
  const ctx = await request.newContext({ baseURL: CLOUD, extraHTTPHeaders: { Accept: 'application/json', Referer: 'http://127.0.0.1/', Origin: 'http://127.0.0.1' } })
  await ctx.get('/sanctum/csrf-cookie')
  const xsrf = async () => decodeURIComponent((await ctx.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value || '')
  const call = async (method, url, data) => {
    const res = await ctx.fetch(url, { method, data, headers: { 'X-XSRF-TOKEN': await xsrf() } })
    let body = null
    try { body = await res.json() } catch { body = null }
    return { status: res.status(), body }
  }
  return { ctx, call }
}

async function waitJob(api, ws, jobRun, timeoutMs = 60000) {
  const until = Date.now() + timeoutMs
  while (Date.now() < until) {
    const r = await api.call('GET', `/api/v1/workspaces/${ws}/job-runs/${jobRun.id}`)
    const status = r.body?.data?.status
    if (status === 'succeeded' || status === 'failed') return r.body.data
    await sleep(500)
  }
  return { status: 'timeout' }
}

async function waitSite(api, ws, siteId, pred, timeoutMs = 60000) {
  const until = Date.now() + timeoutMs
  let last = null
  while (Date.now() < until) {
    last = (await api.call('GET', `/api/v1/workspaces/${ws}/sites/${siteId}`)).body?.data
    if (last && pred(last)) return last
    await sleep(700)
  }
  return last
}

// ---------- Direct signed requests to the plugin (attacker / replay checks) ----------
function b64urlDecode(s) { return Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64') }
function signRequest({ secret, method, route, query = {}, body = '', ts = Math.floor(Date.now() / 1000), nonce = randomBytes(18).toString('base64url') }) {
  const q = Object.keys(query).sort().map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(String(query[k]))}`).join('&')
  const canonical = ['SITEX-HMAC-V1', 'c2w', method, route, q, String(ts), nonce, createHash('sha256').update(body).digest('hex')].join('\n')
  return { ts, nonce, signature: createHmac('sha256', b64urlDecode(secret)).update(canonical).digest('base64') }
}
async function pluginFetch(base, { keyId, secret, method, route, body = '', sig, headers = {} }) {
  const s = sig || signRequest({ secret, method, route, body })
  const res = await fetch(`${base}/?rest_route=${encodeURIComponent(route)}`, {
    method,
    body: method === 'GET' ? undefined : body,
    headers: { 'Content-Type': 'application/json', 'X-SiteX-Key-Id': keyId, 'X-SiteX-Timestamp': String(s.ts), 'X-SiteX-Nonce': s.nonce, 'X-SiteX-Signature': s.signature, ...headers },
  })
  let json = null
  try { json = await res.json() } catch { json = null }
  return { status: res.status, json, sig: s }
}

// ---------- WordPress admin wizard ----------
async function pairThroughWizard(browser, site, code, authorId, label) {
  const page = await browser.newPage()
  await page.goto(`${site.base}/wp-login.php`)
  await page.fill('#user_login', 'admin')
  await page.fill('#user_pass', 'AdminPass123!')
  await page.click('#wp-submit')
  await page.waitForURL(/wp-admin/)
  await page.goto(`${site.base}/wp-admin/admin.php?page=sitex-post`)
  await page.screenshot({ path: path.join(SHOTS, `${label}-1-start.png`), fullPage: true })
  const passwordFields = await page.locator('input[type=password]').count()
  check(`[${label}] wizard never asks for a WordPress password`, passwordFields === 0)
  await page.fill('#sxp-code', code)
  await page.click('form:has(input[name=action][value=sitex_post_preview]) [type=submit]')
  await page.waitForLoadState('load')
  const confirmText = await page.textContent('body')
  await page.screenshot({ path: path.join(SHOTS, `${label}-2-confirm.png`), fullPage: true })
  check(`[${label}] confirm step shows the Cloud workspace before anything is granted`, confirmText.includes('E2E Workspace'))
  await page.selectOption('#sxp-author', String(authorId))
  await page.check('input[name=sxp_confirm]')
  await page.click('form:has(input[name=action][value=sitex_post_pair]) [type=submit]')
  await page.waitForLoadState('load')
  await page.screenshot({ path: path.join(SHOTS, `${label}-3-connected.png`), fullPage: true })
  return page
}

// ---------- main ----------
const sites = process.argv.slice(2).map((arg) => {
  const [dirPort, flag] = arg.split(':')
  const [dir, port] = dirPort.split('@')
  return { dir: path.resolve(dir), port, stubs: flag === 'stubs', base: `http://127.0.0.1:${port}` }
})
if (!sites.length) {
  console.error('usage: node run-e2e.mjs <wp-dir>@<port>[:stubs] ...')
  process.exit(2)
}

const api = await cloudClient()
const email = `e2e-${Date.now()}@example.test`
let r = await api.call('POST', '/api/v1/auth/register', { name: 'E2E Owner', email, password: 'E2e-Passw0rd-2026!', password_confirmation: 'E2e-Passw0rd-2026!', locale: 'vi' })
check('cloud: register owner', [200, 201].includes(r.status), `HTTP ${r.status}`)
execFileSync('php', ['artisan', 'tinker', '--execute', `\\SiteXPost\\Modules\\Identity\\Domain\\User::where('email','${email}')->update(['email_verified_at'=>now()]);`], { cwd: process.env.BACKEND_DIR || path.resolve(here, '../../../backend'), stdio: 'ignore', env: process.env })
r = await api.call('POST', '/api/v1/workspaces', { name: 'E2E Workspace' })
check('cloud: create workspace', r.status === 201, `HTTP ${r.status}`)
const ws = r.body?.data?.id

r = await api.call('POST', `/api/v1/workspaces/${ws}/sites`, { name: 'Internal', url: 'http://10.0.0.5' })
check('cloud: refuses a private-network site URL (SSRF)', r.status === 422, `HTTP ${r.status} ${r.body?.error?.code || ''}`)
r = await api.call('POST', `/api/v1/workspaces/${ws}/sites`, { name: 'Metadata', url: 'http://169.254.169.254' })
check('cloud: refuses the cloud metadata address (SSRF)', r.status === 422, `HTTP ${r.status}`)

const browser = await chromium.launch()
for (const site of sites) {
  const label = `wp${site.port}`
  const wpVersion = execFileSync('php', ['-r', `include '${site.dir}/wp-includes/version.php'; echo $wp_version;`], { encoding: 'utf8' })
  console.log(`\n=== ${label}: WordPress ${wpVersion}${site.stubs ? ' + WooCommerce/Yoast stubs' : ''} ===`)

  r = await api.call('POST', `/api/v1/workspaces/${ws}/sites`, { name: `E2E ${label}`, url: site.base })
  check(`[${label}] cloud: add website returns a pairing code`, r.status === 201 && /^[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(r.body?.meta?.pairing_code || r.body?.data?.pairing_code || ''), `HTTP ${r.status}`)
  const siteId = r.body?.data?.id
  const code = r.body?.meta?.pairing_code || r.body?.data?.pairing_code

  const authorId = inspect(site.dir, 'managed').author_id
  const page = await pairThroughWizard(browser, site, code, authorId, label)
  const conn = inspect(site.dir, 'connection')
  check(`[${label}] plugin stored a per-site key after admin confirmation`, conn?.status === 'connected' && !!conn?.key_id && !!conn?.secret)

  let s = await waitSite(api, ws, siteId, (d) => d.status === 'connected' && d.last_health_at)
  check(`[${label}] cloud: site connected and health check ran`, s?.status === 'connected' && !!s?.last_health_at, `status=${s?.status}`)
  check(`[${label}] cloud: versions detected`, s?.versions?.wordpress === wpVersion && s?.versions?.plugin === '1.0.0', JSON.stringify(s?.versions))
  check(`[${label}] cloud: publish author is the chosen Author account`, s?.publish_author?.wp_user_id === authorId)
  check(`[${label}] cloud: no health issues`, (s?.issues || []).length === 0, JSON.stringify(s?.issues))
  if (site.stubs) {
    check(`[${label}] cloud: detects WooCommerce and Yoast`, s?.features?.woocommerce === true && s?.features?.yoast === true, JSON.stringify(s?.features))
  }

  // Sync
  r = await api.call('POST', `/api/v1/workspaces/${ws}/sites/${siteId}/sync`)
  let job = await waitJob(api, ws, r.body?.data?.job_run || {})
  check(`[${label}] cloud: manual sync job succeeded`, job.status === 'succeeded', job.error_message || job.status)
  const terms = (await api.call('GET', `/api/v1/workspaces/${ws}/sites/${siteId}/taxonomies?per_page=100`)).body?.data || []
  check(`[${label}] sync: categories and tags (UTF-8 names)`, terms.some((t) => t.name === 'Hướng dẫn' && t.taxonomy === 'category') && terms.some((t) => t.name === 'seo' && t.taxonomy === 'post_tag'), `${terms.length} terms`)
  const content = (await api.call('GET', `/api/v1/workspaces/${ws}/sites/${siteId}/content?per_page=100`)).body?.data || []
  check(`[${label}] sync: posts and pages indexed with WP ids`, content.some((c) => c.post_type === 'post' && c.title === 'Bài mẫu 1' && c.wp_post_id > 0) && content.some((c) => c.post_type === 'page'), `${content.length} items`)
  if (site.stubs) {
    const product = content.find((c) => c.post_type === 'product')
    check(`[${label}] sync: WooCommerce product with price/SKU`, product?.product?.price === '199000' && product?.product?.sku === 'SX-TEE-01', JSON.stringify(product?.product))
    check(`[${label}] sync: product categories`, terms.some((t) => t.taxonomy === 'product_cat' && t.name === 'Áo'))
  }

  // Test publish x3 (retries must not duplicate)
  for (let i = 0; i < 3; i++) {
    r = await api.call('POST', `/api/v1/workspaces/${ws}/sites/${siteId}/test-publish`)
    job = await waitJob(api, ws, r.body?.data?.job_run || {})
    check(`[${label}] cloud: test publish #${i + 1} succeeded`, job.status === 'succeeded', job.error_message || job.status)
  }
  let wp = inspect(site.dir, 'managed')
  const testPosts = (wp.posts || []).filter((p) => p.meta._sitex_post_idempotency_key === 'sitex-connection-test')
  check(`[${label}] WordPress: 3 publishes -> exactly 1 post`, testPosts.length === 1, `${testPosts.length} posts`)
  const tp = testPosts[0] || { meta: {}, content: '' }
  check(`[${label}] WordPress: 2 images uploaded once (featured + inline)`, wp.attachments === 2, `${wp.attachments} attachments`)
  check(`[${label}] WordPress: featured image set`, Number(tp.meta._thumbnail_id) > 0)
  check(`[${label}] WordPress: inline image placeholder replaced by uploaded URL`, /wp-content\/uploads\/.+\.png/.test(tp.content) && !tp.content.includes('{{sitex-image'))
  check(`[${label}] WordPress: post is a draft by the Author account`, tp.status === 'draft' && tp.author === authorId)
  if (site.stubs) {
    check(`[${label}] SEO: Yoast meta written (description, focus keyword, noindex)`, !!tp.meta._yoast_wpseo_metadesc && tp.meta._yoast_wpseo_focuskw === 'sitex post' && tp.meta['_yoast_wpseo_meta-robots-noindex'] === '1')
  } else {
    check(`[${label}] SEO: native fallback meta written`, tp.meta._sitex_post_seo_adapter === 'native' && !!tp.meta._sitex_post_seo_description)
  }

  // Direct signed requests: replay, tampering, stale timestamp, unsigned, XSS
  const route = '/sitex-post/v1/posts'
  const body = JSON.stringify({ idempotency_key: 'e2e-direct-1', title: 'Direct <b>title</b>', status: 'draft', content_html: '<p>ok</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">x</a><iframe src="https://evil.example"></iframe>' })
  const first = await pluginFetch(site.base, { keyId: conn.key_id, secret: conn.secret, method: 'POST', route, body })
  check(`[${label}] plugin: valid signed create accepted`, [200, 201].includes(first.status), `HTTP ${first.status}`)
  const again = await pluginFetch(site.base, { keyId: conn.key_id, secret: conn.secret, method: 'POST', route, body })
  check(`[${label}] plugin: same idempotency key -> same post id`, again.json?.id === first.json?.id && !!first.json?.id, `${first.json?.id} / ${again.json?.id}`)
  const replay = await pluginFetch(site.base, { keyId: conn.key_id, secret: conn.secret, method: 'POST', route, body, sig: first.sig })
  check(`[${label}] plugin: replayed request rejected`, replay.status === 401, `HTTP ${replay.status} ${replay.json?.code || ''}`)
  const tampered = await pluginFetch(site.base, { keyId: conn.key_id, secret: conn.secret, method: 'POST', route, body: body.replace('draft', 'publish'), sig: signRequest({ secret: conn.secret, method: 'POST', route, body }) })
  check(`[${label}] plugin: tampered body rejected`, tampered.status === 401, `HTTP ${tampered.status}`)
  const stale = await pluginFetch(site.base, { keyId: conn.key_id, secret: conn.secret, method: 'POST', route, body, sig: signRequest({ secret: conn.secret, method: 'POST', route, body, ts: Math.floor(Date.now() / 1000) - 900 }) })
  check(`[${label}] plugin: stale timestamp rejected`, stale.status === 401, `HTTP ${stale.status}`)
  const wrongKey = await pluginFetch(site.base, { keyId: conn.key_id, secret: randomBytes(32).toString('base64url'), method: 'POST', route, body })
  check(`[${label}] plugin: wrong secret rejected`, wrongKey.status === 401, `HTTP ${wrongKey.status}`)
  const unsigned = await fetch(`${site.base}/?rest_route=${encodeURIComponent(route)}`, { method: 'POST', body, headers: { 'Content-Type': 'application/json' } })
  check(`[${label}] plugin: unsigned write rejected`, unsigned.status === 401, `HTTP ${unsigned.status}`)
  const coreRest = await fetch(`${site.base}/?rest_route=/wp/v2/posts`, { method: 'POST', body: JSON.stringify({ title: 'x' }), headers: { 'Content-Type': 'application/json', 'X-SiteX-Key-Id': conn.key_id } })
  check(`[${label}] plugin: SiteX headers do not authenticate core REST writes`, coreRest.status === 401, `HTTP ${coreRest.status}`)
  wp = inspect(site.dir, 'managed')
  const direct = (wp.posts || []).filter((p) => p.meta._sitex_post_idempotency_key === 'e2e-direct-1')
  const dc = direct[0]?.content || ''
  check(`[${label}] XSS: script, event handlers, javascript: URLs and iframes stripped`, direct.length === 1 && !/<script|onerror|javascript:|<iframe/i.test(dc) && dc.includes('<p>ok</p>'), dc.slice(0, 120))
  check(`[${label}] XSS: HTML in the title stripped`, direct[0]?.title === 'Direct title', direct[0]?.title)

  // Rotate keys: the new key works, Cloud keeps operating
  r = await api.call('POST', `/api/v1/workspaces/${ws}/sites/${siteId}/rotate-keys`)
  const conn2 = inspect(site.dir, 'connection')
  check(`[${label}] rotate: new key installed on WordPress`, r.status === 200 && conn2.key_id !== conn.key_id, `HTTP ${r.status}`)
  r = await api.call('POST', `/api/v1/workspaces/${ws}/sites/${siteId}/health-check`)
  job = await waitJob(api, ws, r.body?.data?.job_run || {})
  check(`[${label}] rotate: health check with the new key succeeds`, job.status === 'succeeded', job.error_message || job.status)

  // Disconnect: from Cloud for the first site, from the WordPress admin for the others
  if (site === sites[0]) {
    r = await api.call('POST', `/api/v1/workspaces/${ws}/sites/${siteId}/revoke`)
    check(`[${label}] disconnect from Cloud`, r.status === 200, `HTTP ${r.status}`)
  } else {
    await page.goto(`${site.base}/wp-admin/admin.php?page=sitex-post`)
    page.once('dialog', (d) => d.accept())
    await page.click('form:has(input[name=action][value=sitex_post_disconnect]) [type=submit]')
    await page.waitForLoadState('load')
    await page.screenshot({ path: path.join(SHOTS, `${label}-4-disconnected.png`), fullPage: true })
  }
  s = await waitSite(api, ws, siteId, (d) => d.status === 'revoked', 15000)
  check(`[${label}] after disconnect: Cloud shows revoked`, s?.status === 'revoked', `status=${s?.status} by=${s?.revoked_by}`)
  const connAfter = inspect(site.dir, 'connection')
  check(`[${label}] after disconnect: plugin forgot the secret`, !connAfter?.secret, JSON.stringify(connAfter)?.slice(0, 80))
  const afterNew = await pluginFetch(site.base, { keyId: conn2.key_id, secret: conn2.secret, method: 'POST', route, body: body.replace('e2e-direct-1', 'e2e-after-revoke') })
  const afterOld = await pluginFetch(site.base, { keyId: conn.key_id, secret: conn.secret, method: 'POST', route, body: body.replace('e2e-direct-1', 'e2e-after-revoke-old') })
  check(`[${label}] after disconnect: signed writes with the last key rejected`, afterNew.status === 401 && afterOld.status === 401, `HTTP ${afterNew.status}/${afterOld.status}`)
  r = await api.call('POST', `/api/v1/workspaces/${ws}/sites/${siteId}/test-publish`)
  check(`[${label}] after disconnect: Cloud refuses to publish`, r.status >= 400 && r.status < 500, `HTTP ${r.status} ${r.body?.error?.code || ''}`)
  wp = inspect(site.dir, 'managed')
  check(`[${label}] after disconnect: no new posts on WordPress`, !(wp.posts || []).some((p) => String(p.meta._sitex_post_idempotency_key).startsWith('e2e-after-revoke')))
  await page.close()
}
await browser.close()
await api.ctx.dispose()

console.log(`\n${results.length - failures}/${results.length} checks passed`)
process.exit(failures ? 1 : 0)
