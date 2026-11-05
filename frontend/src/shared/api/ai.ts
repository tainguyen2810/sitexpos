import { api } from './client'
import type { Envelope, Paginated } from './types'

/** Money is integer micro-USD (1 USD = 1 000 000). */
export type MicroUsd = number

export type WorkflowStatus = 'pending' | 'running' | 'retry_scheduled' | 'succeeded' | 'failed' | 'canceled'
export const ACTIVE_STATUSES: WorkflowStatus[] = ['pending', 'running', 'retry_scheduled']

export interface UsageNumbers {
  requests: number
  succeeded: number
  failed: number
  input_tokens: number
  cached_input_tokens: number
  cache_write_tokens: number
  output_tokens: number
  reasoning_tokens: number
  images: number
  searches: number
  cost_micro_usd: MicroUsd
}

export interface BudgetUsage {
  period_start: string
  spent_micro_usd: MicroUsd
  reserved_micro_usd: MicroUsd
  used_pct: number
  alerted_at: string | null
}

export interface UsageReport {
  period: { from: string; to: string }
  totals: UsageNumbers
  by_day: (UsageNumbers & { date: string })[]
  by_task: (UsageNumbers & { task_key: string })[]
  by_model: (UsageNumbers & { provider: string; model: string })[]
  errors: { error_code: string; count: number }[]
  by_provider?: (UsageNumbers & { provider: string })[]
  by_workspace?: { workspace_id: string; workspace_name: string | null; requests: number; input_tokens: number; output_tokens: number; cost_micro_usd: MicroUsd }[]
  budgets?: ({ period: 'day' | 'month'; limit_micro_usd: MicroUsd; alert_threshold_pct: number; action_on_exceed: BudgetAction } & BudgetUsage)[]
}

export interface WorkflowStep {
  key: string
  status: 'pending' | 'running' | 'succeeded' | 'failed' | 'canceled'
  attempts: number
  error: { code: string; message: string | null } | null
  started_at: string | null
  finished_at: string | null
}

export interface AiTaskOutput {
  text?: string
  json?: Record<string, unknown> | null
  provider?: string
  model?: string
  model_key?: string
  cost_micro_usd?: MicroUsd
  usage?: Partial<Record<'input_tokens' | 'cached_input_tokens' | 'cache_write_tokens' | 'output_tokens' | 'reasoning_tokens' | 'images' | 'searches', number>>
  used_fallback?: boolean
  downgraded?: boolean
  finish_reason?: string | null
}

export interface WorkflowRun {
  id: string
  type: string
  workspace?: { id: string; name: string | null }
  queue: string
  status: WorkflowStatus
  current_step: string | null
  attempts: number
  max_attempts: number
  cycle: number
  cancel_requested: boolean
  next_attempt_at: string | null
  error: { code: string; message: string | null } | null
  input: { prompt?: string; structured?: boolean; web_search?: boolean } & Record<string, unknown>
  output?: AiTaskOutput | null
  job_run_id: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
  steps?: WorkflowStep[]
  events?: { event: string; from: string | null; to: string | null; details: Record<string, unknown> | null; at: string }[]
}

export interface AiTaskCreated {
  workflow_run: WorkflowRun
  job_run: { id: string | null; status: string; type: string }
}

const ws = (id: string) => `/api/v1/workspaces/${id}/ai`

export const aiApi = {
  tasks: (workspaceId: string, query: { status?: string; cursor?: string | null }) => api.get<Paginated<WorkflowRun>>(`${ws(workspaceId)}/tasks`, { query }),
  task: (workspaceId: string, id: string) => api.get<Envelope<WorkflowRun>>(`${ws(workspaceId)}/tasks/${id}`).then((r) => r.data),
  createTask: (workspaceId: string, body: { prompt: string; structured?: boolean; web_search?: boolean }, idempotencyKey: string) =>
    api.post<Envelope<AiTaskCreated>>(`${ws(workspaceId)}/tasks`, body, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data),
  cancelTask: (workspaceId: string, id: string) => api.post<Envelope<WorkflowRun>>(`${ws(workspaceId)}/tasks/${id}/cancel`).then((r) => r.data),
  retryTask: (workspaceId: string, id: string) => api.post<Envelope<WorkflowRun>>(`${ws(workspaceId)}/tasks/${id}/retry`).then((r) => r.data),
  usage: (workspaceId: string, days = 30) => api.get<Envelope<UsageReport>>(`${ws(workspaceId)}/usage`, { query: { days } }).then((r) => r.data),
}

// ---- Admin ---------------------------------------------------------------

export type BudgetAction = 'alert' | 'pause_batch' | 'pause_all'
export type CircuitState = 'closed' | 'open' | 'half_open'

export interface AiProvider {
  id: string
  code: string
  name: string
  is_enabled: boolean
  has_api_key: boolean
  priority: number
  timeout_seconds: number
  max_retries: number
  circuit_failure_threshold: number
  circuit_cooldown_seconds: number
  circuit: { state: CircuitState; failures: number; open_until: string | null }
  models_count: number
  updated_at: string
}

export interface AiPrice {
  id: string
  version: number
  effective_from: string
  state: 'effective' | 'scheduled'
  currency: string
  input_per_mtok: MicroUsd
  cached_input_per_mtok: MicroUsd
  cache_write_per_mtok: MicroUsd
  output_per_mtok: MicroUsd
  long_context_threshold: number | null
  long_input_per_mtok: MicroUsd | null
  long_cached_input_per_mtok: MicroUsd | null
  long_cache_write_per_mtok: MicroUsd | null
  long_output_per_mtok: MicroUsd | null
  per_image: MicroUsd
  per_1k_searches: MicroUsd
  source_url: string | null
  notes: string | null
  created_at: string
}

export type Capability = 'structured_output' | 'web_search' | 'image_generation' | 'temperature' | 'vision'

export interface AiModel {
  id: string
  provider: { id: string; code: string; name: string }
  model_key: string
  model_name: string
  display_name: string
  kind: 'text' | 'image'
  tier: 'economy' | 'standard' | 'premium'
  capabilities: Partial<Record<Capability, boolean>> & { context_window?: number | null; max_output_tokens?: number | null }
  is_enabled: boolean
  notes: string | null
  current_price: AiPrice | null
  scheduled_prices: number
}

export interface ModelRef {
  id: string
  model_key: string
  display_name: string
  is_enabled: boolean
}

export interface AiRoute {
  id: string
  task_key: string
  description: string | null
  queue: string
  priority: 'interactive' | 'batch'
  primary_model: ModelRef | null
  economy_model: ModelRef | null
  fallback_models: ModelRef[]
  max_input_tokens: number
  max_output_tokens: number
  temperature: number | null
  timeout_seconds: number | null
  max_attempts: number
  allow_paid_retry: boolean
  is_enabled: boolean
  updated_at: string
}

export interface AiBudget {
  id: string
  scope: 'global' | 'workspace'
  workspace: { id: string; name: string | null } | null
  period: 'day' | 'month'
  limit_micro_usd: MicroUsd
  alert_threshold_pct: number
  action_on_exceed: BudgetAction
  is_enabled: boolean
  usage: BudgetUsage
  updated_at: string
}

export interface AiRequestLog {
  id: string
  workspace: { id: string; name: string | null } | null
  task_key: string
  provider: string
  model: string
  attempt: number
  is_fallback: boolean
  status: 'succeeded' | 'failed' | 'skipped' | 'blocked'
  error: { code: string; message: string | null; http_status: number | null } | null
  billing_uncertain: boolean
  usage: Record<'input_tokens' | 'cached_input_tokens' | 'cache_write_tokens' | 'output_tokens' | 'reasoning_tokens' | 'images' | 'searches', number>
  cost_micro_usd: MicroUsd
  estimated_cost_micro_usd: MicroUsd
  latency_ms: number
  finish_reason: string | null
  workflow_run_id: string | null
  created_at: string
}

export interface JobsOverview {
  queue_connection: string
  horizon: 'running' | 'paused' | 'inactive' | 'unknown' | 'unavailable'
  queues: { name: string; size: number | null }[]
  workflows: Record<WorkflowStatus, number>
  stuck: number
  dead_letters_open: number
  failed_jobs: number
}

export interface DeadLetter {
  id: string
  workspace_id: string | null
  source: 'workflow' | 'job'
  workflow_run_id: string | null
  job_class: string
  queue: string | null
  error: { code: string; message: string | null }
  attempts: number
  status: 'open' | 'retried' | 'discarded'
  resolved_at: string | null
  created_at: string
}

export type PriceInput = Partial<Omit<AiPrice, 'id' | 'version' | 'state' | 'currency' | 'created_at'>> & {
  effective_from: string
  input_per_mtok: MicroUsd
  output_per_mtok: MicroUsd
}

const A = '/api/admin/v1'

export const adminAiApi = {
  usage: (query: { days?: number; workspace_id?: string }) => api.get<Envelope<UsageReport>>(`${A}/ai/usage`, { query }).then((r) => r.data),
  requests: (query: { cursor?: string | null; status?: string; provider?: string; workspace_id?: string; failed?: string }) => api.get<Paginated<AiRequestLog>>(`${A}/ai/requests`, { query }),

  providers: () => api.get<Envelope<AiProvider[]>>(`${A}/ai/providers`).then((r) => r.data),
  updateProvider: (id: string, body: Partial<Pick<AiProvider, 'is_enabled' | 'priority' | 'timeout_seconds' | 'max_retries' | 'circuit_failure_threshold' | 'circuit_cooldown_seconds'>>) =>
    api.patch<Envelope<AiProvider>>(`${A}/ai/providers/${id}`, body).then((r) => r.data),
  resetCircuit: (id: string) => api.post<Envelope<AiProvider>>(`${A}/ai/providers/${id}/circuit/reset`).then((r) => r.data),

  models: () => api.get<Envelope<AiModel[]>>(`${A}/ai/models`).then((r) => r.data),
  createModel: (body: { provider_id: string; model_name: string; display_name: string; kind?: string; tier?: string; capabilities?: Record<string, unknown>; notes?: string }) =>
    api.post<Envelope<AiModel>>(`${A}/ai/models`, body).then((r) => r.data),
  updateModel: (id: string, body: Partial<{ display_name: string; tier: string; is_enabled: boolean; capabilities: Record<string, unknown>; notes: string | null }>) =>
    api.patch<Envelope<AiModel>>(`${A}/ai/models/${id}`, body).then((r) => r.data),
  prices: (modelId: string) => api.get<Envelope<AiPrice[]>>(`${A}/ai/models/${modelId}/prices`).then((r) => r.data),
  createPrice: (modelId: string, body: PriceInput) => api.post<Envelope<AiPrice>>(`${A}/ai/models/${modelId}/prices`, body).then((r) => r.data),
  deletePrice: (modelId: string, priceId: string) => api.delete<void>(`${A}/ai/models/${modelId}/prices/${priceId}`),

  routes: () => api.get<Envelope<AiRoute[]>>(`${A}/ai/routes`).then((r) => r.data),
  updateRoute: (id: string, body: Partial<{ queue: string; priority: string; primary_model_id: string; economy_model_id: string | null; fallback_model_ids: string[]; max_input_tokens: number; max_output_tokens: number; temperature: number | null; timeout_seconds: number | null; max_attempts: number; allow_paid_retry: boolean; is_enabled: boolean }>) =>
    api.patch<Envelope<AiRoute>>(`${A}/ai/routes/${id}`, body).then((r) => r.data),

  budgets: () => api.get<Envelope<AiBudget[]>>(`${A}/ai/budgets`).then((r) => r.data),
  upsertBudget: (body: { scope: 'global' | 'workspace'; workspace_id?: string; period: 'day' | 'month'; limit_micro_usd: MicroUsd; alert_threshold_pct?: number; action_on_exceed?: BudgetAction; is_enabled?: boolean }) =>
    api.put<Envelope<AiBudget>>(`${A}/ai/budgets`, body).then((r) => r.data),
  deleteBudget: (id: string) => api.delete<void>(`${A}/ai/budgets/${id}`),

  jobsOverview: () => api.get<Envelope<JobsOverview>>(`${A}/jobs/overview`).then((r) => r.data),
  workflows: (query: { status?: string; type?: string; workspace_id?: string; cursor?: string | null }) => api.get<Paginated<WorkflowRun>>(`${A}/jobs/workflows`, { query }),
  workflow: (id: string) => api.get<Envelope<WorkflowRun>>(`${A}/jobs/workflows/${id}`).then((r) => r.data),
  retryWorkflow: (id: string) => api.post<Envelope<WorkflowRun>>(`${A}/jobs/workflows/${id}/retry`).then((r) => r.data),
  cancelWorkflow: (id: string) => api.post<Envelope<WorkflowRun>>(`${A}/jobs/workflows/${id}/cancel`).then((r) => r.data),
  deadLetters: (query: { status?: string; cursor?: string | null }) => api.get<Paginated<DeadLetter>>(`${A}/jobs/dead-letters`, { query }),
  retryDeadLetter: (id: string) => api.post<Envelope<DeadLetter>>(`${A}/jobs/dead-letters/${id}/retry`).then((r) => r.data),
  discardDeadLetter: (id: string) => api.post<Envelope<DeadLetter>>(`${A}/jobs/dead-letters/${id}/discard`).then((r) => r.data),
}

/** "$0.0123" — micro-USD to a readable dollar string. */
export function formatUsd(micro: number | null | undefined, digits?: number): string {
  if (micro === null || micro === undefined) return '—'
  const usd = micro / 1_000_000
  const abs = Math.abs(usd)
  const d = digits ?? (abs >= 100 ? 2 : abs >= 1 ? 3 : abs >= 0.01 || abs === 0 ? 4 : 6)
  return `$${usd.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d })}`
}

/** Price per 1M tokens in micro-USD → "$2.50". */
export function formatPerMtok(micro: number | null | undefined): string {
  if (micro === null || micro === undefined) return '—'
  return `$${(micro / 1_000_000).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`
}

export function usdToMicro(usd: string | number): number {
  const n = typeof usd === 'number' ? usd : Number.parseFloat(usd.replace(',', '.'))
  return Number.isFinite(n) ? Math.round(n * 1_000_000) : 0
}

export function formatTokens(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return n.toLocaleString('en-US')
}

export function newIdempotencyKey(): string {
  const c = globalThis.crypto as Crypto | undefined
  return c?.randomUUID ? c.randomUUID() : `k-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}
