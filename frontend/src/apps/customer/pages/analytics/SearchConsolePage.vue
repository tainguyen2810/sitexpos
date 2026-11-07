<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { analyticsApi, idempotencyKey, type AnalyticsSite, type SyncRun } from '@/shared/api/analytics'
import { useAsync } from '@/shared/composables/useAsync'
import { formatDate, formatDateTime } from '@/shared/utils/format'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppCard from '@/shared/ui/AppCard.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import PageHeader from '@/shared/ui/PageHeader.vue'
import AnalyticsNav from './AnalyticsNav.vue'
import { useAnalytics } from './useAnalytics'

/**
 * Google Search Console per website (Phase 4 OAuth reused): connect,
 * choose the verified property, sync / backfill, disconnect (revokes the
 * grant at Google). Google passwords are never asked for.
 */
const { t } = useI18n()
const route = useRoute()
const { workspaceId, sites, sitesLoading, sitesError, loadSites, oauthConfigured, canConfigure, canEdit, toast, toastError } = useAnalytics()
const runs = useAsync(() => analyticsApi.syncRuns(workspaceId.value))
const gscResult = computed(() => (typeof route.query.gsc === 'string' ? route.query.gsc : ''))
const busy = ref('')
const propertySite = shallowRef<AnalyticsSite | null>(null)
const properties = ref<{ site_url: string; permission: string }[]>([])
const disconnectSite = shallowRef<AnalyticsSite | null>(null)
onMounted(() => void loadSites(true))

async function connect(s: AnalyticsSite) {
  busy.value = `connect:${s.id}`
  try {
    const res = await analyticsApi.connect(workspaceId.value, s.id)
    window.location.assign(res.data.authorization_url)
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
async function openProperties(s: AnalyticsSite) {
  busy.value = `props:${s.id}`
  try {
    properties.value = (await analyticsApi.properties(workspaceId.value, s.id)).data
    propertySite.value = s
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
async function choose(url: string) {
  if (!propertySite.value) return
  busy.value = 'choose'
  try {
    await analyticsApi.selectProperty(workspaceId.value, propertySite.value.id, url)
    toast.success(t('an.connections.propertySaved'))
    propertySite.value = null
    await loadSites(true)
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
async function sync(s: AnalyticsSite, kind: 'incremental' | 'backfill') {
  busy.value = `${kind}:${s.id}`
  try {
    await analyticsApi.sync(workspaceId.value, s.id, kind, idempotencyKey(kind))
    toast.success(t('an.connections.syncQueued'))
    await Promise.all([runs.run(), loadSites(true)])
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
async function disconnect() {
  const s = disconnectSite.value
  if (!s) return
  busy.value = 'disconnect'
  try {
    const res = await analyticsApi.disconnect(workspaceId.value, s.id)
    toast.success(res.data.revoked_at_google ? t('an.connections.disconnected') : t('an.connections.disconnectedLocal'))
    disconnectSite.value = null
    await loadSites(true)
  } catch (e) {
    toastError(e)
  } finally {
    busy.value = ''
  }
}
function statusTone(s: string): 'success' | 'warning' | 'danger' | 'neutral' {
  return ({ active: 'success', needs_property: 'warning', reauth_required: 'danger', error: 'danger' } as Record<string, 'success' | 'warning' | 'danger'>)[s] ?? 'neutral'
}
function runTone(s: SyncRun['status']): 'success' | 'warning' | 'danger' | 'brand' | 'neutral' {
  return ({ completed: 'success', skipped: 'neutral', failed: 'danger', partial: 'warning', running: 'brand', queued: 'brand' } as Record<string, 'success' | 'warning' | 'danger' | 'brand' | 'neutral'>)[s] ?? 'neutral'
}
</script>

<template>
  <div>
    <PageHeader :title="t('an.title')" :description="t('an.connections.subtitle')" />
    <AnalyticsNav active="connections" :site-select="false" />

    <div v-if="gscResult" class="mb-4 rounded-lg border border-line bg-surface-muted p-3 text-sm" role="status">{{ t(`an.connections.result.${gscResult}`, t('an.connections.result.other')) }}</div>
    <div v-if="!oauthConfigured && sites.length" class="mb-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-700">{{ t('an.connections.notConfigured') }}</div>

    <LoadingState v-if="sitesLoading && !sites.length" />
    <ErrorState v-else-if="sitesError" :error="sitesError" @retry="loadSites(true)" />
    <EmptyState v-else-if="!sites.length" icon="globe" :title="t('an.connections.noSites')">
      <RouterLink :to="{ name: 'sites', params: { workspaceId } }" class="text-sm font-medium text-brand-700 hover:underline">{{ t('an.connections.addSite') }}</RouterLink>
    </EmptyState>
    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <AppCard v-for="s in sites" :key="s.id" :title="s.name" :subtitle="s.url" :data-testid="`gsc-site-${s.id}`">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <dt class="text-ink-muted">{{ t('an.connections.gsc') }}</dt>
          <dd><AppBadge :tone="statusTone(s.search_console.status)">{{ t(`an.connections.status.${s.search_console.status}`, s.search_console.status) }}</AppBadge></dd>
          <dt class="text-ink-muted">{{ t('an.connections.account') }}</dt>
          <dd class="truncate">{{ s.search_console.google_email ?? '—' }}</dd>
          <dt class="text-ink-muted">{{ t('an.connections.property') }}</dt>
          <dd class="truncate">{{ s.search_console.property_url ?? t('an.connections.noProperty') }}</dd>
          <dt class="text-ink-muted">{{ t('an.connections.dataRange') }}</dt>
          <dd>{{ s.analytics?.first_date ? `${formatDate(s.analytics.first_date)} – ${formatDate(s.analytics.last_complete_date)}` : t('an.common.noData') }}</dd>
          <dt class="text-ink-muted">{{ t('an.connections.lastSync') }}</dt>
          <dd>
            {{ formatDateTime(s.analytics?.last_sync_at) }}
            <span v-if="s.analytics?.last_error_code" class="ml-1 text-xs text-danger-600">{{ t(`an.errors.${s.analytics.last_error_code}`, s.analytics.last_error_code) }}</span>
          </dd>
          <dt class="text-ink-muted">GA4</dt>
          <dd class="text-ink-muted">{{ t('an.connections.ga4Later') }}</dd>
        </dl>
        <div class="mt-4 flex flex-wrap gap-2">
          <template v-if="canConfigure">
            <AppButton v-if="!s.search_console.connected || s.search_console.status === 'reauth_required'" size="sm" :disabled="!oauthConfigured" :loading="busy === `connect:${s.id}`" @click="connect(s)">
              {{ s.search_console.connected ? t('an.connections.reconnect') : t('an.connections.connect') }}
            </AppButton>
            <AppButton v-if="s.search_console.connected" size="sm" variant="secondary" :loading="busy === `props:${s.id}`" @click="openProperties(s)">{{ t('an.connections.chooseProperty') }}</AppButton>
          </template>
          <template v-if="canEdit && s.search_console.status === 'active'">
            <AppButton size="sm" variant="secondary" :loading="busy === `incremental:${s.id}`" @click="sync(s, 'incremental')">{{ t('an.connections.syncNow') }}</AppButton>
            <AppButton size="sm" variant="ghost" :loading="busy === `backfill:${s.id}`" @click="sync(s, 'backfill')">{{ t('an.connections.backfill') }}</AppButton>
          </template>
          <AppButton v-if="canConfigure && s.search_console.connected" size="sm" variant="danger" @click="disconnectSite = s">{{ t('an.connections.disconnect') }}</AppButton>
        </div>
      </AppCard>
    </div>

    <AppCard :title="t('an.connections.runs')" :padded="false" class="mt-6">
      <LoadingState v-if="runs.loading.value && !runs.data.value" />
      <ErrorState v-else-if="runs.error.value" :error="runs.error.value" @retry="runs.run" />
      <EmptyState v-else-if="!runs.data.value?.data.length" icon="refresh" :title="t('an.connections.noRuns')" />
      <div v-else class="overflow-x-auto">
        <table class="data-table min-w-full text-sm">
          <thead class="bg-surface-muted text-left text-xs uppercase text-ink-muted">
            <tr>
              <th class="px-4 py-2">{{ t('an.connections.kind') }}</th>
              <th class="px-4 py-2">{{ t('an.common.status') }}</th>
              <th class="px-4 py-2">{{ t('an.connections.range') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.connections.rows') }}</th>
              <th class="px-4 py-2 text-right">{{ t('an.connections.requests') }}</th>
              <th class="px-4 py-2">{{ t('an.common.createdAt') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="r in runs.data.value.data" :key="r.id">
              <td class="px-4 py-2">{{ t(`an.connections.kinds.${r.kind}`) }}<AppBadge v-if="r.is_demo" class="ml-1">DEMO</AppBadge></td>
              <td class="px-4 py-2">
                <AppBadge :tone="runTone(r.status)">{{ t(`an.connections.runStatus.${r.status}`, r.status) }}</AppBadge>
                <p v-if="r.error_code" class="mt-1 text-xs text-danger-600">{{ t(`an.errors.${r.error_code}`, r.error_code) }}<span v-if="r.next_retry_at"> · {{ t('an.connections.retryAt') }} {{ formatDateTime(r.next_retry_at) }}</span></p>
              </td>
              <td class="px-4 py-2 tabular-nums">{{ r.range_start ?? '—' }} → {{ r.range_end ?? '—' }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ r.rows_upserted }}</td>
              <td class="px-4 py-2 text-right tabular-nums">{{ r.api_requests }}</td>
              <td class="px-4 py-2">{{ formatDateTime(r.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>

    <AppModal :open="!!propertySite" :title="t('an.connections.chooseProperty')" @close="propertySite = null">
      <p class="mb-3 text-sm text-ink-muted">{{ t('an.connections.propertyHint') }}</p>
      <EmptyState v-if="!properties.length" icon="search" :title="t('an.connections.noProperties')" />
      <ul v-else class="divide-y divide-line">
        <li v-for="p in properties" :key="p.site_url" class="flex items-center justify-between gap-3 py-2 text-sm">
          <span class="truncate">{{ p.site_url }} <span class="text-xs text-ink-muted">({{ p.permission }})</span></span>
          <AppButton size="sm" :disabled="p.permission === 'siteUnverifiedUser'" :loading="busy === 'choose'" @click="choose(p.site_url)">{{ t('an.connections.use') }}</AppButton>
        </li>
      </ul>
    </AppModal>
    <ConfirmDialog
      :open="!!disconnectSite"
      :title="t('an.connections.disconnect')"
      :message="t('an.connections.disconnectConfirm')"
      danger
      :loading="busy === 'disconnect'"
      @close="disconnectSite = null"
      @confirm="disconnect"
    />
  </div>
</template>
