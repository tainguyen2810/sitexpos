import { api } from './client'
import type { Envelope } from './types'
import { qs } from './analytics'

/**
 * Phase 8 — Autonomous SEO Agent. The agent only proposes and runs
 * registered tools; every decision is taken by the server-side policy
 * engine. Level 3 (auto-publish) is never switched on implicitly.
 */
export const TOOLS = ['read_analytics', 'read_wp_content', 'analyze_site', 'run_seo_audit', 'create_proposal', 'create_refresh_brief', 'publish_via_workflow'] as const
export type ToolName = (typeof TOOLS)[number]
export const LEVELS = [0, 1, 2, 3] as const
export const RUN_KINDS = ['daily_monitoring', 'weekly_audit', 'monthly_review', 'content_refresh', 'opportunity_discovery'] as const
export type RunKind = (typeof RUN_KINDS)[number]
export const RUN_STATUSES = ['queued', 'running', 'paused', 'awaiting_approval', 'completed', 'failed', 'cancelled'] as const
export const TASK_STATUSES = ['pending', 'awaiting_approval', 'approved', 'queued', 'running', 'succeeded', 'failed', 'rejected', 'cancelled', 'skipped', 'blocked'] as const
export const PHASES = ['observe', 'analyze', 'plan', 'estimate', 'approve', 'execute', 'verify', 'learn', 'done'] as const
export const SCHEDULE_STATUSES = ['active', 'paused', 'disabled'] as const

export interface ToolInfo {
  name: ToolName
  min_level: number
  permission: string
  writes: boolean
  content: boolean
  cost: number
  description: string
}
export interface AgentConfig {
  site_id: string
  enabled: boolean
  autonomy_level: number
  effective_level: number
  emergency_stop: boolean
  allowed_actions: ToolName[]
  forbidden_actions: ToolName[]
  max_daily_tasks: number
  max_monthly_ai_spend_micro_usd: number
  max_monthly_refreshes: number
  max_monthly_new_articles: number
  publish_window_start: number
  publish_window_end: number
  timezone: string
  approval_requirements: Partial<Record<ToolName, boolean>>
  principal_user_id: string | null
  level3_enabled: boolean
  level3_enabled_at: string | null
  lock_version: number
  is_demo: boolean
  platform: { kill_switch: boolean; max_autonomy_level: number; forbidden_tools: ToolName[]; max_tasks_per_run: number }
}
export interface AgentRun {
  id: string
  site_id: string
  schedule_id: string | null
  trigger: 'manual' | 'schedule' | string
  kind: RunKind
  status: (typeof RUN_STATUSES)[number]
  phase: (typeof PHASES)[number]
  autonomy_level: number
  max_tasks: number
  estimated_cost_micro_usd: number | null
  actual_cost_micro_usd: number | null
  observations: Record<string, unknown> | null
  plan: { key: string; tool: ToolName; reason: string; priority: number }[] | Record<string, unknown> | null
  summary: Record<string, unknown> | null
  error_code: string | null
  started_at: string | null
  finished_at: string | null
  deadline_at: string | null
  is_demo: boolean
  created_at: string | null
}
export interface AgentTask {
  id: string
  site_id: string
  agent_run_id: string
  sequence: number
  tool: ToolName
  status: (typeof TASK_STATUSES)[number]
  decision: 'allow' | 'require_approval' | 'deny'
  decision_reasons: string[]
  input: Record<string, unknown>
  evidence: Record<string, unknown> | null
  output: Record<string, unknown> | null
  estimated_cost_micro_usd: number | null
  actual_cost_micro_usd: number | null
  error_code: string | null
  error_message: string | null
  attempts: number
  max_attempts: number
  approved_by: string | null
  approved_at: string | null
  started_at: string | null
  finished_at: string | null
  is_demo: boolean
  created_at: string | null
}
export interface Schedule {
  id: string
  site_id: string
  kind: RunKind
  frequency: 'daily' | 'weekly' | 'monthly'
  day_of_week: number | null
  day_of_month: number | null
  local_time: string
  timezone: string
  status: (typeof SCHEDULE_STATUSES)[number]
  next_run_at: string | null
  last_run_at: string | null
  last_run_id: string | null
  last_status: string | null
  consecutive_failures: number
  notify_on_failure: boolean
  is_demo: boolean
  preview: string[]
}
export interface AgentLog {
  id: string
  site_id: string | null
  agent_run_id: string | null
  agent_task_id: string | null
  event: string
  level: 'info' | 'warning' | 'error'
  message: string
  context: Record<string, unknown> | null
  actor_type: 'agent' | 'user' | 'system'
  actor_id: string | null
  created_at: string | null
}
export interface AgentMemory {
  id: string
  site_id: string | null
  kind: string
  content: string
  evidence: Record<string, unknown> | null
  source_ref: string | null
  verified: boolean
  confidence: number | null
  expires_at: string | null
  created_at: string | null
}
export type ConfigUpdate = Partial<Omit<AgentConfig, 'site_id' | 'effective_level' | 'emergency_stop' | 'principal_user_id' | 'level3_enabled' | 'level3_enabled_at' | 'is_demo' | 'platform'>> & {
  lock_version: number
  confirm_level3?: boolean
}

const base = (ws: string) => `/api/v1/workspaces/${ws}/agent`

export const agentApi = {
  tools: (ws: string) => api.get<{ data: ToolInfo[]; meta: { pipeline_steps: Record<string, string>; levels: Record<string, string> } }>(`${base(ws)}/tools`),
  config: (ws: string, site: string) => api.get<Envelope<AgentConfig>>(`${base(ws)}/sites/${site}/config`),
  updateConfig: (ws: string, site: string, body: ConfigUpdate) => api.put<Envelope<AgentConfig>>(`${base(ws)}/sites/${site}/config`, body),
  emergencyStop: (ws: string, site: string, stop: boolean, reason?: string) => api.post<Envelope<AgentConfig>>(`${base(ws)}/sites/${site}/emergency-stop`, { stop, reason }),
  startRun: (ws: string, site: string, kind: RunKind, idempotencyKey?: string) => api.post<Envelope<AgentRun>>(`${base(ws)}/sites/${site}/runs`, { kind, idempotency_key: idempotencyKey }),
  runs: (ws: string, f: { site_id?: string; status?: string } = {}) => api.get<Envelope<AgentRun[]>>(`${base(ws)}/runs${qs(f)}`),
  run: (ws: string, id: string) => api.get<Envelope<AgentRun & { tasks: AgentTask[] }>>(`${base(ws)}/runs/${id}`),
  pause: (ws: string, id: string) => api.post<Envelope<AgentRun>>(`${base(ws)}/runs/${id}/pause`),
  resume: (ws: string, id: string) => api.post<Envelope<AgentRun>>(`${base(ws)}/runs/${id}/resume`),
  cancel: (ws: string, id: string) => api.post<Envelope<AgentRun>>(`${base(ws)}/runs/${id}/cancel`),
  /** Defaults to tasks awaiting approval when no status is given. */
  tasks: (ws: string, f: { site_id?: string; status?: string } = {}) => api.get<Envelope<AgentTask[]>>(`${base(ws)}/tasks${qs(f)}`),
  approve: (ws: string, id: string, note?: string) => api.post<Envelope<AgentTask>>(`${base(ws)}/tasks/${id}/approve`, { note }),
  reject: (ws: string, id: string, note?: string) => api.post<Envelope<AgentTask>>(`${base(ws)}/tasks/${id}/reject`, { note }),
  retry: (ws: string, id: string) => api.post<Envelope<AgentTask>>(`${base(ws)}/tasks/${id}/retry`),
  logs: (ws: string, f: { site_id?: string; run_id?: string } = {}) => api.get<Envelope<AgentLog[]>>(`${base(ws)}/logs${qs(f)}`),
  memories: (ws: string, site?: string) => api.get<Envelope<AgentMemory[]>>(`${base(ws)}/memories${qs({ site_id: site })}`),
  schedules: (ws: string, site: string) => api.get<Envelope<Schedule[]>>(`${base(ws)}/sites/${site}/schedules`),
  saveSchedule: (ws: string, site: string, body: { kind: RunKind; frequency?: string; day_of_week?: number | null; day_of_month?: number | null; local_time?: string; timezone?: string; notify_on_failure?: boolean }) =>
    api.post<Envelope<Schedule>>(`${base(ws)}/sites/${site}/schedules`, body),
  updateSchedule: (ws: string, id: string, body: Partial<Pick<Schedule, 'status' | 'frequency' | 'day_of_week' | 'day_of_month' | 'local_time' | 'timezone' | 'notify_on_failure'>>) =>
    api.patch<Envelope<Schedule>>(`${base(ws)}/schedules/${id}`, body),
}

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger'
export function runTone(s: string): Tone {
  return ({ completed: 'success', awaiting_approval: 'warning', running: 'brand', queued: 'brand', paused: 'neutral', failed: 'danger', cancelled: 'neutral' } as Record<string, Tone>)[s] ?? 'neutral'
}
export function taskTone(s: string): Tone {
  return ({ succeeded: 'success', awaiting_approval: 'warning', running: 'brand', queued: 'brand', failed: 'danger', blocked: 'danger', rejected: 'neutral', cancelled: 'neutral' } as Record<string, Tone>)[s] ?? 'neutral'
}
export function decisionTone(d: string): Tone {
  return ({ allow: 'success', require_approval: 'warning', deny: 'danger' } as Record<string, Tone>)[d] ?? 'neutral'
}

/**
 * UI hint only (the server decides): can this tool run without a person at
 * the given level? Mirrors the policy engine's level gate.
 */
export function needsApproval(tool: ToolInfo, level: number, config?: Pick<AgentConfig, 'approval_requirements' | 'level3_enabled'>): boolean {
  if (tool.name === 'publish_via_workflow') return !(level >= 3 && config?.level3_enabled)
  if (config?.approval_requirements?.[tool.name]) return true
  return level < tool.min_level
}
export function blockedAtLevel(tool: ToolInfo, level: number): boolean {
  return level === 0 && tool.writes
}
