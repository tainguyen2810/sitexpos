// Phase 3E smoke test (dev only): Super Admin manages AI providers/models in
// the real UI — DeepSeek key (encrypted, write-only), Test Connection, a custom
// OpenAI-compatible provider (mock server), model with time-of-day price,
// Test Model, Model ID edit. Needs: API with SITEX_SSRF_ALLOW_HOSTS=127.0.0.1 +
// SITEX_SSRF_ALLOW_HTTP=true (dev only), `node scripts/e2e/mock-openai-compatible.js`,
// the admin Vite dev server, DemoSeeder data.
const { chromium } = require('playwright')
const crypto = require('crypto')
const ADMIN = process.env.SITEX_ADMIN_URL ?? 'http://127.0.0.1:5174'
const MOCK = process.env.SITEX_MOCK_URL ?? 'http://127.0.0.1:8099/v1'
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

let failPage = null
let failBodies = []
;(async () => {
  const browser = await chromium.launch()
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 })
  const admin = await context.newPage()
  const apiBodies = []
  failPage = admin
  failBodies = apiBodies
  admin.on('response', async (r) => { if (r.url().includes('/api/admin/v1/ai/')) apiBodies.push(await r.text().catch(() => '')) })

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

  await admin.getByRole('link', { name: 'Cấu hình AI' }).click()
  const list = admin.getByTestId('provider-list')
  await list.waitFor()
  const deepseek = list.locator('li', { hasText: 'DeepSeek' })
  await deepseek.waitFor()
  step('DeepSeek provider listed')

  // 1. DeepSeek API key: write-only, stored encrypted, only last 4 shown.
  await deepseek.getByRole('button', { name: /khóa API/ }).click()
  await admin.getByRole('dialog').getByLabel('API key').fill('sk-e2e-deepseek-not-real-7788')
  await admin.getByRole('dialog').getByRole('button', { name: 'Lưu thay đổi' }).click()
  await deepseek.getByText('Khóa lưu trên hệ thống (…7788)').waitFor()
  step('DeepSeek key saved; UI shows only …7788')

  // 2. Test Connection to the real DeepSeek API (this sandbox has no route to it → an honest error).
  await deepseek.getByRole('button', { name: 'Kiểm tra kết nối' }).click()
  const conn = admin.getByTestId('conn-result')
  await conn.waitFor({ timeout: 30000 })
  console.log('  DeepSeek connection result:', (await conn.innerText()).replace(/\s+/g, ' '))
  await admin.screenshot({ path: `${shots}/30-deepseek-connection.png` })
  await admin.getByRole('dialog').getByText('Đóng', { exact: true }).click()
  step('Test Connection result shown')

  // 3. Custom OpenAI-compatible provider (mock server).
  await admin.getByRole('button', { name: 'Thêm provider OpenAI-compatible' }).click()
  const d = admin.getByRole('dialog')
  await d.getByLabel('Tên hiển thị').fill('Mock LLM')
  await d.getByLabel('Mã (chữ thường, không dấu, vd. together)').fill('mockllm')
  await d.getByLabel('Base URL').fill(MOCK)
  await d.getByLabel('API key').fill('sk-mock-e2e-key-0001')
  await d.getByLabel('Cách trả JSON').selectOption('json_schema')
  await d.getByRole('button', { name: 'Xác nhận' }).click()
  const mock = list.locator('li', { hasText: 'Mock LLM' })
  await mock.waitFor()
  await mock.getByRole('button', { name: 'Kiểm tra kết nối' }).click()
  await conn.getByText('Kết nối được').waitFor({ timeout: 30000 })
  await conn.getByText('Nhà cung cấp trả về 2 mô hình.').waitFor()
  await admin.getByRole('dialog').getByText('Đóng', { exact: true }).click()
  await mock.getByRole('switch').click()
  await mock.getByText('Đang bật', { exact: true }).waitFor()
  await admin.screenshot({ path: `${shots}/31-custom-provider.png`, fullPage: true })
  step('custom provider added, connection OK (2 models), enabled')

  // 4. Model on the custom provider + time-of-day price.
  await admin.getByRole('tab', { name: 'Mô hình & giá' }).click()
  await admin.getByRole('button', { name: 'Thêm mô hình' }).click()
  const md = admin.getByRole('dialog')
  await md.getByLabel('Nhà cung cấp').selectOption({ label: 'Mock LLM' })
  await md.getByLabel('Model ID (đúng như tài liệu API)').fill('mock-mini')
  await md.getByLabel('Tên hiển thị').fill('Mock Mini')
  await md.getByRole('button', { name: 'Xác nhận' }).click()
  const row = admin.getByTestId('model-list').locator('tr', { hasText: 'mockllm/mock-mini' })
  await row.waitFor()
  await row.getByRole('button', { name: 'Thêm giá' }).click()
  const pd = admin.getByRole('dialog')
  await pd.getByLabel('Giá input ($ / 1M token)', { exact: true }).fill('0.30')
  await pd.getByLabel('Giá output ($ / 1M token)', { exact: true }).fill('1.20')
  await pd.getByTestId('time-of-day').check()
  await pd.getByLabel('Giá input thấp điểm ($ / 1M token)').fill('0.15')
  await pd.getByLabel('Giá output thấp điểm ($ / 1M token)').fill('0.60')
  await pd.getByTestId('peak-windows').fill('1-5 01:00-04:00\n1-5 06:00-10:00')
  await pd.getByRole('button', { name: 'Thêm phiên bản giá' }).click()
  await pd.getByText('Thấp điểm: $0.15 / $0.60').first().waitFor()
  await admin.screenshot({ path: `${shots}/32-time-of-day-price.png` })
  await pd.getByText('Đóng', { exact: true }).click()
  step('model added with peak/off-peak price version')

  // 5. Test Model (real call to the mock provider, through the gateway).
  await row.getByRole('button', { name: 'Thử', exact: true }).click()
  const td = admin.getByRole('dialog')
  await td.getByText('Yêu cầu kết quả JSON').click()
  await td.getByRole('button', { name: 'Chạy thử' }).click()
  const result = td.getByTestId('model-test-result')
  await result.getByText('Thành công').waitFor({ timeout: 30000 })
  console.log('  Test model result:', (await result.innerText()).replace(/\s+/g, ' '))
  await admin.screenshot({ path: `${shots}/33-test-model.png` })
  await td.getByText('Đóng', { exact: true }).click()
  step('Test Model succeeded with JSON, tokens and cost')

  // 6. Edit the Model ID.
  await row.getByRole('button', { name: 'Thêm thao tác' }).click()
  await row.getByRole('menuitem', { name: 'Sửa' }).click()
  const ed = admin.getByRole('dialog')
  await ed.getByLabel('Model ID (đúng như tài liệu API)').fill('mock-large')
  await ed.getByRole('button', { name: 'Lưu thay đổi' }).click()
  await admin.getByTestId('model-list').getByText('mockllm/mock-large').waitFor()
  await admin.screenshot({ path: `${shots}/34-models.png`, fullPage: true })
  step('Model ID changed to mock-large')

  const leaked = apiBodies.filter((b) => b.includes('sk-e2e-deepseek-not-real') || b.includes('sk-mock-e2e-key'))
  if (leaked.length) throw new Error(`API responses leaked a key (${leaked.length})`)
  step(`no API key in ${apiBodies.length} admin API responses`)

  await admin.setViewportSize({ width: 390, height: 844 })
  await admin.getByRole('tab', { name: 'Nhà cung cấp' }).click()
  await admin.screenshot({ path: `${shots}/35-providers-mobile.png`, fullPage: true })
  step('providers tab on a phone')

  await browser.close()
})().catch(async (e) => {
  console.error('✗', e.message)
  console.error('last API responses:', failBodies.slice(-3).map((b) => b.slice(0, 600)).join('\n'))
  if (failPage) await failPage.screenshot({ path: `${shots}/99-failure.png`, fullPage: true }).catch(() => {})
  process.exit(1)
})
