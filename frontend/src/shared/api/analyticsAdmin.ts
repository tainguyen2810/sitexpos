import { api } from './client'
import type { Envelope } from './types'
import type { ToolInfo, ToolName } from './agent'

/** Phase 8 — Super Admin views of analytics ingestion and the SEO agent (cross-tenant, read mostly). */
export interface AnalyticsAdminOverview {
  days: number
  oauth_configured: boolean
  properties: Record<string, number>
  sync_runs: Record<string, number>
  api_requests: number
  rows_upserted: number
  errors: { error_code: string; n: number }[]
  failing: { workspace_id: string; site_id: string; consecutive_failures: number; last_error_code: string | null; last_sync_at: string | null }[]
  stale: number
  refresh_jobs: Record<string, number>
  copilot: { queries: number; cost_micro_usd: number }
}
export interface AgentAdminOverview {
  days: number
  runs: Record<string, number>
  active_runs: number
  tasks_by_tool: { tool: ToolName; status: string; n: number }[]
  decisions: Record<string, number>
  failed_runs: { id: string; workspace_id: string; site_id: string; kind: string; error_code: string | null; updated_at: string }[]
  levels: Record<string, number>
  emergency_stops: number
  estimated_cost_micro_usd: number
}
export interface PlatformPolicy {
  kill_switch: boolean
  kill_reason: string | null
  max_autonomy_level: number
  forbidden_tools: ToolName[] | null
  max_tasks_per_run: number
  max_concurrent_runs: number
  task_timeout_seconds: number
  run_timeout_minutes: number
  lock_version: number
  updated_at?: string | null
}

export const analyticsAdminApi = {
  analytics: (days = 7) => api.get<Envelope<AnalyticsAdminOverview>>(`/api/admin/v1/analytics/overview?days=${days}`),
  agent: (days = 7) => api.get<Envelope<AgentAdminOverview>>(`/api/admin/v1/agent/overview?days=${days}`),
  policy: () => api.get<{ data: PlatformPolicy; meta: { tools: ToolInfo[] } }>('/api/admin/v1/agent/policy'),
  updatePolicy: (body: Partial<PlatformPolicy> & { lock_version: number }) => api.put<Envelope<PlatformPolicy>>('/api/admin/v1/agent/policy', body),
}
