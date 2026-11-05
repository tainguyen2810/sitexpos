const { chromium } = require('playwright')
const crypto = require('crypto')
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
  const context = await browser.newContext({ viewport: { width: 1280, height: 860 } })
  const page = await context.newPage()

  // Non-staff cannot use the admin app.
  await page.goto(ADMIN + '/login')
  await page.getByLabel('Email').fill('owner@demo.sitexpost.test')
  await page.getByLabel('Mật khẩu').fill('SiteXDemo2026')
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  await page.getByText('không có quyền quản trị nền tảng').waitFor()
  step('tenant OWNER is refused by the admin app')
  await page.getByRole('button', { name: 'Đăng xuất' }).click()
  await page.waitForURL('**/login')

  await page.getByLabel('Email').fill('superadmin@demo.sitexpost.test')
  await page.getByLabel('Mật khẩu').fill('SiteXDemo2026')
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  await page.waitForURL('**/setup-2fa')
  step('SUPER_ADMIN without 2FA is forced to set it up')
  await page.getByRole('button', { name: 'Bật 2FA' }).click()
  await page.getByLabel('Nhập mật khẩu để tiếp tục').fill('SiteXDemo2026')
  await page.getByRole('button', { name: 'Xác nhận' }).click()
  const secretText = await page.getByText('Hoặc nhập khóa thủ công').textContent()
  const secret = secretText.split(':').pop().trim()
  await page.getByLabel('Mã xác thực').fill(totp(secret))
  await page.getByRole('button', { name: 'Xác nhận' }).click()
  await page.getByText('Mã khôi phục', { exact: true }).waitFor()
  await page.screenshot({ path: `${shots}/10-admin-2fa-recovery.png` })
  step('2FA enabled, recovery codes shown')
  await page.getByRole('button', { name: 'Tiếp tục' }).click()
  await page.getByText('Tổng người dùng').waitFor()
  await page.screenshot({ path: `${shots}/11-admin-dashboard.png` })
  step('admin dashboard with real counts')

  await page.getByRole('link', { name: 'Workspace' }).first().click()
  await page.getByRole('link', { name: 'Cửa hàng A' }).click()
  await page.getByText('owner@demo.sitexpost.test').waitFor()
  await page.screenshot({ path: `${shots}/12-admin-workspace.png` })
  step('workspace detail with members')

  await page.getByRole('button', { name: 'Xem với tư cách hỗ trợ' }).click()
  await page.getByLabel('Lý do (bắt buộc, được ghi vào nhật ký)').fill('Khách hàng yêu cầu hỗ trợ ticket #1')
  const [popup] = await Promise.all([context.waitForEvent('page'), page.getByRole('dialog').getByRole('button', { name: 'Xem với tư cách hỗ trợ' }).click()])
  await popup.waitForLoadState()
  await popup.getByText('chế độ hỗ trợ (chỉ xem)').waitFor()
  await popup.getByRole('link', { name: 'Thành viên' }).first().click()
  await popup.getByText('viewer@demo.sitexpost.test').waitFor()
  if (await popup.getByRole('button', { name: 'Mời thành viên' }).count()) throw new Error('impersonation shows invite')
  await popup.screenshot({ path: `${shots}/13-impersonation.png` })
  step('impersonation opens customer app read-only with banner')
  await popup.getByRole('button', { name: 'Kết thúc phiên hỗ trợ' }).click()
  await popup.waitForEvent('close').catch(() => {})
  step('impersonation ended')

  await page.goto(ADMIN + '/audit')
  await page.getByText('platform.impersonation.started').first().waitFor()
  await page.screenshot({ path: `${shots}/14-admin-audit.png` })
  step('impersonation recorded in platform audit log')

  await browser.close()
})().catch((e) => {
  console.error('✗', e.message)
  process.exit(1)
})
