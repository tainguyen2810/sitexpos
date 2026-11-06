import { api } from './client'
import type { Envelope, Paginated } from './types'
import type { CreditPackage, PaymentOrder, PlanVersion, PlanView, Subscription, SubscriptionEvent, WalletSummary } from './billing'

/** Phase 7 — Super Admin billing (`platform.billing.manage`). Every write is audited server-side. */
const A = '/api/admin/v1/billing'
const unwrap = <T>(r: Envelope<T>) => r.data
type Q = Record<string, string | number | boolean | null | undefined>

export interface CreditPolicy {
  id: string
  version: number
  micro_usd_per_credit: number
  markup_bps: number
  min_charge_mc: number
  effective_from: string | null
  notes: string | null
  created_at: string | null
}

export interface AdminSubscriptionRow {
  id: string
  workspace_id: string
  workspace_name: string
  plan_code: string
  plan_name: string
  status: string
  interval: string
  price_minor: number
  currency: string
  current_period_end: string | null
  trial_ends_at: string | null
  grace_ends_at: string | null
  is_live: boolean
  created_at: string | null
}

export interface AdminOrderRow {
  id: string
  workspace_id: string
  workspace_name: string
  purpose: string
  description: string | null
  amount_minor: number
  currency: string
  provider: string | null
  reference: string
  status: string
  paid_amount_minor: number
  refunded_amount_minor: number
  expires_at: string | null
  paid_at: string | null
  created_at: string | null
}

export interface PaymentTransaction {
  id: string
  provider: string
  external_id: string
  direction: 'in' | 'out'
  amount_minor: number
  currency: string
  description: string | null
  counterparty: string | null
  occurred_at: string | null
  match_status: 'unmatched' | 'matched' | 'mismatch' | 'ignored' | 'rejected'
  match_reason: string | null
  payment_order_id: string | null
  workspace_id: string | null
  created_at: string | null
}

export interface RefundRow {
  id: string
  workspace_id: string
  payment_order_id: string
  amount_minor: number
  currency: string
  status: 'requested' | 'processing' | 'succeeded' | 'failed'
  reason: string | null
  credits_reversed_mc: number
  external_ref: string | null
  failure_reason: string | null
  created_at: string | null
  processed_at: string | null
}

export interface AdminOrderDetail extends PaymentOrder {
  refunds: RefundRow[]
  transactions: PaymentTransaction[]
  reconciliations: { id: number; result: string; diff_minor: number; note: string | null; created_at: string }[]
}

export interface AdjustmentRow {
  id: string
  workspace_id: string
  amount_mc: number
  kind: string
  reason: string
  expires_at: string | null
  ledger_entry_id: number | null
  created_by: string | null
  created_at: string | null
}

export interface UncertainRow {
  id: string
  workspace_id: string
  purpose: string
  category: string | null
  status: string
  estimate_micro_usd: number
  actual_micro_usd: number | null
  charged_mc: number | null
  uncollected_mc: number
  created_at: string | null
  settled_at: string | null
}

export interface RevenueReport {
  currency: string
  from: string
  to: string
  cash: { gross_minor: number; discounts_minor: number; refunds_minor: number; net_minor: number; subscription_minor: number; credits_minor: number }
  recognized: { subscription_minor: number; credits_minor: number; total_minor: number }
  deferred_subscription_minor: number
  credit_liability_minor: number
  credits_consumed_mc: number
  costs: CostReport & { payment_fees_minor: number; ai_cost_minor: number | null }
  gross_profit_minor: number | null
  gross_margin_bps: number | null
  exchange_rate: number | null
}

export interface CostReport {
  total_micro_usd: number
  uncertain_micro_usd: number
  by_category: Record<string, number>
  by_model: { provider: string; model: string; cost_micro_usd: number; requests: number }[]
  trend: { day: string; cost_micro_usd: number }[]
}

export interface TenantProfit {
  workspace_id: string
  workspace_name: string | null
  plan_code: string | null
  cash_minor: number
  recognized_minor: number
  ai_cost_micro_usd: number
  ai_cost_minor: number | null
  credits_consumed_mc: number
  gross_profit_minor: number | null
  margin_bps: number | null
  flags: string[]
}

export interface ProfitabilityReport {
  currency: string
  exchange_rate: number | null
  tenants: TenantProfit[]
  alerts: TenantProfit[]
  by_plan: { plan_code: string | null; tenants: number; recognized_minor: number; ai_cost_micro_usd: number }[]
  costs: CostReport
}

export type BillingSettings = Record<string, unknown> & {
  enforcement: 'off' | 'enforce'
  default_plan_code: string | null
  currency: string
  trial_enabled: boolean
  grace_days: number
  grace_allows_ai: boolean
  proration_enabled: boolean
  order_expiry_minutes: number
  renewal_notice_days: number[]
  low_credit_threshold_mc: number
  overpayment_policy: 'manual_review' | 'accept_and_credit_difference'
  usd_to_currency_rate: number | null
}

export interface ProviderConfig {
  id: string
  code: string
  adapter: string
  name: string
  is_enabled: boolean
  settings: Record<string, unknown>
  credentials_set: string[]
  capabilities: Record<string, boolean>
  required: { settings: string[]; credentials: string[] }
  display_order: number
  webhook_url: string | null
  updated_at: string | null
}

export type VersionInput = Partial<Pick<PlanVersion, 'currency' | 'monthly_price_minor' | 'yearly_price_minor' | 'trial_days' | 'trial_credits_mc' | 'included_credits_mc' | 'support_level' | 'notes'>> & {
  feature_flags?: Record<string, boolean>
  entitlements?: Record<string, number | null>
}

export const billingAdminApi = {
  plans: () => api.get<Envelope<(PlanView & { versions: PlanVersion[]; live_subscriptions: number })[]>>(`${A}/plans`).then(unwrap),
  createPlan: (body: { code: string; name: string; description?: string | null; is_free?: boolean; display_order?: number }) => api.post<Envelope<PlanView>>(`${A}/plans`, body).then(unwrap),
  updatePlan: (id: string, body: { name?: string; description?: string | null; status?: string; is_free?: boolean; display_order?: number }) =>
    api.patch<Envelope<PlanView>>(`${A}/plans/${id}`, body).then(unwrap),
  deletePlan: (id: string) => api.delete<void>(`${A}/plans/${id}`),
  createVersion: (planId: string, body: VersionInput) => api.post<Envelope<PlanVersion>>(`${A}/plans/${planId}/versions`, body).then(unwrap),
  updateVersion: (id: string, body: VersionInput) => api.patch<Envelope<PlanVersion>>(`${A}/plan-versions/${id}`, body).then(unwrap),
  publishVersion: (id: string) => api.post<Envelope<PlanVersion>>(`${A}/plan-versions/${id}/publish`).then(unwrap),
  packages: () => api.get<Envelope<CreditPackage[]>>(`${A}/credit-packages`).then(unwrap),
  createPackage: (body: Partial<CreditPackage>) => api.post<Envelope<CreditPackage>>(`${A}/credit-packages`, body).then(unwrap),
  updatePackage: (id: string, body: Partial<CreditPackage>) => api.patch<Envelope<CreditPackage>>(`${A}/credit-packages/${id}`, body).then(unwrap),
  policies: () => api.get<Envelope<CreditPolicy[]>>(`${A}/credit-policies`).then(unwrap),
  createPolicy: (body: { micro_usd_per_credit: number; markup_bps: number; min_charge_mc: number; effective_from?: string | null; notes?: string | null }) =>
    api.post<Envelope<CreditPolicy>>(`${A}/credit-policies`, body).then(unwrap),

  subscriptions: (q: Q) => api.get<Paginated<AdminSubscriptionRow>>(`${A}/subscriptions`, { query: q }),
  subscription: (id: string) => api.get<Envelope<Subscription & { events: SubscriptionEvent[] }>>(`${A}/subscriptions/${id}`).then(unwrap),
  subscriptionAction: (id: string, body: { action: string; reason: string; days?: number | null }) =>
    api.post<Envelope<Subscription>>(`${A}/subscriptions/${id}/actions`, body).then(unwrap),
  orders: (q: Q) => api.get<Paginated<AdminOrderRow>>(`${A}/orders`, { query: q }),
  order: (id: string) => api.get<Envelope<AdminOrderDetail>>(`${A}/orders/${id}`).then(unwrap),
  manualPayment: (id: string, body: { bank_reference: string; amount_minor: number; note: string }) => api.post<Envelope<unknown>>(`${A}/orders/${id}/manual-payment`, body),
  cancelOrder: (id: string) => api.post<Envelope<unknown>>(`${A}/orders/${id}/cancel`),
  requestRefund: (id: string, body: { amount_minor: number; reason: string }) => api.post<Envelope<RefundRow>>(`${A}/orders/${id}/refunds`, body).then(unwrap),
  transactions: (q: Q) => api.get<Paginated<PaymentTransaction>>(`${A}/transactions`, { query: q }),
  approveTransaction: (id: string, body: { order_id?: string; note: string }) => api.post<Envelope<unknown>>(`${A}/transactions/${id}/approve`, body),
  rejectTransaction: (id: string, body: { note: string }) => api.post<Envelope<unknown>>(`${A}/transactions/${id}/reject`, body),
  refunds: (q: Q) => api.get<Paginated<RefundRow>>(`${A}/refunds`, { query: q }),
  completeRefund: (id: string, body: { succeeded: boolean; external_ref?: string | null; failure_reason?: string | null }) =>
    api.post<Envelope<RefundRow>>(`${A}/refunds/${id}/complete`, body).then(unwrap),
  wallet: (workspaceId: string) =>
    api.get<Envelope<{ wallet: WalletSummary; entries: { id: number; entry_type: string; amount_mc: number; balance_after_mc: number; description: string | null; created_at: string }[] }>>(`${A}/workspaces/${workspaceId}/wallet`).then(unwrap),
  adjust: (workspaceId: string, body: { amount_mc: number; kind: string; reason: string; expires_at?: string | null }) =>
    api.post<Envelope<{ id: string; wallet: WalletSummary }>>(`${A}/workspaces/${workspaceId}/credit-adjustments`, body).then(unwrap),
  adjustments: (q: Q) => api.get<Paginated<AdjustmentRow>>(`${A}/credit-adjustments`, { query: q }),
  uncertain: (q: Q) => api.get<Paginated<UncertainRow>>(`${A}/uncertain-usage`, { query: q }),

  revenue: (from?: string, to?: string) => api.get<Envelope<RevenueReport>>(`${A}/reports/revenue`, { query: { from, to } }).then(unwrap),
  costs: (from?: string, to?: string) => api.get<Envelope<CostReport>>(`${A}/reports/costs`, { query: { from, to } }).then(unwrap),
  profitability: (from?: string, to?: string) => api.get<Envelope<ProfitabilityReport>>(`${A}/reports/profitability`, { query: { from, to } }).then(unwrap),
  settings: () => api.get<Envelope<{ settings: BillingSettings; credit_policy_configured: boolean; adapters: string[] }>>(`${A}/settings`).then(unwrap),
  updateSettings: (body: Partial<BillingSettings>) => api.put<Envelope<{ settings: BillingSettings }>>(`${A}/settings`, body).then(unwrap),
  providers: () => api.get<Envelope<ProviderConfig[]>>(`${A}/providers`).then(unwrap),
  upsertProvider: (code: string, body: { adapter?: string; name?: string; is_enabled?: boolean; display_order?: number; settings?: Record<string, unknown>; credentials?: Record<string, string> }) =>
    api.put<Envelope<ProviderConfig>>(`${A}/providers/${encodeURIComponent(code)}`, body).then(unwrap),
}
