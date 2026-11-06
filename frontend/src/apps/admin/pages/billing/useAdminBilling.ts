import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/composables/useToast'

export function useAdminBilling() {
  const { t } = useI18n()
  const toast = useToast()
  function message(e: unknown): string {
    if (e instanceof ApiError) return e.status === 0 ? t('errors.network') : e.message || t('errors.generic')
    return t('errors.generic')
  }
  return { toast, message, toastError: (e: unknown) => toast.error(message(e)) }
}

/** Parse a decimal input to an integer (minor units / mc). Returns null when not an integer. */
export function toInt(v: string | number | null | undefined): number | null {
  if (v === null || v === undefined || String(v).trim() === '') return null
  const n = Number(String(v).replace(/[\s.,_]/g, ''))
  return Number.isInteger(n) ? n : null
}
