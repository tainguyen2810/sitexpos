import { api } from './client'
import type { Envelope, Paginated } from './types'

/**
 * Phase 7 — customer billing. Money is integer minor units (`*_minor`),
 * credits are integer milli-credits (`*_mc`, 1 credit = 1000 mc). The UI
 * never sends a payment status: only the backend (verified webhook or staff
 * review) marks an order PAID.
 */
export const SUBSCRIPTION_STATUSES = ['TRIALING', 'ACTIVE', 'PAST_DUE', 'GRACE_PERIOD', 'PAUSED', 'CANCELLED', 'EXPIRED', 'SUSPENDED'] as const
export type SubscriptionStatus = (typeof SUBSCRIPTION_STATUSES)[number]
export const ORDER_STATUSES = ['PENDING', 'PROCESSING', 'PAID', 'EXPIRED', 'CANCELLED', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED'] as const
export type OrderStatus = (typeof ORDER_STATUSES)[number]
export type Interval = 'month' | 'year'
export const RESOURCES = ['websites', 'members', 'research_runs', 'keywords', 'articles', 'ai_images', 'publishing_jobs', 'storage_mb', 'automation_schedules'] as const
export type Resource = (typeof RESOURCES)[number]
export const FEATURES = ['ai_writer', 'ai_images', 'research', 'publishing', 'automation', 'auto_publish', 'priority_support'] as const

export interface Entitlement {
  limit: number
  period: 'month' | 'total'
}

export interface PlanVersion {
  id: string
  plan_id: string
  version: number
  status: 'draft' | 'published' | 'retired'
  currency: string
  monthly_price_minor: number | null
  yearly_price_minor: number | null
  trial_days: number
  trial_credits_mc: number
  included_credits_mc: number
  support_level: string | null
  features: Record<string, boolean>
  feature_flags: Record<string, boolean>
  entitlements: Partial<Record<Resource, Entitlement>>
  notes: string | null
  published_at: string | null
  created_at: string | null
}

export interface PlanView {
  id: string
  code: string
  name: string
  description: string | null
  is_free: boolean
  is_demo: boolean
  display_order: number
  current_version: PlanVersion | null
  trial?: { eligible: boolean; reason: string | null }
  status?: string
  current_version_id?: string | null
}

export interface CreditPackage {
  id: string
  code: string
  name: string
  credits_mc: number
  bonus_credits_mc: number
  price_minor: number
  currency: string
  validity_days: number | null
  is_demo: boolean
  status?: string
  display_order?: number
}

export interface PublicProvider {
  code: string
  name: string
  adapter: string
}

export interface Subscription {
  id: string
  status: SubscriptionStatus
  interval: Interval
  price_minor: number
  currency: string
  plan: { id: string; code: string; name: string; is_free: boolean; is_demo: boolean } | null
  plan_version: { id: string; version: number; included_credits_mc: number } | null
  started_at: string | null
  current_period_start: string | null
  current_period_end: string | null
  trial_ends_at: string | null
  grace_ends_at: string | null
  cancelled_at: string | null
  ended_at: string | null
  renewal_reminders: boolean
  workspace_id: string
}

export interface Quote {
  kind: 'upgrade' | 'downgrade'
  amount_minor: number
  currency: string
  effective: 'immediate' | 'period_end'
  effective_at: string | null
  new_period_end: string | null
  proration: { charge_minor: number; remaining_days: number; credit_for_unused_minor: number; new_price_minor: number } | null
  credits_policy: string
}

export interface SubscriptionChange {
  id: string
  kind: 'upgrade' | 'downgrade'
  status: 'pending_payment' | 'scheduled' | 'applied' | 'cancelled'
  to_plan: { code: string; name: string } | null
  to_interval: Interval
  effective_at: string | null
  amount_minor: number
  currency: string
  quote: Quote | null
  payment_order_id: string | null
  applied_at: string | null
}

export interface SubscriptionEvent {
  id: number
  event: string
  from_status: string | null
  to_status: string | null
  actor_type: string | null
  reason: string | null
  data: Record<string, unknown> | null
  payment_order_id: string | null
  created_at: string | null
}

export interface WalletSummary {
  balance_mc: number
  held_mc: number
  available_mc: number
  overdraft_limit_mc: number
}

export interface Effective {
  source: 'subscription' | 'default_plan' | 'none'
  enforcing: boolean
  applies: boolean
  status: SubscriptionStatus | null
  plan_code: string | null
  plan_name: string | null
  limits: Partial<Record<Resource, Entitlement>>
  features: Record<string, boolean>
  paid_usage_allowed: boolean
  usage: Partial<Record<Resource, number>>
}

export interface BillingOverview {
  subscription: Subscription | null
  last_subscription: Subscription | null
  pending_change: SubscriptionChange | null
  entitlements: Effective
  wallet: WalletSummary
  history: SubscriptionEvent[]
  grace_allows_ai: boolean
}

export interface PlansView {
  plans: PlanView[]
  credit_packages: CreditPackage[]
  providers: PublicProvider[]
  currency: string
  proration_enabled: boolean
}

export interface PaymentInstructions {
  type: 'bank_transfer'
  configured: boolean
  bank_name: string | null
  bank_bin: string | null
  account_no: string | null
  account_name: string | null
  amount_minor: number
  currency: string
  memo: string
  qr_payload: string | null
  confirmation: 'manual_review' | 'automatic'
}

export interface PaymentOrder {
  id: string
  purpose: 'subscription' | 'renewal' | 'upgrade' | 'credit_package'
  description: string | null
  amount_minor: number
  currency: string
  provider: string | null
  reference: string
  status: OrderStatus
  paid_amount_minor: number
  refunded_amount_minor: number
  interval: Interval | null
  snapshot: Record<string, unknown> | null
  subscription_id: string | null
  workspace_id: string
  expires_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  fulfilled_at: string | null
  created_at: string | null
  instructions?: PaymentInstructions | null
}

export interface CreditGrant {
  id: string
  source: string
  amount_mc: number
  remaining_mc: number
  expires_at: string | null
  expired_at: string | null
  created_at: string | null
}

export interface LedgerEntry {
  id: number
  entry_type: 'grant' | 'consume' | 'expire' | 'refund' | 'adjust' | 'reversal'
  amount_mc: number
  balance_after_mc: number
  reference_type: string | null
  reference_id: string | null
  description: string | null
  grant_id: string | null
  created_at: string | null
}

export interface BillingDocument {
  id: string
  type: 'receipt' | 'refund_record'
  number: string
  amount_minor: number
  currency: string
  payment_order_id: string | null
  refund_id: string | null
  snapshot: Record<string, unknown> | null
  is_tax_invoice: false
  issued_at: string | null
}

export interface BillingProfile {
  company_name: string | null
  tax_code: string | null
  address: string | null
  contact_name: string | null
  email: string | null
  phone: string | null
}

export interface UsageView {
  period_start: string
  by_category: { category: string; calls: number; charged_mc: number; uncertain_calls: number }[]
  daily: { day: string; charged_mc: number }[]
  entitlements: Partial<Record<Resource, Entitlement>>
  usage: Partial<Record<Resource, number>>
}

const W = (ws: string) => `/api/v1/workspaces/${encodeURIComponent(ws)}/billing`
const unwrap = <T>(r: Envelope<T>) => r.data

export const billingApi = {
  overview: (ws: string) => api.get<Envelope<BillingOverview>>(`${W(ws)}/subscription`).then(unwrap),
  plans: (ws: string) => api.get<Envelope<PlansView>>(`${W(ws)}/plans`).then(unwrap),
  history: (ws: string, cursor?: string | null) => api.get<Paginated<SubscriptionEvent>>(`${W(ws)}/subscription/history`, { query: { cursor } }),
  trial: (ws: string, planCode: string) => api.post<Envelope<Subscription>>(`${W(ws)}/subscription/trial`, { plan_code: planCode }).then(unwrap),
  subscribeFree: (ws: string, planCode: string) => api.post<Envelope<Subscription>>(`${W(ws)}/subscription/free`, { plan_code: planCode }).then(unwrap),
  checkout: (ws: string, body: { plan_code: string; interval: Interval; provider?: string | null; idempotency_key?: string }) =>
    api.post<Envelope<PaymentOrder>>(`${W(ws)}/subscription/checkout`, body).then(unwrap),
  quote: (ws: string, planCode: string, interval: Interval) => api.post<Envelope<Quote>>(`${W(ws)}/subscription/quote`, { plan_code: planCode, interval }).then(unwrap),
  change: (ws: string, body: { plan_code: string; interval: Interval; provider?: string | null }) =>
    api.post<Envelope<{ change: SubscriptionChange; order: PaymentOrder | null }>>(`${W(ws)}/subscription/change`, body).then(unwrap),
  cancelChange: (ws: string) => api.delete<void>(`${W(ws)}/subscription/change`),
  cancel: (ws: string, reason?: string) => api.post<Envelope<Subscription>>(`${W(ws)}/subscription/cancel`, { reason: reason || null }).then(unwrap),
  resume: (ws: string) => api.post<Envelope<Subscription>>(`${W(ws)}/subscription/resume`).then(unwrap),
  renew: (ws: string, body: { provider?: string | null; idempotency_key?: string } = {}) => api.post<Envelope<PaymentOrder>>(`${W(ws)}/subscription/renew`, body).then(unwrap),
  settings: (ws: string, renewalReminders: boolean) => api.patch<Envelope<Subscription>>(`${W(ws)}/subscription/settings`, { renewal_reminders: renewalReminders }).then(unwrap),
  usage: (ws: string) => api.get<Envelope<UsageView>>(`${W(ws)}/usage`).then(unwrap),

  wallet: (ws: string) => api.get<Envelope<{ wallet: WalletSummary; grants: CreditGrant[] }>>(`${W(ws)}/credits`).then(unwrap),
  transactions: (ws: string, cursor?: string | null, type?: string) => api.get<Paginated<LedgerEntry>>(`${W(ws)}/credits/transactions`, { query: { cursor, type: type || undefined } }),
  packages: (ws: string) => api.get<Envelope<CreditPackage[]>>(`${W(ws)}/credits/packages`).then(unwrap),
  buyCredits: (ws: string, body: { package_code: string; provider?: string | null; idempotency_key?: string }) =>
    api.post<Envelope<PaymentOrder>>(`${W(ws)}/credits/purchase`, body).then(unwrap),

  orders: (ws: string, cursor?: string | null, status?: string) => api.get<Paginated<PaymentOrder>>(`${W(ws)}/orders`, { query: { cursor, status: status || undefined } }),
  order: (ws: string, id: string) => api.get<Envelope<PaymentOrder>>(`${W(ws)}/orders/${encodeURIComponent(id)}`).then(unwrap),
  transferred: (ws: string, id: string) => api.post<Envelope<PaymentOrder>>(`${W(ws)}/orders/${encodeURIComponent(id)}/transferred`).then(unwrap),
  cancelOrder: (ws: string, id: string) => api.post<Envelope<PaymentOrder>>(`${W(ws)}/orders/${encodeURIComponent(id)}/cancel`).then(unwrap),
  documents: (ws: string, cursor?: string | null) => api.get<Paginated<BillingDocument>>(`${W(ws)}/documents`, { query: { cursor } }),
  document: (ws: string, id: string) => api.get<Envelope<BillingDocument>>(`${W(ws)}/documents/${encodeURIComponent(id)}`).then(unwrap),
  profile: (ws: string) => api.get<Envelope<BillingProfile>>(`${W(ws)}/profile`).then(unwrap),
  updateProfile: (ws: string, body: BillingProfile) => api.put<Envelope<BillingProfile>>(`${W(ws)}/profile`, body).then(unwrap),
}

/** A fresh idempotency key per user action (a double click reuses the same key). */
export function idempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

/** Integer money → localized string, no float maths on stored values. */
export function formatMoney(minor: number | null | undefined, currency = 'VND'): string {
  if (minor === null || minor === undefined) return '—'
  const digits = currency === 'VND' ? 0 : 2
  const value = currency === 'VND' ? minor : minor / 100
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency, minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value)
}

/** milli-credits → "1.234,5" credits (display only). */
export function formatCredits(mc: number | null | undefined): string {
  if (mc === null || mc === undefined) return '—'
  return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 }).format(mc / 1000)
}

/** µUSD → "$0.0123" (display only). */
export function formatMicroUsd(micro: number | null | undefined): string {
  if (micro === null || micro === undefined) return '—'
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(micro / 1_000_000)
}

export function statusTone(status: string): 'neutral' | 'brand' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'ACTIVE':
    case 'PAID':
    case 'TRIALING':
    case 'matched':
    case 'succeeded':
      return 'success'
    case 'PAST_DUE':
    case 'GRACE_PERIOD':
    case 'PROCESSING':
    case 'PENDING':
    case 'PARTIALLY_REFUNDED':
    case 'mismatch':
    case 'requested':
      return 'warning'
    case 'SUSPENDED':
    case 'FAILED':
    case 'failed':
      return 'danger'
    case 'CANCELLED':
    case 'EXPIRED':
    case 'REFUNDED':
    case 'PAUSED':
      return 'neutral'
    default:
      return 'brand'
  }
}
