const { chromium } = require('playwright')
const fs = require('fs')
const LOG = process.env.SITEX_LOG ?? require('path').resolve(__dirname, '../../backend/storage/logs', `laravel-${new Date().toISOString().slice(0, 10)}.log`)
const BASE = process.env.SITEX_APP_URL ?? 'http://127.0.0.1:5173'
const shots = process.argv[2] ?? require('os').tmpdir()
const step = (m) => console.log('✓', m)

;(async () => {
  const browser = await chromium.launch()
  const page = await browser.newPage({ viewport: { width: 1280, height: 860 } })
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))

  const email = `e2e${Date.now()}@example.com`
  await page.goto(BASE + '/')
  await page.waitForURL('**/login')
  step('guest redirected to /login')
  await page.screenshot({ path: `${shots}/01-login.png` })

  await page.getByRole('link', { name: 'Đăng ký' }).click()
  await page.getByLabel('Họ tên').fill('Người Dùng E2E')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Mật khẩu', { exact: true }).fill('MatKhau2026x')
  await page.getByLabel('Nhập lại mật khẩu').fill('MatKhau2026x')
  await page.getByRole('button', { name: 'Đăng ký' }).click()
  await page.waitForURL('**/verify-email')
  step('registered → verify-email notice')

  // Email is sent via the log mailer: pull the verification link out of the log.
  let link = null
  for (let i = 0; i < 20 && !link; i++) {
    const log = fs.readFileSync(LOG, 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=')
    const m = [...log.matchAll(/(https?:\/\/[^\s"<>/]+\/verify-email\?link=[^\s"<>)\]]+)/g)].pop()
    link = m ? m[1].replace(/&amp;/g, '&') : null
    if (!link) await page.waitForTimeout(250)
  }
  if (!link) throw new Error('verification link not found in log')
  await page.goto(BASE + link.slice(link.indexOf('/verify-email')))
  await page.waitForURL('**/onboarding')
  step('email verified via link → onboarding')
  await page.screenshot({ path: `${shots}/02-onboarding.png` })

  await page.getByLabel('Tên workspace').fill('Workspace E2E')
  await page.getByRole('button', { name: 'Tạo workspace' }).click()
  await page.waitForURL(/\/w\/[0-9a-z]{26}\/dashboard$/)
  const wsId = page.url().match(/\/w\/([0-9a-z]{26})\//)[1]
  await page.getByText('Xin chào, Người Dùng E2E!').waitFor()
  step(`workspace created → dashboard (${wsId})`)
  await page.screenshot({ path: `${shots}/03-dashboard.png` })

  await page.getByRole('link', { name: 'Thành viên' }).first().click()
  await page.getByRole('button', { name: 'Mời thành viên' }).click()
  await page.getByRole('dialog').getByLabel('Email').fill('teammate@example.com')
  await page.getByRole('button', { name: 'Gửi lời mời' }).click()
  await page.getByText('teammate@example.com').waitFor()
  step('invitation sent and listed')
  await page.screenshot({ path: `${shots}/04-members.png` })

  // Second workspace + switcher
  await page.goto(BASE + '/onboarding')
  await page.getByLabel('Tên workspace').fill('Workspace Hai')
  await page.getByRole('button', { name: 'Tạo workspace' }).click()
  await page.waitForURL(/\/dashboard$/)
  await page.getByRole('button', { name: 'Chuyển workspace' }).click()
  await page.screenshot({ path: `${shots}/05-switcher.png` })
  await page.getByRole('link', { name: /Workspace E2E/ }).click()
  await page.waitForURL(new RegExp(`/w/${wsId}/dashboard$`))
  step('workspace switcher changes tenant')

  // Cross-tenant: log in as owner of tenant A, try tenant of this user by URL
  await page.getByRole('button', { name: /Người Dùng E2E/ }).click()
  await page.getByRole('button', { name: 'Đăng xuất' }).click()
  await page.waitForURL('**/login')
  await page.getByLabel('Email').fill('other@demo.sitexpost.test')
  await page.getByLabel('Mật khẩu').fill('SiteXDemo2026')
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  await page.waitForURL(/\/dashboard$/)
  step('demo owner of Agency B logged in')
  await page.goto(`${BASE}/w/${wsId}/members`)
  await page.getByText('404').waitFor()
  step('other tenant workspace by URL → 404 page (no data shown)')
  await page.screenshot({ path: `${shots}/06-cross-tenant-404.png` })

  // Viewer: no invite button, no settings edit
  await page.goto(BASE + '/')
  await page.getByRole('button', { name: /Owner B|other|Agency/ }).first().click().catch(() => {})
  await page.context().clearCookies()
  await page.goto(BASE + '/login')
  await page.getByLabel('Email').fill('viewer@demo.sitexpost.test')
  await page.getByLabel('Mật khẩu').fill('SiteXDemo2026')
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  await page.waitForURL(/\/dashboard$/)
  await page.getByRole('link', { name: 'Thành viên' }).first().click()
  await page.getByText('owner@demo.sitexpost.test').waitFor()
  if (await page.getByRole('button', { name: 'Mời thành viên' }).count()) throw new Error('viewer sees invite button')
  step('VIEWER sees members, no invite/manage controls')
  await page.screenshot({ path: `${shots}/07-viewer-members.png` })

  await page.setViewportSize({ width: 390, height: 844 })
  await page.screenshot({ path: `${shots}/08-mobile.png` })

  console.log(errors.length ? 'console errors:\n' + errors.join('\n') : 'no console errors')
  await browser.close()
})().catch((e) => {
  console.error('✗', e.message)
  process.exit(1)
})
