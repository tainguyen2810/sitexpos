import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { useToast } from '@/shared/composables/useToast'
import { useWorkspaceStore } from '../../stores/workspace'

/** Billing permission hints (UI only — the API enforces `billing.manage` = OWNER). */
export function useBilling() {
  const ws = useWorkspaceStore()
  const { t } = useI18n()
  const toast = useToast()
  const readOnly = computed(() => !!ws.current?.impersonating)
  function message(e: unknown): string {
    if (e instanceof ApiError) {
      if (e.status === 0) return t('errors.network')
      if (e.status === 429) return t('bill.common.rateLimited')
      return e.message || t('errors.generic')
    }
    return t('errors.generic')
  }
  return {
    workspaceId: computed(() => ws.current!.id),
    canManage: computed(() => ws.can('billing.manage') && !readOnly.value),
    readOnly,
    toast,
    message,
    toastError: (e: unknown) => toast.error(message(e)),
  }
}

export const BILLING_NAV = [
  { key: 'overview', route: 'billing' },
  { key: 'plans', route: 'billing-plans' },
  { key: 'credits', route: 'billing-credits' },
  { key: 'transactions', route: 'billing-transactions' },
  { key: 'usage', route: 'billing-usage' },
  { key: 'orders', route: 'billing-orders', manage: true },
  { key: 'history', route: 'billing-history' },
  { key: 'profile', route: 'billing-profile', manage: true },
] as const
