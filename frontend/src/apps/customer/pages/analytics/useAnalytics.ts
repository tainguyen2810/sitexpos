import { computed, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { analyticsApi, type AnalyticsSite } from '@/shared/api/analytics'
import { useToast } from '@/shared/composables/useToast'
import { useWorkspaceStore } from '../../stores/workspace'

/**
 * Shared state for the Phase 8 pages: the selected website (kept in the
 * `?site=` query so links are shareable) and permission hints. The API is
 * always the one that enforces permissions.
 */
const sitesCache = shallowRef<{ workspace: string; sites: AnalyticsSite[]; oauth: boolean } | null>(null)

export function useAnalytics() {
  const ws = useWorkspaceStore()
  const route = useRoute()
  const router = useRouter()
  const { t } = useI18n()
  const toast = useToast()
  const readOnly = computed(() => !!ws.current?.impersonating)
  const workspaceId = computed(() => ws.current!.id)
  const sites = computed(() => (sitesCache.value?.workspace === workspaceId.value ? sitesCache.value.sites : []))
  const oauthConfigured = computed(() => sitesCache.value?.oauth ?? false)
  const sitesLoading = ref(false)
  const sitesError = shallowRef<unknown>(null)

  const siteId = computed<string>({
    get: () => (typeof route.query.site === 'string' ? route.query.site : '') || sites.value[0]?.id || '',
    set: (v) => void router.replace({ query: { ...route.query, site: v || undefined } }),
  })
  const site = computed(() => sites.value.find((s) => s.id === siteId.value) ?? null)

  async function loadSites(force = false): Promise<void> {
    if (!force && sitesCache.value?.workspace === workspaceId.value) return
    sitesLoading.value = true
    sitesError.value = null
    try {
      const res = await analyticsApi.sites(workspaceId.value)
      sitesCache.value = { workspace: workspaceId.value, sites: res.data, oauth: res.meta.oauth_configured }
    } catch (e) {
      sitesError.value = e
    } finally {
      sitesLoading.value = false
    }
  }

  function message(e: unknown): string {
    if (e instanceof ApiError) {
      if (e.status === 0) return t('errors.network')
      if (e.status === 429) return t('an.common.rateLimited')
      return e.message || t('errors.generic')
    }
    return t('errors.generic')
  }

  return {
    workspaceId,
    sites,
    site,
    siteId,
    oauthConfigured,
    sitesLoading,
    sitesError,
    loadSites,
    readOnly,
    canEdit: computed(() => ws.can('content.edit') && !readOnly.value),
    canPublish: computed(() => ws.can('content.publish') && !readOnly.value),
    canConfigure: computed(() => ws.can('sites.manage') && !readOnly.value),
    toast,
    message,
    toastError: (e: unknown) => toast.error(message(e)),
    /** Re-run a loader whenever the selected site changes. */
    onSite(fn: () => unknown) {
      watch(siteId, () => void fn())
    },
  }
}

export const ANALYTICS_NAV = [
  { key: 'overview', route: 'analytics' },
  { key: 'queries', route: 'analytics-queries' },
  { key: 'pages', route: 'analytics-pages' },
  { key: 'articles', route: 'analytics-articles' },
  { key: 'content', route: 'analytics-content' },
  { key: 'opportunities', route: 'analytics-opportunities' },
  { key: 'decay', route: 'analytics-decay' },
  { key: 'recommendations', route: 'analytics-recommendations' },
  { key: 'refresh', route: 'analytics-refresh' },
  { key: 'experiments', route: 'analytics-experiments' },
  { key: 'health', route: 'analytics-health' },
  { key: 'reports', route: 'analytics-reports' },
  { key: 'copilot', route: 'analytics-copilot' },
  { key: 'connections', route: 'analytics-connections' },
  { key: 'settings', route: 'analytics-settings', configure: true },
] as const

export const AGENT_NAV = [
  { key: 'config', route: 'agent' },
  { key: 'approvals', route: 'agent-approvals' },
  { key: 'runs', route: 'agent-runs' },
  { key: 'schedules', route: 'agent-schedules' },
  { key: 'logs', route: 'agent-logs' },
] as const
