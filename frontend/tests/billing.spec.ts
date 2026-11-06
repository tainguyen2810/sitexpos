import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import viMessages from '@/locales/vi'
import { FEATURES, formatCredits, formatMicroUsd, formatMoney, idempotencyKey, ORDER_STATUSES, RESOURCES, statusTone, SUBSCRIPTION_STATUSES } from '@/shared/api/billing'
import { toInt } from '@/apps/admin/pages/billing/useAdminBilling'
import QrCode from '@/apps/customer/pages/billing/QrCode.vue'
import { BILLING_NAV } from '@/apps/customer/pages/billing/useBilling'

const vi = viMessages as unknown as { bill: Record<string, Record<string, unknown>>; billAdmin: Record<string, Record<string, unknown>> }

describe('billing formatting (integers only, no float maths on stored values)', () => {
  it('formats VND minor units and milli-credits', () => {
    expect(formatMoney(300000, 'VND').replace(/\s/g, ' ')).toMatch(/300\.000/)
    expect(formatMoney(null)).toBe('—')
    expect(formatCredits(1500)).toBe('1,5')
    expect(formatCredits(100000)).toBe('100')
    expect(formatMicroUsd(12_345)).toBe('$0.0123')
  })

  it('maps statuses to tones', () => {
    expect(statusTone('ACTIVE')).toBe('success')
    expect(statusTone('GRACE_PERIOD')).toBe('warning')
    expect(statusTone('SUSPENDED')).toBe('danger')
    expect(statusTone('EXPIRED')).toBe('neutral')
  })

  it('creates unique idempotency keys within the API limit', () => {
    const a = idempotencyKey()
    expect(a.length).toBeLessThanOrEqual(100)
    expect(idempotencyKey()).not.toBe(a)
  })

  it('parses admin integer inputs strictly', () => {
    expect(toInt('300.000')).toBe(300000)
    expect(toInt('-80000')).toBe(-80000)
    expect(toInt('')).toBeNull()
    expect(toInt('abc')).toBeNull()
  })
})

describe('VietQR rendering', () => {
  it('renders the payload as an inline SVG QR code (no v-html, no network)', () => {
    const w = mount(QrCode, { props: { payload: '00020101021238540010A00000072701240006970436011001234567890208QRIBFTTA530370454063000005802VN62150811SXPABCD23456304ABCD', label: 'QR' } })
    const svg = w.get('[data-testid="vietqr"]')
    expect(svg.attributes('aria-label')).toBe('QR')
    expect(w.findAll('rect').length).toBeGreaterThan(50)
    expect(w.html()).not.toContain('<img')
  })
})

describe('billing locale coverage', () => {
  it('has a Vietnamese label for every status, resource, feature and nav item', () => {
    for (const s of SUBSCRIPTION_STATUSES) expect(vi.bill.status![s]).toBeTruthy()
    for (const s of ORDER_STATUSES) expect(vi.bill.orderStatus![s]).toBeTruthy()
    for (const r of RESOURCES) expect(vi.bill.resources![r]).toBeTruthy()
    for (const f of FEATURES) expect(vi.bill.features![f]).toBeTruthy()
    for (const n of BILLING_NAV) expect(vi.bill.nav![n.key]).toBeTruthy()
  })

  it('labels receipts as internal documents, not VAT invoices', () => {
    expect(String(vi.bill.common!.internalDoc)).toContain('không phải hóa đơn GTGT')
  })
})
