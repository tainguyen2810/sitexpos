<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/shared/api/client'
import { siteApi, siteStatusTone, waitForJob, type PairingCode, type SiteJob } from '@/shared/api/sites'
import { useAsync } from '@/shared/composables/useAsync'
import { useCursorList } from '@/shared/composables/useCursorList'
import { useToast } from '@/shared/composables/useToast'
import { formatDateTime } from '@/shared/utils/format'
import AppCard from '@/shared/ui/AppCard.vue'
import AppButton from '@/shared/ui/AppButton.vue'
import AppBadge from '@/shared/ui/AppBadge.vue'
import AppModal from '@/shared/ui/AppModal.vue'
import AppSelect from '@/shared/ui/AppSelect.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import LoadingState from '@/shared/ui/LoadingState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'
import PairingCodePanel from '../components/PairingCodePanel.vue'
import { useWorkspaceStore } from '../stores/workspace'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const ws = useWorkspaceStore()
const id = ws.current!.id
const siteId = String(route.params.siteId)

const readOnly = computed(() => ws.current?.impersonating === true)
const canOperate = computed(() => ws.can('content.edit') && !readOnly.value)
const canManage = computed(() => ws.can('sites.manage') && !readOnly.value)

const site = useAsync(() => siteApi.get(id, siteId))
const paired = computed(() => site.data.value?.status === 'connected' || site.data.value?.status === 'degraded')

function errorMessage(e: unknown): string {
  return e instanceof ApiError && e.message ? e.message : t('errors.generic')
}

// ---- background jobs (health check, sync, test publish) ----
const running = ref<SiteJob | null>(null)
async function runJob(job: SiteJob) {
  running.value = job
  try {
    const started = await siteApi.queue(id, siteId, job)
    const run = await waitForJob(() => siteApi.jobRun(id, started.id))
    if (run.status === 'succeeded') toast.success(t(`sites.jobs.${job}.done`))
    else if (run.status === 'failed') toast.error(run.error?.message || t(`sites.jobs.${job}.failed`))
    else toast.success(t('sites.jobs.stillRunning'))
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    running.value = null
    await site.run()
    reloadTab()
  }
}

// ---- keys, pairing, disconnect, delete ----
const busy = ref<string | null>(null)
async function rotate() {
  busy.value = 'rotate'
  try {
    await siteApi.rotateKeys(id, siteId)
    toast.success(t('sites.rotated'))
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = null
    await site.run()
  }
}

const pairing = ref<PairingCode | null>(null)
async function newCode() {
  busy.value = 'code'
  try {
    pairing.value = await siteApi.pairingCode(id, siteId)
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = null
  }
}
async function closePairing() {
  pairing.value = null
  await site.run()
}

const confirmRevoke = ref(false)
async function revoke() {
  busy.value = 'revoke'
  try {
    const res = await siteApi.revoke(id, siteId)
    toast.success(res.meta.plugin_notified ? t('sites.revoked') : t('sites.revokedOffline'))
    confirmRevoke.value = false
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = null
    await site.run()
  }
}

const confirmDelete = ref(false)
async function remove() {
  busy.value = 'delete'
  try {
    await siteApi.remove(id, siteId)
    toast.success(t('sites.deleted'))
    await router.replace({ name: 'sites', params: { workspaceId: id } })
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = null
    confirmDelete.value = false
  }
}

// ---- tabs ----
type Tab = 'content' | 'taxonomies' | 'logs' | 'health'
const tab = ref<Tab>('content')
const tabs: Tab[] = ['content', 'taxonomies', 'logs', 'health']

const contentType = ref('')
const content = useCursorList((cursor) => siteApi.content(id, siteId, { cursor, 'filter[post_type]': contentType.value || undefined }))
const taxonomies = useAsync(() => siteApi.taxonomies(id, siteId), { immediate: false })
const logLevel = ref('')
const logs = useCursorList((cursor) => siteApi.logs(id, siteId, { cursor, 'filter[level]': logLevel.value || undefined }))
const health = useAsync(() => siteApi.healthChecks(id, siteId), { immediate: false })

function reloadTab() {
  if (tab.value === 'content') void content.reload()
  if (tab.value === 'taxonomies') void taxonomies.run()
  if (tab.value === 'logs') void logs.reload()
  if (tab.value === 'health') void health.run()
}
watch(tab, reloadTab, { immediate: true })
watch(contentType, () => void content.reload())
watch(logLevel, () => void logs.reload())

const contentTypeOptions = computed(() => [
  { value: '', label: t('sites.content.all') },
  { value: 'post', label: t('sites.content.types.post') },
  { value: 'page', label: t('sites.content.types.page') },
  ...(site.data.value?.features.woocommerce ? [{ value: 'product', label: t('sites.content.types.product') }] : []),
])
const levelOptions = computed(() => [
  { value: '', label: t('sites.logs.all') },
  { value: 'error', label: t('sites.logs.levels.error') },
  { value: 'warning', label: t('sites.logs.levels.warning') },
  { value: 'info', label: t('sites.logs.levels.info') },
])
const groupedTerms = computed(() => {
  const groups: Record<string, { name: string; count: number; id: string }[]> = {}
  for (const term of taxonomies.data.value ?? []) (groups[term.taxonomy] ??= []).push(term)
  return groups
})
const featureList = computed(() => {
  const f = site.data.value?.features ?? {}
  return (['woocommerce', 'yoast', 'rank_math', 'pretty_permalinks', 'https'] as const).map((k) => ({ key: k, on: f[k] === true }))
})
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'sites', params: { workspaceId: id } }" class="text-sm text-brand-600 hover:underline">← {{ $t('sites.back') }}</RouterLink>

    <LoadingState v-if="site.loading.value && !site.data.value" :rows="3" />
    <ErrorState v-else-if="site.error.value" :error="site.error.value" @retry="site.run" />
    <template v-else-if="site.data.value">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold">
            {{ site.data.value.name }}
            <AppBadge :tone="siteStatusTone(site.data.value.status)" data-testid="site-status">{{ $t(`sites.status.${site.data.value.status}`) }}</AppBadge>
          </h1>
          <a :href="site.data.value.url" target="_blank" rel="noopener noreferrer" class="text-sm text-ink-muted hover:underline">{{ site.data.value.url }}</a>
        </div>
        <div class="flex flex-wrap gap-2">
          <AppButton v-if="paired && canOperate" variant="secondary" :loading="running === 'health-check'" :disabled="running !== null" @click="runJob('health-check')">{{ $t('sites.actions.healthCheck') }}</AppButton>
          <AppButton v-if="paired && canOperate" variant="secondary" :loading="running === 'sync'" :disabled="running !== null" @click="runJob('sync')">{{ $t('sites.actions.sync') }}</AppButton>
          <AppButton v-if="paired && canManage" variant="secondary" :loading="running === 'test-publish'" :disabled="running !== null" @click="runJob('test-publish')">{{ $t('sites.actions.testPublish') }}</AppButton>
          <AppButton v-if="!paired && canManage" :loading="busy === 'code'" @click="newCode">{{ $t('sites.actions.newCode') }}</AppButton>
        </div>
      </div>

      <p v-if="site.data.value.status === 'pending_pairing'" class="rounded-lg bg-brand-50 px-4 py-3 text-sm text-ink">{{ $t('sites.pendingHelp') }}</p>
      <p v-if="site.data.value.status === 'revoked'" class="rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-600">
        {{ $t(site.data.value.revoked_by === 'wordpress' ? 'sites.revokedByWordpress' : 'sites.revokedByCloud', { date: formatDateTime(site.data.value.revoked_at) }) }}
      </p>
      <div v-if="site.data.value.last_error && paired" class="rounded-lg bg-warning-50 px-4 py-3 text-sm text-ink" role="alert">
        <p class="font-medium">{{ $t('sites.lastError') }}</p>
        <p>{{ site.data.value.last_error.message || site.data.value.last_error.code }}</p>
      </div>
      <ul v-if="site.data.value.issues.length" class="space-y-1 rounded-lg bg-warning-50 px-4 py-3 text-sm text-ink">
        <li v-for="issue in site.data.value.issues" :key="issue">⚠ {{ $t(`sites.issues.${issue}`) }}</li>
      </ul>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <AppCard :title="$t('sites.info.title')">
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-ink-muted">WordPress</dt><dd>{{ site.data.value.versions.wordpress ?? '—' }}</dd>
            <dt class="text-ink-muted">PHP</dt><dd>{{ site.data.value.versions.php ?? '—' }}</dd>
            <dt class="text-ink-muted">{{ $t('sites.info.plugin') }}</dt><dd>{{ site.data.value.versions.plugin ?? '—' }}</dd>
            <dt class="text-ink-muted">{{ $t('sites.info.author') }}</dt><dd>{{ site.data.value.publish_author?.display_name ?? '—' }}</dd>
            <dt class="text-ink-muted">{{ $t('sites.info.connectedAt') }}</dt><dd>{{ formatDateTime(site.data.value.connected_at) }}</dd>
            <dt class="text-ink-muted">{{ $t('sites.lastHealth') }}</dt><dd>{{ formatDateTime(site.data.value.last_health_at) }}</dd>
            <dt class="text-ink-muted">{{ $t('sites.info.lastSync') }}</dt><dd>{{ formatDateTime(site.data.value.last_synced_at) }}</dd>
            <dt class="text-ink-muted">{{ $t('sites.info.lastSeen') }}</dt><dd>{{ formatDateTime(site.data.value.last_seen_at) }}</dd>
          </dl>
        </AppCard>
        <AppCard :title="$t('sites.info.features')">
          <ul class="grid grid-cols-2 gap-2 text-sm">
            <li v-for="f in featureList" :key="f.key" class="flex items-center gap-2">
              <span :class="f.on ? 'text-success-600' : 'text-ink-subtle'">{{ f.on ? '●' : '○' }}</span>{{ $t(`sites.features.${f.key}`) }}
            </li>
          </ul>
          <div v-if="canManage" class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
            <AppButton v-if="paired" size="sm" variant="ghost" :loading="busy === 'rotate'" @click="rotate">{{ $t('sites.actions.rotate') }}</AppButton>
            <AppButton v-if="site.data.value.status !== 'revoked' && site.data.value.status !== 'pending_pairing'" size="sm" variant="ghost" @click="confirmRevoke = true">{{ $t('sites.actions.disconnect') }}</AppButton>
            <AppButton size="sm" variant="ghost" @click="confirmDelete = true">{{ $t('sites.actions.delete') }}</AppButton>
          </div>
        </AppCard>
      </div>

      <AppCard :padded="false">
        <div class="flex flex-wrap gap-1 border-b border-line px-3 pt-3" role="tablist">
          <button
            v-for="k in tabs"
            :key="k"
            type="button"
            role="tab"
            :aria-selected="tab === k"
            class="rounded-t-lg px-3 py-2 text-sm"
            :class="tab === k ? 'bg-surface-muted font-medium text-ink' : 'text-ink-muted hover:text-ink'"
            @click="tab = k"
          >
            {{ $t(`sites.tabs.${k}`) }}
          </button>
        </div>

        <div v-if="tab === 'content'">
          <div class="flex justify-end p-3"><AppSelect v-model="contentType" class="w-48" :options="contentTypeOptions" :aria-label="$t('sites.content.filter')" /></div>
          <LoadingState v-if="content.loading.value" class="p-5" />
          <ErrorState v-else-if="content.error.value" :error="content.error.value" class="p-5" @retry="content.reload" />
          <EmptyState v-else-if="!content.items.value.length" icon="file" :title="$t('sites.content.empty')" :description="paired ? $t('sites.content.emptyHint') : undefined" />
          <ul v-else class="divide-y divide-line">
            <li v-for="item in content.items.value" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
              <div class="min-w-0 flex-1">
                <a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="truncate font-medium text-ink hover:underline">{{ item.title || $t('sites.content.untitled') }}</a>
                <span v-else class="truncate font-medium">{{ item.title || $t('sites.content.untitled') }}</span>
                <p class="text-xs text-ink-muted">#{{ item.wp_post_id }} · {{ formatDateTime(item.modified_at) }}<template v-if="item.product?.price"> · {{ item.product.price }} {{ item.product.currency }}</template></p>
              </div>
              <AppBadge v-if="item.is_managed" tone="brand">SiteX</AppBadge>
              <AppBadge>{{ $t(`sites.content.types.${item.post_type}`) }}</AppBadge>
              <AppBadge>{{ item.status }}</AppBadge>
            </li>
          </ul>
          <div v-if="content.next.value" class="p-3 text-center"><AppButton size="sm" variant="ghost" :loading="content.loadingMore.value" @click="content.more">{{ $t('common.loadMore') }}</AppButton></div>
        </div>

        <div v-else-if="tab === 'taxonomies'" class="p-5">
          <LoadingState v-if="taxonomies.loading.value" />
          <ErrorState v-else-if="taxonomies.error.value" :error="taxonomies.error.value" @retry="taxonomies.run" />
          <EmptyState v-else-if="!taxonomies.data.value?.length" icon="list" :title="$t('sites.taxonomies.empty')" />
          <div v-else class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <section v-for="(terms, taxonomy) in groupedTerms" :key="taxonomy">
              <h3 class="mb-2 text-sm font-semibold">{{ $t(`sites.taxonomies.${taxonomy}`) }} ({{ terms.length }})</h3>
              <ul class="flex flex-wrap gap-2">
                <li v-for="term in terms" :key="term.id"><AppBadge>{{ term.name }} · {{ term.count }}</AppBadge></li>
              </ul>
            </section>
          </div>
        </div>

        <div v-else-if="tab === 'logs'">
          <div class="flex justify-end p-3"><AppSelect v-model="logLevel" class="w-48" :options="levelOptions" :aria-label="$t('sites.logs.filter')" /></div>
          <LoadingState v-if="logs.loading.value" class="p-5" />
          <ErrorState v-else-if="logs.error.value" :error="logs.error.value" class="p-5" @retry="logs.reload" />
          <EmptyState v-else-if="!logs.items.value.length" icon="list" :title="$t('sites.logs.empty')" />
          <ul v-else class="divide-y divide-line text-sm">
            <li v-for="log in logs.items.value" :key="log.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
              <AppBadge :tone="log.level === 'error' ? 'danger' : log.level === 'warning' ? 'warning' : 'neutral'">{{ $t(`sites.logs.levels.${log.level}`) }}</AppBadge>
              <span class="font-mono text-xs">{{ log.direction === 'out' ? '→' : '←' }} {{ log.operation }}</span>
              <span v-if="log.http_status" class="text-xs text-ink-muted">HTTP {{ log.http_status }}</span>
              <span v-if="log.latency_ms !== null" class="text-xs text-ink-muted">{{ log.latency_ms }} ms</span>
              <span class="ml-auto text-xs text-ink-muted">{{ formatDateTime(log.created_at) }}</span>
              <p v-if="log.message || log.error_code" class="basis-full text-xs text-ink-muted">{{ log.message || log.error_code }}</p>
            </li>
          </ul>
          <div v-if="logs.next.value" class="p-3 text-center"><AppButton size="sm" variant="ghost" :loading="logs.loadingMore.value" @click="logs.more">{{ $t('common.loadMore') }}</AppButton></div>
        </div>

        <div v-else class="p-5">
          <LoadingState v-if="health.loading.value" />
          <ErrorState v-else-if="health.error.value" :error="health.error.value" @retry="health.run" />
          <EmptyState v-else-if="!health.data.value?.length" icon="monitor" :title="$t('sites.health.empty')" />
          <ul v-else class="divide-y divide-line text-sm">
            <li v-for="h in health.data.value" :key="h.id" class="flex flex-wrap items-center gap-3 py-2">
              <AppBadge :tone="h.ok ? 'success' : 'danger'">{{ h.ok ? $t('sites.health.ok') : $t('sites.health.failed') }}</AppBadge>
              <span class="text-xs text-ink-muted">{{ formatDateTime(h.checked_at) }}</span>
              <span v-if="h.latency_ms !== null" class="text-xs text-ink-muted">{{ h.latency_ms }} ms</span>
              <span v-if="h.error_code" class="text-xs text-danger-600">{{ h.error_code }}</span>
            </li>
          </ul>
        </div>
      </AppCard>
    </template>

    <AppModal :open="pairing !== null" :title="$t('sites.pairing.title')" @close="closePairing">
      <PairingCodePanel v-if="pairing" :code="pairing.pairing_code" :expires-at="pairing.expires_at" :site-url="site.data.value?.url" />
      <template #footer><AppButton @click="closePairing">{{ $t('sites.pairing.done') }}</AppButton></template>
    </AppModal>

    <ConfirmDialog
      :open="confirmRevoke"
      :title="$t('sites.actions.disconnect')"
      :message="$t('sites.disconnectConfirm')"
      danger
      :loading="busy === 'revoke'"
      @close="confirmRevoke = false"
      @confirm="revoke"
    />
    <ConfirmDialog
      :open="confirmDelete"
      :title="$t('sites.actions.delete')"
      :message="$t('sites.deleteConfirm', { name: site.data.value?.name ?? '' })"
      danger
      :loading="busy === 'delete'"
      @close="confirmDelete = false"
      @confirm="remove"
    />
  </div>
</template>
