import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/composables/useToast'

export const SYSTEM_NAV = [
  { key: 'health', to: '/system/health', permission: 'platform.system.view' },
  { key: 'setup', to: '/setup', permission: 'platform.system.manage' },
  { key: 'upgrade', to: '/system/upgrade', permission: 'platform.system.manage' },
  { key: 'backups', to: '/system/backups', permission: 'platform.system.manage' },
  { key: 'alerts', to: '/system/alerts', permission: 'platform.system.manage' },
] as const

export function useSystem() {
  const { t } = useI18n()
  const toast = useToast()
  function message(e: unknown): string {
    if (e instanceof ApiError) {
      if (e.status === 0) return t('errors.network')
      if (e.status === 429) return t('sys.common.rateLimited')
      return e.message || t('errors.generic')
    }
    return t('errors.generic')
  }
  /** Field errors from a 422 response, keyed by field name (first message only). */
  function fieldErrors(e: unknown): Record<string, string> {
    if (!(e instanceof ApiError) || e.status !== 422) return {}
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(e.details)) if (v[0]) out[k] = v[0]
    return out
  }
  return { t, toast, message, fieldErrors, toastError: (e: unknown) => toast.error(message(e)) }
}

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('vi-VN')
}

/** Renders a health/setup detail value as short text (no HTML). */
export function detailText(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? '✓' : '✗'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
