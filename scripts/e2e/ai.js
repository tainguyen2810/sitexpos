// Phase 3 smoke test (dev only): customer sends an AI test task through the
// real queue (fake provider) and sees tokens/cost; super admin sees cost
// analytics, AI settings and the job monitor. Needs DemoSeeder data, the AI
// registry (`php artisan sitex:ai:sync-registry`) and a queue worker on `writing`.
const { chromium } = require('playwright')
const crypto = require('crypto')
const APP = process.env.SITEX_APP_URL ?? 'http://127.0.0.1:5173'
const ADMIN = process.env.SITEX_ADMIN_URL ?? 'http://127.0.0.1:5174'
const shots = process.argv[2] ?? require('os').tmpdir()
const step = (m) => console.log('✓', m)

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

;(async () => {
  const browser = await chromium.launch()
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 })
  const page = await context.newPage()

  await page.goto(APP + '/login')
  await page.getByLabel('Email').fill('owner@demo.sitexpost.test')
  await page.getByLabel('Mật khẩu').fill('SiteXDemo2026')
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  await page.getByRole('link', { name: 'Tác vụ AI' }).first().click()
  await page.getByRole('heading', { name: 'Tác vụ AI', level: 1 }).waitFor()
  step('customer opens AI tasks')

  await page.getByLabel('Nội dung yêu cầu').fill('Viết 3 ý tưởng bài blog cho cửa hàng hoa tươi ở Đà Nẵng')
  await page.getByText('Yêu cầu kết quả JSON có cấu trúc').click()
  const started = Date.now()
  await page.getByRole('button', { name: 'Gửi vào hàng đợi' }).click()
  await page.getByRole('dialog').waitFor()
  const acceptedMs = Date.now() - started
  step(`task accepted in ${acceptedMs} ms (queued, not run in the request)`)
  await page.getByRole('dialog').locator('[data-status=succeeded]').waitFor({ timeout: 30000 })
  await page.getByRole('dialog').getByTestId('run-usage').waitFor()
  await page.screenshot({ path: `${shots}/20-ai-task-detail.png` })
  step('task finished on the worker; result, tokens and cost shown')
  await page.getByRole('dialog').getByText('Đóng', { exact: true }).click()
  await page.getByTestId('ai-usage').getByText('Lượt gọi').waitFor()
  await page.screenshot({ path: `${shots}/21-ai-tasks.png`, fullPage: true })
  step('task list and usage card')

  // Super admin (enable 2FA on the freshly seeded account).
  // Separate context: cookies are shared across ports on the same host.
  const adminContext = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 })
  const admin = await adminContext.newPage()
  await admin.goto(ADMIN + '/login')
  await admin.getByLabel('Email').fill('superadmin@demo.sitexpost.test')
  await admin.getByLabel('Mật khẩu').fill('SiteXDemo2026')
  await admin.getByRole('button', { name: 'Đăng nhập' }).click()
  await admin.waitForURL('**/setup-2fa')
  await admin.getByRole('button', { name: 'Bật 2FA' }).click()
  await admin.getByLabel('Nhập mật khẩu để tiếp tục').fill('SiteXDemo2026')
  await admin.getByRole('button', { name: 'Xác nhận' }).click()
  const secret = (await admin.getByText('Hoặc nhập khóa thủ công').textContent()).split(':').pop().trim()
  await admin.getByLabel('Mã xác thực').fill(totp(secret))
  await admin.getByRole('button', { name: 'Xác nhận' }).click()
  await admin.getByRole('button', { name: 'Tiếp tục' }).click()

  await admin.getByRole('link', { name: 'Chi phí AI' }).click()
  await admin.getByTestId('usage-totals').waitFor()
  await admin.getByTestId('request-log').waitFor()
  await admin.screenshot({ path: `${shots}/22-admin-ai-usage.png`, fullPage: true })
  step('admin AI cost analytics + request log')

  await admin.getByRole('link', { name: 'Cấu hình AI' }).click()
  await admin.getByTestId('provider-list').waitFor()
  await admin.screenshot({ path: `${shots}/23-admin-ai-providers.png`, fullPage: true })
  await admin.getByRole('tab', { name: 'Mô hình & giá' }).click()
  await admin.getByTestId('model-list').waitFor()
  await admin.screenshot({ path: `${shots}/24-admin-ai-models.png`, fullPage: true })
  await admin.getByRole('tab', { name: 'Định tuyến' }).click()
  await admin.getByTestId('route-list').waitFor()
  await admin.screenshot({ path: `${shots}/25-admin-ai-routes.png`, fullPage: true })
  step('admin AI settings: providers, models & prices, routing')

  await admin.getByRole('link', { name: 'Hàng đợi & job' }).click()
  await admin.getByTestId('jobs-overview').waitFor()
  await admin.getByTestId('workflow-list').waitFor()
  await admin.screenshot({ path: `${shots}/26-admin-jobs.png`, fullPage: true })
  step('admin job monitor')

  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await page.getByRole('heading', { name: 'Tác vụ AI', level: 1 }).waitFor()
  await page.screenshot({ path: `${shots}/27-ai-tasks-mobile.png`, fullPage: true })
  step('customer AI page on a phone')

  await browser.close()
})().catch((e) => {
  console.error('✗', e.message)
  process.exit(1)
})
