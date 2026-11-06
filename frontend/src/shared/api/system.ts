import { api, apiUrl } from './client'
import type { Envelope } from './types'

/** Phase 9 — System Health, Setup Wizard, Upgrade, Backups and alerts (Super Admin). */
const S = '/api/admin/v1/system'
const unwrap = <T>(r: Envelope<T>) => r.data

export type HealthStatus = 'ok' | 'warning' | 'critical' | 'unknown'
export type StepStatus = 'NOT_STARTED' | 'IN_PROGRESS' | 'COMPLETE' | 'WARNING' | 'BLOCKED'
export type Readiness = 'READY' | 'READY_WITH_WARNINGS' | 'NOT_READY'

export const HEALTH_KEYS = ['application', 'database', 'redis', 'horizon', 'scheduler', 'queue', 'storage', 'ai_providers', 'ai_spending', 'payments', 'wordpress', 'gsc', 'mail', 'backup', 'ssl', 'security'] as const
export const SETUP_STEPS = ['general', 'ai_providers', 'ai_models', 'email', 'plans', 'payments', 'credits', 'google', 'wordpress', 'queue', 'security', 'final'] as const
export type SetupStepKey = (typeof SETUP_STEPS)[number]
export const STEP_STATUSES: StepStatus[] = ['NOT_STARTED', 'IN_PROGRESS', 'COMPLETE', 'WARNING', 'BLOCKED']

export interface HealthCheck {
  key: string
  status: HealthStatus
  summary: string
  details: Record<string, unknown>
  fix: string | null
}
export interface HealthReport {
  status: HealthStatus
  generated_at: string
  checks: HealthCheck[]
}

export interface SetupStep {
  key: SetupStepKey
  status: StepStatus
  required: boolean
  data: Record<string, unknown>
}
export interface SetupOverview {
  steps: SetupStep[]
  readiness: Readiness
  completed_at: string | null
  progress_percent: number
}

export interface GeneralSettings {
  brand_name: string
  support_email: string
  default_locale: string
  default_timezone: string
  currency?: string
  company_name?: string | null
  company_tax_code?: string | null
  company_address?: string | null
  contact_phone?: string | null
  contact_email?: string | null
}
export interface MailSettings {
  mailer: 'smtp' | 'env'
  host?: string
  port?: number
  username?: string | null
  /** Write-only: blank keeps the saved password. */
  password?: string
  encryption?: 'tls' | 'ssl' | 'none'
  from_address?: string
  from_name?: string | null
}
export interface GoogleSettings {
  client_id?: string | null
  client_secret?: string
  ga4_enabled?: boolean
  clear?: boolean
}
export interface AlertSettings {
  emails: string[]
  cooldown_minutes?: number | null
  telegram_bot_token?: string
  telegram_chat_id?: string | null
  clear_telegram?: boolean
}
export interface AlertsView {
  settings: { emails: string[]; telegram_bot_token_configured: boolean; telegram_chat_id: string | null; cooldown_minutes: number | null; last_test?: unknown }
  history: { id: string; check_key: string; severity: string; summary: string; channels: Record<string, string>; created_at: string | null }[]
}

export interface UpgradeRun {
  id: string
  from_version: string | null
  to_version: string
  status: 'queued' | 'running' | 'succeeded' | 'failed'
  migrations: string[]
  steps: { step: string; status: string; note: string | null; at: string }[]
  error: string | null
  created_at: string | null
  finished_at: string | null
}
export interface PendingMigration {
  name: string
  destructive: boolean
}
export interface UpgradeStatus {
  current_version: string | null
  target_version: string
  upgrade_needed: boolean
  changelog: { version: string; date: string | null; notes: string[] }[]
  requirements: { summary: Record<string, number>; failed: { key: string; group: string; status: string; value: string; fix: string | null }[] }
  migrations: { pending: PendingMigration[]; destructive: PendingMigration[]; ran_count: number }
  backup: { last_success_at: string | null; verified: boolean; recent: boolean; encryption_configured: boolean }
  maintenance: { down: boolean; required: boolean }
  blockers: string[]
  can_upgrade: boolean
  runs: UpgradeRun[]
}

export interface BackupItem {
  id: string
  status: 'running' | 'succeeded' | 'failed'
  trigger: string
  file_name: string | null
  size_bytes: number | null
  sha256: string | null
  contents: string[]
  offsite_status: string | null
  verify_status: string | null
  verified_at: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
}
export interface BackupsView {
  configured: boolean
  offsite_disk: string | null
  retention_days: number
  schedule: { schedule_enabled: boolean; schedule_time: string }
  items: BackupItem[]
}

export const systemApi = {
  health: () => api.get<Envelope<HealthReport>>(`${S}/health`).then(unwrap),
  alerts: () => api.get<Envelope<AlertsView>>(`${S}/alerts`).then(unwrap),
  setup: () => api.get<Envelope<SetupOverview>>(`${S}/setup`).then(unwrap),
  draftPlans: () => api.post<Envelope<{ created: string[] }>>(`${S}/setup/plans/draft`).then(unwrap),
  acknowledge: (step: 'wordpress') => api.post<Envelope<{ acknowledged: string[] }>>(`${S}/setup/acknowledge`, { step }).then(unwrap),
  complete: () => api.post<Envelope<SetupOverview>>(`${S}/setup/complete`).then(unwrap),
  updateGeneral: (body: GeneralSettings) => api.put<Envelope<Record<string, unknown>>>(`${S}/settings/general`, body).then(unwrap),
  uploadLogo: (file: File) => {
    const fd = new FormData()
    fd.append('logo', file)
    return api.post<Envelope<{ logo_path: string }>>(`${S}/settings/logo`, fd).then(unwrap)
  },
  updateMail: (body: MailSettings) => api.put<Envelope<Record<string, unknown>>>(`${S}/settings/mail`, body).then(unwrap),
  testMail: (to: string) => api.post<Envelope<{ queued: boolean }>>(`${S}/settings/mail/test`, { to }).then(unwrap),
  updateGoogle: (body: GoogleSettings) => api.put<Envelope<{ saved: boolean }>>(`${S}/settings/google`, body).then(unwrap),
  testGoogle: () => api.post<Envelope<{ queued: boolean }>>(`${S}/settings/google/test`).then(unwrap),
  updateAlerts: (body: AlertSettings) => api.put<Envelope<AlertsView['settings']>>(`${S}/settings/alerts`, body).then(unwrap),
  testAlert: () => api.post<Envelope<{ queued: boolean }>>(`${S}/settings/alerts/test`).then(unwrap),
  updateBackup: (body: { schedule_enabled: boolean; schedule_time: string }) => api.put<Envelope<BackupsView['schedule']>>(`${S}/settings/backup`, body).then(unwrap),
  upgrade: () => api.get<Envelope<UpgradeStatus>>(`${S}/upgrade`).then(unwrap),
  startUpgrade: (password: string, backupFirst: boolean) => api.post<Envelope<{ id: string; status: string }>>(`${S}/upgrade`, { password, backup_first: backupFirst }).then(unwrap),
  backups: () => api.get<Envelope<BackupsView>>(`${S}/backups`).then(unwrap),
  startBackup: (password: string) => api.post<Envelope<{ id: string; status: string }>>(`${S}/backups`, { password }).then(unwrap),
  verifyBackup: (id: string) => api.post<Envelope<{ queued: boolean }>>(`${S}/backups/${encodeURIComponent(id)}/verify`).then(unwrap),
  pluginDownloadUrl: () => apiUrl(`${S}/plugin/download`),
}

export function healthTone(s: HealthStatus | string): 'success' | 'warning' | 'danger' | 'neutral' {
  return s === 'ok' ? 'success' : s === 'warning' ? 'warning' : s === 'critical' ? 'danger' : 'neutral'
}

export function stepTone(s: StepStatus | string): 'success' | 'warning' | 'danger' | 'brand' | 'neutral' {
  return s === 'COMPLETE' ? 'success' : s === 'WARNING' ? 'warning' : s === 'BLOCKED' ? 'danger' : s === 'IN_PROGRESS' ? 'brand' : 'neutral'
}

export function formatBytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = n
  let i = 0
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${i === 0 ? v : v.toFixed(1).replace('.', ',')} ${units[i]}`
}
