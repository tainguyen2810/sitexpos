const { chromium } = require('playwright')
const { execFileSync } = require('child_process')
// Browser smoke test for the Phase 2 "Websites" screens against a real
// WordPress with the SiteX Post plugin (see wordpress-plugin/tests/e2e/README.md).
//   SITEX_APP_URL=http://127.0.0.1:5173 SITEX_WP_URL=http://127.0.0.1:8081 node scripts/e2e/sites.js <screenshot-dir>
// The WordPress site must be installed with install-wp.php and not connected.
const OUT = process.argv[2] ?? require('os').tmpdir()
const APP = process.env.SITEX_APP_URL ?? 'http://127.0.0.1:5173'
const WP = process.env.SITEX_WP_URL ?? 'http://127.0.0.1:8081'
const BACKEND = require('path').resolve(__dirname, '../../backend')
;(async () => {
  const browser = await chromium.launch()
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, locale: 'vi-VN' })
  const page = await ctx.newPage()
  page.on('console', (m) => m.type() === 'error' && console.log('console error:', m.text()))
  await page.goto(APP + '/login')
  await ctx.request.get(APP + '/sanctum/csrf-cookie')
  const xsrf = async () => decodeURIComponent((await ctx.cookies()).find((c) => c.name === 'XSRF-TOKEN').value)
  const email = `ui-${Date.now()}@example.test`
  let r = await ctx.request.post(APP + '/api/v1/auth/register', { data: { name: 'Tài Nguyễn', email, password: 'E2e-Passw0rd-2026!', password_confirmation: 'E2e-Passw0rd-2026!', locale: 'vi' }, headers: { 'X-XSRF-TOKEN': await xsrf(), Accept: 'application/json', Referer: APP + '/', Origin: APP } })
  console.log('register', r.status())
  execFileSync('php', ['artisan', 'tinker', '--execute', `\\SiteXPost\\Modules\\Identity\\Domain\\User::where('email','${email}')->update(['email_verified_at'=>now()]);`], { cwd: BACKEND, stdio: 'ignore' })
  r = await ctx.request.post(APP + '/api/v1/workspaces', { data: { name: 'Cửa hàng Hoa Mai' }, headers: { 'X-XSRF-TOKEN': await xsrf(), Accept: 'application/json', Referer: APP + '/', Origin: APP } })
  const wsBody = await r.json(); if (!wsBody.data) console.log('ws', r.status(), JSON.stringify(wsBody)); const ws = wsBody.data.id
  await page.goto(`${APP}/w/${ws}/sites`)
  await page.waitForSelector('[data-testid=empty-state]')
  await page.screenshot({ path: OUT + '/1-sites-empty.png' })
  await page.click('[data-testid=add-site]')
  await page.getByLabel('Tên hiển thị').fill('Blog Hoa Mai')
  await page.getByLabel('Địa chỉ website').fill(WP)
  await page.screenshot({ path: OUT + '/2-add-site.png' })
  await page.click('button[form=add-site-form]')
  await page.waitForSelector('[data-testid=pairing-code]')
  const code = (await page.textContent('[data-testid=pairing-code]')).trim()
  await page.screenshot({ path: OUT + '/3-pairing-code.png' })
  console.log('code', code)

  // Pair from WordPress admin
  const wp = await ctx.newPage()
  await wp.goto(WP + '/wp-login.php')
  await wp.fill('#user_login', 'admin'); await wp.fill('#user_pass', 'AdminPass123!'); await wp.click('#wp-submit'); await wp.waitForURL(/wp-admin/)
  await wp.goto(WP + '/wp-admin/admin.php?page=sitex-post')
  await wp.fill('#sxp-code', code)
  await wp.click('form:has(input[name=action][value=sitex_post_preview]) [type=submit]'); await wp.waitForLoadState('load')
  const author = await wp.$eval('#sxp-author', (s) => [...s.options].find((o) => o.text.includes('sitex-author')).value)
  await wp.selectOption('#sxp-author', author)
  await wp.check('input[name=sxp_confirm]')
  await wp.click('form:has(input[name=action][value=sitex_post_pair]) [type=submit]'); await wp.waitForLoadState('load')
  await wp.close()

  await page.getByRole('button', { name: 'Xong' }).click()
  await page.waitForURL(/\/sites\/[0-9A-Z]+/)
  for (let i = 0; i < 20; i++) {
    await page.reload(); await page.waitForSelector('[data-testid=site-status]')
    if ((await page.textContent('[data-testid=site-status]')).includes('Đã kết nối') && !(await page.textContent('body')).includes('WordPress\n—')) break
    await page.waitForTimeout(1000)
  }
  await page.waitForTimeout(3000); await page.reload(); await page.waitForSelector('[data-testid=site-status]')
  await page.screenshot({ path: OUT + '/4-site-connected.png', fullPage: true })
  await page.getByRole('button', { name: 'Đồng bộ ngay' }).click()
  await page.getByText('Đã đồng bộ chuyên mục').waitFor({ timeout: 60000 })
  await page.waitForTimeout(800)
  await page.screenshot({ path: OUT + '/5-after-sync.png', fullPage: true })
  await page.getByRole('tab', { name: 'Chuyên mục & thẻ' }).click(); await page.waitForTimeout(800)
  await page.screenshot({ path: OUT + '/6-taxonomies.png', fullPage: true })
  await page.getByRole('button', { name: 'Đăng bài thử' }).click()
  await page.getByText('Đã tạo bài nháp thử').waitFor({ timeout: 60000 })
  await page.getByRole('tab', { name: 'Nhật ký kết nối' }).click(); await page.waitForTimeout(800)
  await page.screenshot({ path: OUT + '/7-logs.png', fullPage: true })
  await page.getByRole('button', { name: 'Ngắt kết nối' }).first().click()
  await page.locator('[role=dialog] button', { hasText: 'Xác nhận' }).click().catch(async () => { await page.getByRole('button', { name: /Xác nhận|Confirm/ }).last().click() })
  await page.waitForTimeout(1500)
  await page.screenshot({ path: OUT + '/8-disconnected.png', fullPage: true })
  await page.goto(`${APP}/w/${ws}/sites`); await page.waitForTimeout(800)
  await page.screenshot({ path: OUT + '/9-sites-list.png' })
  await page.setViewportSize({ width: 390, height: 844 }); await page.goto(`${APP}/w/${ws}/dashboard`); await page.waitForTimeout(1000)
  await page.screenshot({ path: OUT + '/10-dashboard-mobile.png', fullPage: true })
  await browser.close()
})().catch((e) => { console.error(e); process.exit(1) })
