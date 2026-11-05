import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { siteApi, type Site } from '@/shared/api/sites'
import { useToast } from '@/shared/composables/useToast'
import { useWorkspaceStore } from '../../stores/workspace'

/** Permission flags for Phase 6 screens. UI hints only: the API enforces every one. */
export function usePhase6Perms() {
  const ws = useWorkspaceStore()
  const readOnly = computed(() => !!ws.current?.impersonating)
  return {
    workspaceId: computed(() => ws.current!.id),
    canEdit: computed(() => ws.can('content.edit') && !readOnly.value),
    canPublish: computed(() => ws.can('content.publish') && !readOnly.value),
    canManageSites: computed(() => ws.can('sites.manage') && !readOnly.value),
    readOnly,
  }
}

/** Toasts the API's own localized message (codes are documented in API-CONTRACTS.md). */
export function usePhase6Errors() {
  const { t } = useI18n()
  const toast = useToast()
  function message(e: unknown): string {
    if (e instanceof ApiError) {
      if (e.status === 0) return t('errors.network')
      if (e.status === 429) return t('p6.common.rateLimited')
      return e.message || t('errors.generic')
    }
    return t('errors.generic')
  }
  return { message, toastError: (e: unknown) => toast.error(message(e)), toast }
}

/** Sites of the workspace with a selected one kept in the `site` query param. */
export function useSites(options: { required?: boolean } = {}) {
  const route = useRoute()
  const router = useRouter()
  const { workspaceId } = usePhase6Perms()
  const sites = shallowRef<Site[]>([])
  const loading = ref(true)
  const error = shallowRef<unknown>(null)
  const siteId = ref(typeof route.query.site === 'string' ? route.query.site : '')

  async function load() {
    loading.value = true
    error.value = null
    try {
      sites.value = await siteApi.list(workspaceId.value)
      if (options.required && !siteId.value && sites.value.length) siteId.value = sites.value[0]!.id
    } catch (e) {
      error.value = e
    } finally {
      loading.value = false
    }
  }
  watch(siteId, (v) => void router.replace({ query: { ...route.query, site: v || undefined } }))
  onMounted(load)
  const options_ = computed(() => sites.value.map((s) => ({ value: s.id, label: s.name })))
  const current = computed(() => sites.value.find((s) => s.id === siteId.value) ?? null)
  return { sites, siteId, loading, error, reload: load, options: options_, current }
}

export function siteName(sites: Site[], id: string | null | undefined): string {
  return sites.find((s) => s.id === id)?.name ?? '—'
}
